<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

class TraceStatsCommand extends Command
{
    protected $signature = 'request-tracer:stats
        {--hours=24 : Time window in hours}
        {--type= : Filter by type (incoming or outgoing)}';

    protected $description = 'Display trace statistics for a given time window';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);
        $type = $this->option('type');

        $this->components->twoColumnDetail('<fg=gray>Time Window</>', "Last {$hours} hour(s)");
        $this->components->twoColumnDetail('<fg=gray>Since</>', $cutoff->toDateTimeString());
        $this->newLine();

        if (!$type || 'outgoing' === $type) {
            $outgoingModel = config('kolaybi.request-tracer.outgoing.model', OutgoingRequestTrace::class);
            $this->renderStats('Outgoing', $outgoingModel::where('created_at', '>=', $cutoff));
        }

        if (!$type || 'incoming' === $type) {
            $incomingModel = config('kolaybi.request-tracer.incoming.model', IncomingRequestTrace::class);
            $this->renderStats('Incoming', $incomingModel::where('created_at', '>=', $cutoff));
        }

        return self::SUCCESS;
    }

    private function renderStats(string $label, Builder $query): void
    {
        $total = $query->count();

        if (0 === $total) {
            $this->components->twoColumnDetail("<fg=yellow>{$label}</>", '<fg=gray>No traces</>');
            $this->newLine();

            return;
        }

        $stats = (clone $query)
            ->toBase()
            ->selectRaw(
                'COUNT(*) as total,
                AVG(duration) as avg_duration,
                MIN(duration) as min_duration,
                MAX(duration) as max_duration,
                SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) as server_errors,
                SUM(CASE WHEN status >= 400 AND status < 500 THEN 1 ELSE 0 END) as client_errors,
                SUM(CASE WHEN status >= 200 AND status < 300 THEN 1 ELSE 0 END) as success',
            )
            ->first();

        $errorRate = $total > 0 ? round(($stats->server_errors / $total) * 100, 1) : 0;

        $this->components->twoColumnDetail("<fg=yellow>{$label}</>");
        $this->components->twoColumnDetail('  Total Requests', number_format((int) $stats->total));
        $this->components->twoColumnDetail('  2xx Success', number_format((int) $stats->success));
        $this->components->twoColumnDetail('  4xx Client Errors', number_format((int) $stats->client_errors));
        $this->components->twoColumnDetail('  5xx Server Errors', number_format((int) $stats->server_errors));
        $this->components->twoColumnDetail('  Error Rate (5xx)', "{$errorRate}%");
        $this->components->twoColumnDetail('  Avg Duration', $this->formatMs($stats->avg_duration));
        $this->components->twoColumnDetail('  Min Duration', $this->formatMs($stats->min_duration));
        $this->components->twoColumnDetail('  Max Duration', $this->formatMs($stats->max_duration));

        // Top hosts
        $topHosts = (clone $query)->toBase()
            ->selectRaw('host, COUNT(*) as cnt')
            ->groupBy('host')
            ->orderByDesc('cnt')
            ->limit(5)
            ->get();

        if ($topHosts->isNotEmpty()) {
            $this->newLine();
            $this->components->twoColumnDetail('  <fg=gray>Top Hosts</>');

            foreach ($topHosts as $row) {
                $this->components->twoColumnDetail("    {$row->host}", number_format($row->cnt));
            }
        }

        // Top channels (outgoing only)
        if ('Outgoing' === $label) {
            $topChannels = (clone $query)->toBase()
                ->selectRaw('channel, COUNT(*) as cnt')
                ->whereNotNull('channel')
                ->groupBy('channel')
                ->orderByDesc('cnt')
                ->limit(5)
                ->get();

            if ($topChannels->isNotEmpty()) {
                $this->newLine();
                $this->components->twoColumnDetail('  <fg=gray>Top Channels</>');

                foreach ($topChannels as $row) {
                    $this->components->twoColumnDetail("    {$row->channel}", number_format($row->cnt));
                }
            }
        }

        $this->newLine();
    }

    private function formatMs(mixed $value): string
    {
        if (null === $value) {
            return '—';
        }

        return number_format((float) $value, 0) . 'ms';
    }
}
