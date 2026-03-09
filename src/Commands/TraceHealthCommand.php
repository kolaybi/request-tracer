<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use KolayBi\RequestTracer\Support\CircuitBreaker;

class TraceHealthCommand extends Command
{
    protected $signature = 'request-tracer:health';

    protected $description = 'Display circuit breaker status for monitored endpoints';

    public function handle(CircuitBreaker $cb): int
    {
        if (!$cb->isEnabled()) {
            $this->warn('Circuit breaker is disabled. Set REQUEST_TRACER_CB_ENABLED=true to enable.');

            return self::SUCCESS;
        }

        $endpoints = $cb->allEndpoints();

        if ([] === $endpoints) {
            $this->info('No endpoints monitored yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Host', 'Channel', 'Failures', 'Status', 'Tripped At'],
            array_map(fn(array $ep) => [
                $ep['host'],
                $ep['channel'] ?? '—',
                $ep['failures'],
                $this->formatStatus($ep),
                $ep['tripped_at'] ?? '—',
            ], $endpoints),
        );

        return self::SUCCESS;
    }

    private function formatStatus(array $ep): string
    {
        if ($ep['tripped']) {
            return '<fg=red>DEGRADED</>';
        }

        if ($ep['recovering']) {
            return '<fg=yellow>RECOVERING</>';
        }

        return '<fg=green>HEALTHY</>';
    }
}
