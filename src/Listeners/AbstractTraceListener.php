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
            'response_size'     => null,
            'message'           => null,
            'exception'         => null,
            'stats'             => null,
            'extra'             => is_array($extra) ? json_encode($extra, JSON_UNESCAPED_SLASHES) : $extra,
        ];
    }

    protected function persistTrace(array $attributes): void
    {
        if (!$this->shouldSample() || !$this->shouldTraceUrl($attributes)) {
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

    protected function extractSoapAction(string $action, string $request): string
    {
        $extracted = Str::remove('http://tempuri.org/', $action);

        return $extracted ?: $this->extractSoapBodyOperationName($request);
    }

    protected function extractSoapBodyOperationName(string $request): ?string
    {
        try {
            $xml = simplexml_load_string($request);
            $body = $xml->xpath('SOAP-ENV:Body')[0];
            $operation = $body?->xpath('*')[0];

            return $operation?->getName();
        } catch (Throwable) {
            return null;
        }
    }

    private function shouldTraceUrl(array $attributes): bool
    {
        $url = trim(($attributes['host'] ?? '') . ($attributes['path'] ?? ''), '/');

        $only = $this->parsePatterns(config('kolaybi.request-tracer.outgoing.only', ''));

        if ([] !== $only) {
            return Str::is($only, $url);
        }

        $except = $this->parsePatterns(config('kolaybi.request-tracer.outgoing.except', ''));

        if ([] !== $except) {
            return !Str::is($except, $url);
        }

        return true;
    }

    private function parsePatterns(array|string $patterns): array
    {
        if (is_array($patterns)) {
            return array_filter($patterns);
        }

        return array_filter(array_map('trim', explode(',', $patterns)));
    }

    private function shouldSample(): bool
    {
        $rate = config('kolaybi.request-tracer.outgoing.sample_rate', 1.0);

        return $rate >= 1.0 || (mt_rand() / mt_getrandmax()) < $rate;
    }
}
