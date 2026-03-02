<?php

namespace KolayBi\RequestTracer\Listeners;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use KolayBi\RequestTracer\Contracts\TraceContextProvider;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;
use KolayBi\RequestTracer\Support\RequestTimingStore;
use KolayBi\RequestTracer\Support\Timestamp;
use KolayBi\RequestTracer\Support\TraceHelper;
use Throwable;

abstract class AbstractTraceListener
{
    protected function buildTraceAttributes(
        string $url,
        string $method,
        string $body,
        array|string $headers,
        ?string $channel = null,
        array|string|null $extra = null,
        ?string $start = null,
    ): array {
        $contextProvider = app(TraceContextProvider::class);
        $urlParts = parse_url($url);
        $tenantColumn = config('kolaybi.request-tracer.tenant_column', 'tenant_id');

        return [
            $tenantColumn       => $contextProvider->tenantId(),
            'user_id'           => $contextProvider->userId(),
            'client_ip'         => $contextProvider->clientIp(),
            'server_identifier' => $contextProvider->serverIdentifier(),
            'trace_id'          => $this->resolveTraceId(),
            'channel'           => $channel,
            'start'             => $start,
            'method'            => $method,
            'host'              => $urlParts['host'] ?? null,
            'path'              => $urlParts['path'] ?? null,
            'query'             => $urlParts['query'] ?? null,
            'request_body'      => TraceHelper::normalizeBody($body),
            'request_headers'   => TraceHelper::normalizeHeaders($headers),
            'request_size'      => strlen($body),
            'response_body'     => null,
            'response_headers'  => null,
            'response_size'     => 0,
            'message'           => null,
            'exception'         => null,
            'stats'             => null,
            'extra'             => is_array($extra) ? json_encode($extra, JSON_UNESCAPED_SLASHES) : $extra,
        ];
    }

    protected function persistTrace(array $attributes): void
    {
        if (!$this->shouldSample()) {
            return;
        }

        $modelClass = config('kolaybi.request-tracer.outgoing.model', OutgoingRequestTrace::class);

        TraceHelper::dispatchTrace($attributes, $modelClass);
    }

    protected function formatException(Throwable $e): string
    {
        return $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString();
    }

    protected function resolveTraceId(): ?string
    {
        $traceId = Context::get('trace_id');

        if (null === $traceId) {
            $traceId = (string) Str::ulid();
            Context::add('trace_id', $traceId);
        }

        return $traceId;
    }

    protected function extractTraceAttributes(array $attributes): array
    {
        $traceAttributes = $attributes['request_tracer'] ?? [];

        return is_array($traceAttributes) ? $traceAttributes : [];
    }

    protected function extractTraceString(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = end($value);
        }

        if (null === $value) {
            return null;
        }

        $value = (string) $value;

        return '' === $value ? null : $value;
    }

    protected function resolveStartedAt(Request $request, array $traceAttributes): string
    {
        return $this->extractTraceString($traceAttributes['started_at'] ?? null)
            ?? RequestTimingStore::pull($request->toPsrRequest())
            ?? ($request->header('X-Trace-Started-At')[0] ?? null)
            ?? Timestamp::now();
    }

    protected function stripTraceHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            fn(string $key) => !str_starts_with(strtolower($key), 'x-trace-'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function shouldSample(): bool
    {
        $rate = config('kolaybi.request-tracer.outgoing.sample_rate', 1.0);

        return $rate >= 1.0 || (mt_rand() / mt_getrandmax()) < $rate;
    }
}
