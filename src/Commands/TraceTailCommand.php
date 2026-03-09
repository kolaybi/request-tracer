<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use KolayBi\RequestTracer\Commands\Concerns\BuildsEndpoint;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

class TraceTailCommand extends Command
{
    use BuildsEndpoint;

    protected $signature = 'request-tracer:tail
        {--type= : Filter by type (incoming or outgoing)}
        {--host= : Filter by host (supports * wildcards)}
        {--status= : Filter by status code (e.g. 500, 4xx, 5xx)}
        {--channel= : Filter by channel (outgoing only)}
        {--interval=2 : Poll interval in seconds}
        {--max-polls= : Stop after N polls (for testing)}';

    protected $description = 'Live-tail new traces as they arrive';

    public function handle(): int
    {
        $type = $this->option('type');
        $interval = max(1, min(60, (int) $this->option('interval')));
        $maxPolls = $this->option('max-polls') ? (int) $this->option('max-polls') : null;

        $outgoingModel = config('kolaybi.request-tracer.outgoing.model', OutgoingRequestTrace::class);
        $incomingModel = config('kolaybi.request-tracer.incoming.model', IncomingRequestTrace::class);

        $lastOutgoingId = (!$type || 'outgoing' === $type)
            ? ($outgoingModel::orderByDesc('id')->value('id') ?? '')
            : '';
        $lastIncomingId = (!$type || 'incoming' === $type)
            ? ($incomingModel::orderByDesc('id')->value('id') ?? '')
            : '';

        $this->info("Tailing traces every {$interval}s... (Ctrl+C to stop)");

        $polls = 0;

        while (true) {
            if (!$type || 'outgoing' === $type) {
                $traces = $this->applyFilters($outgoingModel::where('id', '>', $lastOutgoingId), 'outgoing')
                    ->orderBy('id')
                    ->get();

                /** @var OutgoingRequestTrace $trace */
                foreach ($traces as $trace) {
                    $this->renderLine($trace, 'OUTGOING');
                    $lastOutgoingId = $trace->id;
                }
            }

            if (!$type || 'incoming' === $type) {
                $traces = $this->applyFilters($incomingModel::where('id', '>', $lastIncomingId), 'incoming')
                    ->orderBy('id')
                    ->get();

                /** @var IncomingRequestTrace $trace */
                foreach ($traces as $trace) {
                    $this->renderLine($trace, 'INCOMING');
                    $lastIncomingId = $trace->id;
                }
            }

            $polls++;

            if (null !== $maxPolls && $polls >= $maxPolls) {
                break;
            }

            sleep($interval);
        }

        return self::SUCCESS;
    }

    private function applyFilters(Builder $query, string $direction): Builder
    {
        if ($host = $this->option('host')) {
            $query->whereLike('host', str_replace('*', '%', $host));
        }

        if ($status = $this->option('status')) {
            if (Str::endsWith($status, 'xx')) {
                $base = (int) Str::before($status, 'xx');
                $query->whereBetween('status', [$base * 100, ($base * 100) + 99]);
            } else {
                $query->where('status', (int) $status);
            }
        }

        if ($channel = $this->option('channel')) {
            if ('outgoing' === $direction) {
                $query->where('channel', $channel);
            }
        }

        return $query;
    }

    private function renderLine(IncomingRequestTrace|OutgoingRequestTrace $trace, string $type): void
    {
        $time = ($trace->created_at ?? now())->format('H:i:s');
        $status = $this->colorStatus($trace->status);
        $method = str_pad($trace->method ?? '—', 7);
        $endpoint = Str::limit($this->buildEndpoint($trace, $type), 55);
        $duration = null !== $trace->duration ? "{$trace->duration}ms" : '—';
        $channel = 'OUTGOING' === $type && $trace->channel ? " [{$trace->channel}]" : '';

        $this->line("[{$time}] {$status} {$method} {$endpoint} {$duration} <fg=gray>[{$type}]{$channel}</>");
    }

    private function colorStatus(?int $status): string
    {
        if (null === $status) {
            return '<fg=white>—</>';
        }

        if ($status >= 500) {
            return "<fg=red>{$status}</>";
        }

        if ($status >= 400) {
            return "<fg=yellow>{$status}</>";
        }

        if ($status >= 200 && $status < 300) {
            return "<fg=green>{$status}</>";
        }

        return "<fg=white>{$status}</>";
    }
}
