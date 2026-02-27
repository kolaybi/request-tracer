<?php

namespace KolayBi\RequestTracer\Support;

use Carbon\CarbonImmutable;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;

class TraceHelper
{
    public static function dispatchTrace(array $attributes, string $modelClass): void
    {
        $attributes['duration'] = self::calculateDuration($attributes['start'] ?? null, $attributes['end'] ?? null);

        StoreTraceJob::dispatch($attributes, $modelClass)
            ->onConnection(config('request-tracer.queue_connection'))
            ->onQueue(config('request-tracer.queue'));
    }

    public static function calculateDuration(?string $start, ?string $end): ?int
    {
        if (null === $start || null === $end) {
            return null;
        }

        return (int) CarbonImmutable::parse($start)->diffInMilliseconds(CarbonImmutable::parse($end));
    }

    public static function normalizeHeaders(array|string $headers): string
    {
        return is_array($headers)
            ? json_encode($headers, JSON_UNESCAPED_SLASHES)
            : $headers;
    }

    public static function normalizeBody(string $body): ?string
    {
        return self::truncateBody(self::sanitizeBody($body));
    }

    private static function sanitizeBody(string $body): string
    {
        return mb_check_encoding($body, 'UTF-8') ? $body : base64_encode($body);
    }

    private static function truncateBody(?string $body): ?string
    {
        $maxSize = config('request-tracer.max_body_size', 0);

        if ($maxSize > 0 && null !== $body && strlen($body) > $maxSize) {
            $suffix = '... [truncated]';

            return substr($body, 0, $maxSize - strlen($suffix)) . $suffix;
        }

        return $body;
    }
}
