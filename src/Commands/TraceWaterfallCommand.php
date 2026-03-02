<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

class TraceWaterfallCommand extends Command
{
    protected $signature = 'request-tracer:waterfall {trace_id}';

    protected $description = 'Display a chronological waterfall of all traces for a given trace ID';

    public function handle(): int
    {
        $traceId = $this->argument('trace_id');

        $incomingModel = config('kolaybi.request-tracer.incoming.model', IncomingRequestTrace::class);
        $outgoingModel = config('kolaybi.request-tracer.outgoing.model', OutgoingRequestTrace::class);

        $incomingTraces = $incomingModel::where('trace_id', $traceId)->orderBy('start')->get();
        $outgoingTraces = $outgoingModel::where('trace_id', $traceId)->orderBy('start')->get();

        $allTraces = $incomingTraces
            ->map(fn($trace) => ['trace' => $trace, 'type' => 'INCOMING'])
            ->merge($outgoingTraces->map(fn($trace) => ['trace' => $trace, 'type' => 'OUTGOING']))
            ->sortBy(fn($item) => $item['trace']->start ?? '')
            ->values();

        if ($allTraces->isEmpty()) {
            $this->warn("No traces found for trace_id: {$traceId}");

            return self::FAILURE;
        }

        $this->renderSummary($traceId, $allTraces);
        $this->newLine();
        $this->renderTable($allTraces);

        return self::SUCCESS;
    }

    private function renderSummary(string $traceId, Collection $allTraces): void
    {
        $tenantColumn = config('kolaybi.request-tracer.tenant_column', 'tenant_id');

        $first = $allTraces->first()['trace'];
        $firstStart = $allTraces->min(fn($item) => $item['trace']->start);
        $lastEnd = $allTraces->max(fn($item) => $item['trace']->end);

        $totalDuration = '—';
        if ($firstStart && $lastEnd) {
            $ms = (int) Carbon::parse($firstStart)->diffInMilliseconds(Carbon::parse($lastEnd));
            $totalDuration = "{$ms}ms";
        }

        $this->components->twoColumnDetail('<fg=gray>Trace ID</>', $traceId);
        $this->components->twoColumnDetail('<fg=gray>Tenant ID</>', (string) ($first->{$tenantColumn} ?? '—'));
        $this->components->twoColumnDetail('<fg=gray>User ID</>', (string) ($first->user_id ?? '—'));
        $this->components->twoColumnDetail('<fg=gray>Client IP</>', $first->client_ip ?? '—');
        $this->components->twoColumnDetail('<fg=gray>First Start</>', $firstStart ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Last End</>', $lastEnd ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Total Duration</>', $totalDuration);
    }

    private function renderTable(Collection $allTraces): void
    {
        $this->table(
            ['#', 'ID', 'Type', 'Method', 'Endpoint', 'Status', 'Duration', 'Channel', 'Server', 'Start'],
            $allTraces->map(fn($item, $index) => $this->formatRow($item['trace'], $item['type'], $index + 1)),
        );
    }

    private function formatRow(IncomingRequestTrace|OutgoingRequestTrace $trace, string $type, int $index): array
    {
        return [
            $index,
            $trace->id ?? '—',
            $type,
            $trace->method ?? '—',
            $this->buildEndpoint($trace, $type),
            $trace->status ?? '—',
            null !== $trace->duration ? "{$trace->duration}ms" : '—',
            'OUTGOING' === $type ? ($trace->channel ?? '—') : '—',
            $trace->server_identifier ?? '—',
            $trace->start ?? '—',
        ];
    }

    private function buildEndpoint(IncomingRequestTrace|OutgoingRequestTrace $trace, string $type): string
    {
        $protocol = property_exists($trace, 'protocol') || isset($trace->protocol) ? $trace->protocol : null;
        $host = $trace->host;
        $path = $trace->path;
        $query = $trace->query;
        $route = 'INCOMING' === $type ? $trace->route : null;

        $url = '';

        if ($protocol && $host) {
            $url = "{$protocol}://{$host}";
        } elseif ($host) {
            $url = $host;
        }

        if ($path) {
            $url = rtrim($url, '/') . '/' . ltrim($path, '/');
        }

        if ($query) {
            $url .= "?{$query}";
        }

        if ($route) {
            $url .= " ({$route})";
        }

        return $url ?: '—';
    }
}
