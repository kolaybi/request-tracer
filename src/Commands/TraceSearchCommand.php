<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use KolayBi\RequestTracer\Commands\Concerns\BuildsEndpoint;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

class TraceSearchCommand extends Command
{
    use BuildsEndpoint;

    protected $signature = 'request-tracer:search
        {--host= : Filter by host (supports * wildcards)}
        {--status= : Filter by status code (e.g. 500, 4xx, 5xx)}
        {--method= : Filter by HTTP method}
        {--path= : Filter by path (supports * wildcards)}
        {--channel= : Filter by channel}
        {--from= : Filter traces after this date/time}
        {--to= : Filter traces before this date/time}
        {--min-duration= : Minimum duration in ms}
        {--max-duration= : Maximum duration in ms}
        {--type= : Filter by type (incoming or outgoing)}
        {--limit=50 : Maximum results to show}';

    protected $description = 'Search and filter request traces';

    public function handle(): int
    {
        $type = $this->option('type');
        $limit = max(1, (int) $this->option('limit'));
        $results = collect();

        if (!$type || 'outgoing' === $type) {
            $outgoingModel = config('kolaybi.request-tracer.outgoing.model', OutgoingRequestTrace::class);
            $results = $results->merge(
                $this->buildQuery($outgoingModel::query())
                    ->latest('created_at')
                    ->limit($limit)
                    ->get()
                    ->map(fn($trace) => ['trace' => $trace, 'type' => 'OUTGOING']),
            );
        }

        if (!$type || 'incoming' === $type) {
            $incomingModel = config('kolaybi.request-tracer.incoming.model', IncomingRequestTrace::class);
            $results = $results->merge(
                $this->buildQuery($incomingModel::query())
                    ->latest('created_at')
                    ->limit($limit)
                    ->get()
                    ->map(fn($trace) => ['trace' => $trace, 'type' => 'INCOMING']),
            );
        }

        $results = $results->sortByDesc(fn($item) => $item['trace']->created_at)->take($limit)->values();

        if ($results->isEmpty()) {
            $this->warn('No traces found matching the given criteria.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=gray>Results</>', (string) $results->count());
        $this->newLine();
        $this->renderTable($results);

        return self::SUCCESS;
    }

    private function buildQuery(Builder $query): Builder
    {
        if ($host = $this->option('host')) {
            $query->whereLike('host', $this->wildcardToLike($host));
        }

        if ($path = $this->option('path')) {
            $query->whereLike('path', $this->wildcardToLike($path));
        }

        if ($method = $this->option('method')) {
            $query->where('method', strtoupper($method));
        }

        if ($status = $this->option('status')) {
            $this->applyStatusFilter($query, $status);
        }

        if ($channel = $this->option('channel')) {
            $query->where('channel', $channel);
        }

        if ($from = $this->option('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $this->option('to')) {
            $query->where('created_at', '<=', $to);
        }

        if (null !== $this->option('min-duration')) {
            $query->where('duration', '>=', (int) $this->option('min-duration'));
        }

        if (null !== $this->option('max-duration')) {
            $query->where('duration', '<=', (int) $this->option('max-duration'));
        }

        return $query;
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        if (Str::endsWith($status, 'xx')) {
            $base = (int) Str::before($status, 'xx');
            $query->whereBetween('status', [$base * 100, ($base * 100) + 99]);

            return;
        }

        $query->where('status', (int) $status);
    }

    private function wildcardToLike(string $pattern): string
    {
        return str_replace('*', '%', $pattern);
    }

    private function renderTable(Collection $results): void
    {
        $this->table(
            ['ID', 'Type', 'Method', 'Endpoint', 'Status', 'Duration', 'Channel', 'Created'],
            $results->map(fn($item) => $this->formatRow($item['trace'], $item['type'])),
        );
    }

    private function formatRow(IncomingRequestTrace|OutgoingRequestTrace $trace, string $type): array
    {
        return [
            $trace->id ?? '—',
            $type,
            $trace->method ?? '—',
            Str::limit($this->buildEndpoint($trace, $type), 60),
            $trace->status ?? '—',
            null !== $trace->duration ? "{$trace->duration}ms" : '—',
            $trace->channel ?? '—',
            $trace->created_at ?? '—',
        ];
    }
}
