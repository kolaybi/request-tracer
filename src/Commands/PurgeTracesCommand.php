<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

class PurgeTracesCommand extends Command
{
    protected $signature = 'request-tracer:purge
        {--days= : Override retention days from config}
        {--chunk=5000 : Number of rows to delete per batch}';

    protected $description = 'Purge old request traces';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('request-tracer.retention_days', 0));

        if ($days <= 0) {
            $this->warn('Retention not configured. Use --days=N or set REQUEST_TRACER_RETENTION_DAYS.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $chunk = (int) $this->option('chunk');

        $outgoingModel = config('request-tracer.outgoing.model', OutgoingRequestTrace::class);
        $outgoingDeleted = $this->purgeInChunks($outgoingModel, $cutoff, $chunk);
        $this->info("Purged {$outgoingDeleted} outgoing traces older than {$days} days.");

        $incomingModel = config('request-tracer.incoming.model', IncomingRequestTrace::class);
        $incomingDeleted = $this->purgeInChunks($incomingModel, $cutoff, $chunk);
        $this->info("Purged {$incomingDeleted} incoming traces older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * @param class-string<IncomingRequestTrace|OutgoingRequestTrace> $modelClass
     */
    private function purgeInChunks(string $modelClass, mixed $cutoff, int $chunk): int
    {
        $total = 0;

        do {
            $deleted = $modelClass::where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $total += $deleted;
        } while ($deleted >= $chunk);

        return $total;
    }
}
