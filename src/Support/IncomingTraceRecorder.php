<?php

namespace KolayBi\RequestTracer\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use KolayBi\RequestTracer\Contracts\TraceContextProvider;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use Symfony\Component\HttpFoundation\Response;

use function strlen;

class IncomingTraceRecorder
{
    public function record(Request $request, Response $response, string $start, string $end): void
    {
        $contextProvider = app(TraceContextProvider::class);
        $tenantColumn = config('request-tracer.tenant_column', 'tenant_id');

        $attributes = [
            $tenantColumn       => $contextProvider->tenantId(),
            'user_id'           => $contextProvider->userId(),
            'client_ip'         => $request->ip(),
            'server_identifier' => $contextProvider->serverIdentifier(),
            'trace_id'          => Context::get('trace_id'),
            'method'            => $request->method(),
            'host'              => $request->getHost(),
            'path'              => $request->path(),
            'query'             => $request->getQueryString(),
            'route'             => $request->route()?->uri(),
            'request_body'      => TraceHelper::normalizeBody($request->getContent()),
            'request_headers'   => TraceHelper::normalizeHeaders($request->headers->all()),
            'request_size'      => strlen($request->getContent()),
            'start'             => $start,
            'end'               => $end,
            'status'            => $response->getStatusCode(),
            'response_headers'  => TraceHelper::normalizeHeaders($response->headers->all()),
            'response_body'     => $this->captureResponseBody($response),
            'response_size'     => $this->resolveResponseSize($response),
        ];

        $modelClass = config('request-tracer.incoming.model', IncomingRequestTrace::class);

        TraceHelper::dispatchTrace($attributes, $modelClass);
    }

    private function captureResponseBody(Response $response): ?string
    {
        if (!config('request-tracer.incoming.capture_response_body', false)) {
            return null;
        }

        $content = $response->getContent();

        if (false === $content) {
            return null;
        }

        return TraceHelper::normalizeBody($content);
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
