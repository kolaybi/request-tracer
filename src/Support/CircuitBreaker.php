<?php

namespace KolayBi\RequestTracer\Support;

use Illuminate\Contracts\Cache\Repository;
use KolayBi\RequestTracer\Events\CircuitBreakerTripped;

class CircuitBreaker
{
    private Repository $cache;

    public function __construct(Repository $cache)
    {
        $this->cache = $cache;
    }

    public function isEnabled(): bool
    {
        return (bool) config('kolaybi.request-tracer.circuit_breaker.enabled', false);
    }

    public function recordFailure(string $host, ?string $channel, string $direction = 'outgoing'): void
    {
        $key = $this->failureKey($host, $channel, $direction);
        $ttl = $this->failureTtl();

        // Initialize if absent, then increment
        $this->cache->add($key, 0, $ttl);
        $count = $this->cache->increment($key);

        $this->registerEndpoint($host, $channel, $direction);

        $threshold = $this->failureThreshold();
        $trippedKey = $this->trippedKey($host, $channel, $direction);

        // Only dispatch event when we FIRST reach the threshold
        if ($count === $threshold && !$this->cache->has($trippedKey)) {
            $this->cache->put($trippedKey, now()->toIso8601String(), $this->recoveryAfter());
            CircuitBreakerTripped::dispatch($host, $channel, $count, $direction);
        }
    }

    public function recordSuccess(string $host, ?string $channel, string $direction = 'outgoing'): void
    {
        $this->cache->put($this->failureKey($host, $channel, $direction), 0, $this->failureTtl());
        $this->registerEndpoint($host, $channel, $direction);
    }

    public function getStatus(string $host, ?string $channel, string $direction = 'outgoing'): array
    {
        $failures = (int) $this->cache->get($this->failureKey($host, $channel, $direction), 0);
        $trippedAt = $this->cache->get($this->trippedKey($host, $channel, $direction));

        return [
            'direction'  => $direction,
            'host'       => $host,
            'channel'    => $channel,
            'failures'   => $failures,
            'tripped'    => null !== $trippedAt,
            'tripped_at' => $trippedAt,
            'recovering' => null === $trippedAt && $failures > 0,
            'healthy'    => null === $trippedAt && 0 === $failures,
        ];
    }

    public function allEndpoints(): array
    {
        $registry = $this->cache->get($this->registryKey(), []);
        $statuses = [];

        foreach ($registry as $entry) {
            $statuses[] = $this->getStatus($entry['host'], $entry['channel'], $entry['direction'] ?? 'outgoing');
        }

        // Sort: tripped first, then by failures desc
        usort($statuses, function (array $a, array $b) {
            if ($a['tripped'] !== $b['tripped']) {
                return $b['tripped'] <=> $a['tripped'];
            }

            return $b['failures'] <=> $a['failures'];
        });

        return $statuses;
    }

    private function failureKey(string $host, ?string $channel, string $direction): string
    {
        return 'request-tracer:cb:' . $direction . ':' . $this->sanitize($host) . ':' . $this->sanitize($channel ?? '_');
    }

    private function trippedKey(string $host, ?string $channel, string $direction): string
    {
        return 'request-tracer:cb-tripped:' . $direction . ':' . $this->sanitize($host) . ':' . $this->sanitize($channel ?? '_');
    }

    private function registryKey(): string
    {
        return 'request-tracer:cb-registry';
    }

    private function sanitize(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9\-\.]/i', '-', $value));
    }

    private function registerEndpoint(string $host, ?string $channel, string $direction): void
    {
        $registry = $this->cache->get($this->registryKey(), []);
        $key = $direction . ':' . $this->sanitize($host) . ':' . $this->sanitize($channel ?? '_');

        if (!isset($registry[$key])) {
            $registry[$key] = ['host' => $host, 'channel' => $channel, 'direction' => $direction];
            $this->cache->forever($this->registryKey(), $registry);
        }
    }

    private function failureThreshold(): int
    {
        return max(1, (int) config('kolaybi.request-tracer.circuit_breaker.failure_threshold', 5));
    }

    private function recoveryAfter(): int
    {
        return max(1, (int) config('kolaybi.request-tracer.circuit_breaker.recovery_after', 60));
    }

    private function failureTtl(): int
    {
        return $this->recoveryAfter() * 2;
    }
}
