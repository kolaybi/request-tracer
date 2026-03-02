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
        $traceAttributes = $this->extractTraceAttributes($request->attributes());

        $attributes = array_merge(
            $this->buildTraceAttributes(
                url: $request->url(),
                method: $request->method(),
                body: $request->body(),
                headers: $this->stripTraceHeaders($request->headers()),
                channel: $this->extractTraceString($traceAttributes['channel'] ?? null) ?? ($request->header('X-Trace-Channel')[0] ?? null),
                extra: $traceAttributes['extra'] ?? ($request->header('X-Trace-Extra')[0] ?? null),
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

        $this->persistTrace($attributes);
    }
}
