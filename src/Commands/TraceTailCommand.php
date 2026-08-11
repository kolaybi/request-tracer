<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use KolayBi\RequestTracer\Commands\Concerns\BuildsEndpoint;
use KolayBi\RequestTracer\Commands\Concerns\ColorsStatus;
use KolayBi\RequestTracer\Enums\TraceDirection;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;
use Symfony\Component\Console\Output\OutputInterface;

class TraceTailCommand extends Command
{
    use BuildsEndpoint;
    use ColorsStatus;

    private const int MESSAGE_DISPLAY_LIMIT = 80;

    private const int METHOD_PAD_WIDTH = 7;

    private const int MIN_INTERVAL_SECONDS = 1;

    private const int MAX_INTERVAL_SECONDS = 60;

    private const int LOOKBACK_MULTIPLIER = 2;

    private const int SEEN_IDS_PRUNE_THRESHOLD = 1000;

    private const int SEEN_IDS_PRUNE_KEEP = 500;

    protected $signature = 'request-tracer:tail
        {--type= : Filter by type (incoming or outgoing)}
        {--host= : Filter by host (supports * wildcards)}
        {--status= : Filter by status code (e.g. 500, 4xx, 5xx)}
        {--method= : Filter by HTTP method (comma-separated for multiple, e.g. GET,POST)}
        {--channel= : Filter by channel}
        {--interval=2 : Poll interval in seconds}
        {--max-polls= : Stop after N polls (for testing)}';

    protected $description = 'Live-tail new traces as they arrive';

    public function handle(): int
    {
        $direction = TraceDirection::tryFromInput($type = $this->option('type'));

        if ($type && !$direction) {
            $this->error("Invalid type: {$type}. Use incoming or outgoing.");

            return self::FAILURE;
        }

        $interval = max(self::MIN_INTERVAL_SECONDS, min(self::MAX_INTERVAL_SECONDS, (int) $this->option('interval')));
        $maxPolls = $this->option('max-polls') ? (int) $this->option('max-polls') : null;

        $outgoingModel = config('kolaybi.request-tracer.outgoing.model', OutgoingRequestTrace::class);
        $incomingModel = config('kolaybi.request-tracer.incoming.model', IncomingRequestTrace::class);

        // Pre-populate seen IDs within the lookback window so pre-existing traces are never shown
        $seenIds = [];
        $cursor = Carbon::now();
        $lookback = $cursor->copy()->subSeconds($interval * self::LOOKBACK_MULTIPLIER);

        if (!$direction || TraceDirection::Outgoing === $direction) {
            foreach ($outgoingModel::where('created_at', '>=', $lookback)->pluck('id') as $id) {
                $seenIds[$id] = true;
            }
        }

        if (!$direction || TraceDirection::Incoming === $direction) {
            foreach ($incomingModel::where('created_at', '>=', $lookback)->pluck('id') as $id) {
                $seenIds[$id] = true;
            }
        }

        $this->info("Tailing traces every {$interval}s... (Ctrl+C to stop)");

        $polls = 0;

        while (true) {
            // Look back beyond the interval to catch late inserts from concurrent processes
            $since = $cursor->copy()->subSeconds($interval * self::LOOKBACK_MULTIPLIER);

            if (!$direction || TraceDirection::Outgoing === $direction) {
                $traces = $this
                    ->applyFilters($outgoingModel::where('created_at', '>=', $since))
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();

                /** @var OutgoingRequestTrace $trace */
                foreach ($traces as $trace) {
                    if (!isset($seenIds[$trace->id])) {
                        $this->renderLine($trace, TraceDirection::Outgoing);
                        $seenIds[$trace->id] = true;
                    }
                }
            }

            if (!$direction || TraceDirection::Incoming === $direction) {
                $traces = $this
                    ->applyFilters($incomingModel::where('created_at', '>=', $since))
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();

                /** @var IncomingRequestTrace $trace */
                foreach ($traces as $trace) {
                    if (!isset($seenIds[$trace->id])) {
                        $this->renderLine($trace, TraceDirection::Incoming);
                        $seenIds[$trace->id] = true;
                    }
                }
            }

            $cursor = Carbon::now();

            // Prune seen set to prevent memory growth
            if (count($seenIds) > self::SEEN_IDS_PRUNE_THRESHOLD) {
                $seenIds = array_slice($seenIds, -self::SEEN_IDS_PRUNE_KEEP, null, true);
            }

            $polls++;

            if (null !== $maxPolls && $polls >= $maxPolls) {
                break;
            }

            sleep($interval);
        }

        return self::SUCCESS;
    }

    private function applyFilters(Builder $query): Builder
    {
        if ($host = $this->option('host')) {
            $query->whereLike('host', str_replace('*', '%', $host));
        }

        if ($status = $this->option('status')) {
            $status = strtolower($status);

            if (Str::endsWith($status, 'xx')) {
                $base = (int) Str::before($status, 'xx');
                $query->whereBetween('status', [$base * 100, ($base * 100) + 99]);
            } else {
                $query->where('status', (int) $status);
            }
        }

        if ($method = $this->option('method')) {
            $methods = array_map(strtoupper(...), array_filter(array_map(trim(...), explode(',', $method))));

            $query->whereIn('method', $methods);
        }

        if ($channel = $this->option('channel')) {
            $query->whereRaw('LOWER(channel) = ?', [strtolower($channel)]);
        }

        return $query;
    }

    private function renderLine(IncomingRequestTrace|OutgoingRequestTrace $trace, TraceDirection $direction): void
    {
        $verbosity = $this->verbosity();

        $time = Carbon::parse($trace->created_at ?? now())->format('H:i:s');
        $status = $this->colorStatus($trace->status);
        $method = str_pad($trace->method ?? '—', self::METHOD_PAD_WIDTH);
        $endpoint = $this->buildEndpoint($trace, $direction);

        if ($verbosity < 1) {
            $endpoint = Str::limit($endpoint, self::ENDPOINT_DISPLAY_LIMIT);
        }

        $duration = null !== $trace->duration ? "{$trace->duration}ms" : '—';
        $channel = $trace->channel ? " [{$trace->channel}]" : '';
        $extras = $this->buildExtras($trace, $direction, $verbosity);

        $this->line("[{$time}] {$status} {$method} {$endpoint} {$duration} <fg=gray>[{$direction->value}]{$channel}{$extras}</>");
    }

    private function buildExtras(IncomingRequestTrace|OutgoingRequestTrace $trace, TraceDirection $direction, int $verbosity): string
    {
        if ($verbosity < 1) {
            return '';
        }

        $parts = ["id={$trace->id}"];

        if ($verbosity >= 2) {
            $tenantColumn = config('kolaybi.request-tracer.tenant_column', 'tenant_id');

            if ($trace->trace_id) {
                $parts[] = "trace={$trace->trace_id}";
            }

            if (null !== $trace->{$tenantColumn}) {
                $parts[] = "tenant={$trace->{$tenantColumn}}";
            }

            if (null !== $trace->user_id) {
                $parts[] = "user={$trace->user_id}";
            }

            if ($trace->client_ip) {
                $parts[] = "ip={$trace->client_ip}";
            }
        }

        if ($verbosity >= 3) {
            if (null !== $trace->request_size) {
                $parts[] = "req={$trace->request_size}B";
            }

            if (null !== $trace->response_size) {
                $parts[] = "res={$trace->response_size}B";
            }

            if ($trace->server_identifier) {
                $parts[] = "server={$trace->server_identifier}";
            }

            if (TraceDirection::Outgoing === $direction && $trace->message) {
                $parts[] = 'msg=' . Str::limit($trace->message, self::MESSAGE_DISPLAY_LIMIT);
            }
        }

        return ' ' . implode(' ', $parts);
    }

    private function verbosity(): int
    {
        return match ($this->output->getVerbosity()) {
            OutputInterface::VERBOSITY_VERBOSE      => 1,
            OutputInterface::VERBOSITY_VERY_VERBOSE => 2,
            OutputInterface::VERBOSITY_DEBUG        => 3,
            default                                 => 0,
        };
    }
}
