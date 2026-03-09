<?php

namespace KolayBi\RequestTracer\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use KolayBi\RequestTracer\Contracts\TraceContextProvider;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use Symfony\Component\HttpFoundation\Response;

class IncomingTraceRecorder
{
    public function record(Request $request, Response $response, string $start, string $end): void
    {
        if (!$this->shouldSample() || !$this->shouldTraceRoute($request)) {
            return;
        }

        $contextProvider = app(TraceContextProvider::class);
        $tenantColumn = config('kolaybi.request-tracer.tenant_column', 'tenant_id');

        $excludeRequestBody = TraceHelper::shouldExcludeBody($request->headers->get('Content-Type'));
        $excludeResponseBody = TraceHelper::shouldExcludeBody($response->headers->get('Content-Type'));

        $attributes = [
            $tenantColumn       => $contextProvider->tenantId(),
            'user_id'           => $contextProvider->userId(),
            'client_ip'         => $request->ip(),
            'server_identifier' => $contextProvider->serverIdentifier(),
            'trace_id'          => Context::get('trace_id'),
            'method'            => $request->method(),
            'host'              => $request->getHost(),
            'path'              => $request->path(),
            'query'             => TraceHelper::normalizeQuery($request->getQueryString()),
            'route'             => $request->route()?->uri(),
            'request_body'      => $excludeRequestBody ? null : TraceHelper::normalizeBody($request->getContent()),
            'request_headers'   => TraceHelper::normalizeHeaders($request->headers->all()),
            'request_size'      => strlen($request->getContent()),
            'start'             => $start,
            'end'               => $end,
            'status'            => $response->getStatusCode(),
            'response_headers'  => TraceHelper::normalizeHeaders($response->headers->all()),
            'response_body'     => $excludeResponseBody ? null : $this->captureResponseBody($response),
            'response_size'     => $this->resolveResponseSize($response),
        ];

        $modelClass = config('kolaybi.request-tracer.incoming.model', IncomingRequestTrace::class);

        TraceHelper::dispatchTrace($attributes, $modelClass);
    }

    private function captureResponseBody(Response $response): ?string
    {
        if (!config('kolaybi.request-tracer.incoming.capture_response_body', false)) {
            return null;
        }

        $content = $response->getContent();

        if (false === $content) {
            return null;
        }

        return TraceHelper::normalizeBody($content);
    }

    private function shouldTraceRoute(Request $request): bool
    {
        $path = $request->path();

        $only = $this->parsePatterns(config('kolaybi.request-tracer.incoming.only', ''));

        if ([] !== $only) {
            return Str::is($only, $path);
        }

        $except = $this->parsePatterns(config('kolaybi.request-tracer.incoming.except', ''));

        if ([] !== $except) {
            return !Str::is($except, $path);
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
        $rate = config('kolaybi.request-tracer.incoming.sample_rate', 1.0);

        return $rate >= 1.0 || (mt_rand() / mt_getrandmax()) < $rate;
    }

    private function resolveResponseSize(Response $response): ?int
    {
        $content = $response->getContent();

        if (false !== $content) {
            return strlen($content);
        }

        $contentLength = $response->headers->get('Content-Length');

        if (!is_numeric($contentLength)) {
            return null;
        }

        return max(0, (int) $contentLength);
    }
}
