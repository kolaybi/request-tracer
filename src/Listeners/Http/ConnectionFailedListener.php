<?php

namespace KolayBi\RequestTracer\Listeners\Http;

use Illuminate\Http\Client\Events\ConnectionFailed;
use KolayBi\RequestTracer\Listeners\AbstractTraceListener;
use KolayBi\RequestTracer\Support\Timestamp;

class ConnectionFailedListener extends AbstractTraceListener
{
    public function handle(ConnectionFailed $event): void
    {
        $request = $event->request;
        $exception = $event->exception;

        $willPersist = $this->shouldPersist($request->url());

        if (!$willPersist) {
            $urlParts = parse_url($request->url());
            $traceAttributes = $this->extractTraceAttributes($request->attributes());

            $this->recordCircuitBreaker(
                host: $urlParts['host'] ?? '',
                channel: $this->extractTraceString($traceAttributes['channel'] ?? null),
                status: $exception->getCode(),
                hasException: true,
            );

            return;
        }

        $traceAttributes = $this->extractTraceAttributes($request->attributes());

        $attributes = array_merge(
            $this->buildTraceAttributes(
                url: $request->url(),
                method: $request->method(),
                body: $request->body(),
                headers: $this->stripTraceHeaders($request->headers()),
                channel: $this->extractTraceString($traceAttributes['channel'] ?? null),
                extra: $traceAttributes['extra'] ?? null,
                start: $this->resolveStartedAt($request, $traceAttributes),
            ),
            [
                'end'       => Timestamp::now(),
                'protocol'  => parse_url($request->url(), PHP_URL_SCHEME) ?? 'http',
                'status'    => $exception->getCode(),
                'message'   => $exception->getMessage(),
                'exception' => $this->formatException($exception),
            ],
        );

        $this->persistTrace($attributes, preChecked: true);
    }
}
