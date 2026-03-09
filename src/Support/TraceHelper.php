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
            ->onConnection(config('kolaybi.request-tracer.queue_connection'))
            ->onQueue(config('kolaybi.request-tracer.queue'));
    }

    public static function calculateDuration(?string $start, ?string $end): ?int
    {
        if (null === $start || null === $end) {
            return null;
        }

        return max(0, (int) CarbonImmutable::parse($start)->diffInMilliseconds(CarbonImmutable::parse($end)));
    }

    public static function normalizeHeaders(array|string $headers): string
    {
        if (self::shouldMaskSensitive()) {
            $headers = self::maskHeaders($headers, self::sensitiveKeys(), self::maskValue());
        }

        return is_array($headers)
            ? json_encode($headers, JSON_UNESCAPED_SLASHES)
            : $headers;
    }

    public static function normalizeQuery(?string $query): ?string
    {
        if (null === $query || '' === $query || !self::shouldMaskSensitive()) {
            return $query;
        }

        parse_str($query, $parsed);

        if ([] === $parsed) {
            return $query;
        }

        return http_build_query(self::maskArrayByKey($parsed, self::sensitiveKeys(), self::maskValue()));
    }

    public static function normalizeBody(string $body): ?string
    {
        $body = self::sanitizeBody($body);

        if (self::shouldMaskSensitive()) {
            $body = self::maskBody($body, self::sensitiveKeys(), self::maskValue());
        }

        return self::truncateBody($body);
    }

    public static function shouldExcludeBody(?string $contentType): bool
    {
        if (null === $contentType || '' === $contentType) {
            return false;
        }

        $prefixes = config('kolaybi.request-tracer.exclude_body_content_types', '');

        if (is_string($prefixes)) {
            $prefixes = array_filter(array_map('trim', explode(',', $prefixes)));
        }

        if (!is_array($prefixes) || [] === $prefixes) {
            return false;
        }

        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        foreach ($prefixes as $prefix) {
            if (str_starts_with($contentType, strtolower(trim($prefix)))) {
                return true;
            }
        }

        return false;
    }

    private static function shouldMaskSensitive(): bool
    {
        return (bool) config('kolaybi.request-tracer.mask_sensitive', false);
    }

    private static function maskValue(): string
    {
        return (string) config('kolaybi.request-tracer.mask_value', '[REDACTED]');
    }

    /**
     * @return array<int, string>
     */
    private static function sensitiveKeys(): array
    {
        $keys = config('kolaybi.request-tracer.sensitive_keys', []);

        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }

        if (!is_array($keys)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static fn(mixed $key): string => str_replace('_', '-', strtolower(trim((string) $key))),
                    $keys,
                ),
            ),
        );
    }

    private static function isSensitiveKey(int|string $key, array $sensitiveKeys): bool
    {
        if (!is_string($key)) {
            return false;
        }

        $normalized = str_replace('_', '-', strtolower(trim($key)));

        return in_array($normalized, $sensitiveKeys, true);
    }

    private static function maskHeaders(array|string $headers, array $sensitiveKeys, string $maskValue): array|string
    {
        if (is_array($headers)) {
            return self::maskArrayByKey($headers, $sensitiveKeys, $maskValue);
        }

        $lines = preg_split("/\r\n|\n|\r/", $headers) ?: [];
        $eol = str_contains($headers, "\r\n") ? "\r\n" : "\n";

        foreach ($lines as $index => $line) {
            $parts = explode(':', $line, 2);

            if (2 !== count($parts)) {
                continue;
            }

            $headerName = trim($parts[0]);

            if (self::isSensitiveKey($headerName, $sensitiveKeys)) {
                $lines[$index] = "{$headerName}: {$maskValue}";
            }
        }

        return implode($eol, $lines);
    }

    private static function maskBody(string $body, array $sensitiveKeys, string $maskValue): string
    {
        if ('' === $body) {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
            $encoded = json_encode(self::maskArrayByKey($decoded, $sensitiveKeys, $maskValue), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return false === $encoded ? $body : $encoded;
        }

        if (str_contains($body, '=') && !str_contains($body, '{') && !str_contains($body, '<')) {
            parse_str(ltrim($body, '?'), $parsed);

            if (is_array($parsed) && [] !== $parsed) {
                return http_build_query(self::maskArrayByKey($parsed, $sensitiveKeys, $maskValue));
            }
        }

        return $body;
    }

    private static function maskArrayByKey(array $values, array $sensitiveKeys, string $maskValue): array
    {
        foreach ($values as $key => $value) {
            if (self::isSensitiveKey($key, $sensitiveKeys)) {
                $values[$key] = $maskValue;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = self::maskArrayByKey($value, $sensitiveKeys, $maskValue);
            }
        }

        return $values;
    }

    private static function sanitizeBody(string $body): string
    {
        return mb_check_encoding($body, 'UTF-8') ? $body : base64_encode($body);
    }

    private static function truncateBody(?string $body): ?string
    {
        $maxSize = config('kolaybi.request-tracer.max_body_size', 0);

        if ($maxSize > 0 && null !== $body && strlen($body) > $maxSize) {
            $suffix = '... [truncated]';
            $suffixLength = strlen($suffix);

            // Keep body size within configured max for very small limits.
            if ($maxSize <= $suffixLength) {
                return substr($body, 0, $maxSize);
            }

            return substr($body, 0, $maxSize - $suffixLength) . $suffix;
        }

        return $body;
    }
}
