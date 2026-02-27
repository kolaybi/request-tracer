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

        $attributes = array_merge(
            $this->buildTraceAttributes(
                url: $request->url(),
                method: $request->method(),
                body: $request->body(),
                headers: $this->stripTraceHeaders($request->headers()),
                channel: $request->header('X-Trace-Channel')[0] ?? null,
                extra: $request->header('X-Trace-Extra')[0] ?? null,
                start: $request->header('X-Trace-Started-At')[0] ?? null,
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

    private function stripTraceHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            fn(string $key) => !str_starts_with(strtolower($key), 'x-trace-'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
