<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use KolayBi\RequestTracer\Support\CircuitBreaker;

class TraceHealthCommand extends Command
{
    protected $signature = 'request-tracer:health';

    protected $description = 'Display circuit breaker status for monitored endpoints';

    public function handle(CircuitBreaker $circuitBreaker): int
    {
        if (!$circuitBreaker->isEnabled()) {
            $this->warn('Circuit breaker is disabled. Set REQUEST_TRACER_CB_ENABLED=true to enable.');

            return self::SUCCESS;
        }

        $endpoints = $circuitBreaker->allEndpoints();

        if ([] === $endpoints) {
            $this->info('No endpoints monitored yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Type', 'Host', 'Channel', 'Failures', 'Status', 'Tripped At'],
            array_map(fn(array $endpoint) => [
                strtoupper($endpoint['direction'] ?? 'outgoing'),
                $endpoint['host'],
                $endpoint['channel'] ?? '—',
                $endpoint['failures'],
                $this->formatStatus($endpoint),
                $endpoint['tripped_at'] ?? '—',
            ], $endpoints),
        );

        return self::SUCCESS;
    }

    private function formatStatus(array $endpoint): string
    {
        if ($endpoint['tripped']) {
            return '<fg=red>DEGRADED</>';
        }

        if ($endpoint['recovering']) {
            return '<fg=yellow>RECOVERING</>';
        }

        return '<fg=green>HEALTHY</>';
    }
}
