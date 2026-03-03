<?php

namespace KolayBi\RequestTracer\Listeners\Http;

use Illuminate\Http\Client\Events\ResponseReceived;
use KolayBi\RequestTracer\Listeners\AbstractTraceListener;
use KolayBi\RequestTracer\Support\Timestamp;
use KolayBi\RequestTracer\Support\TraceHelper;

class ResponseReceivedListener extends AbstractTraceListener
{
    public function handle(ResponseReceived $event): void
    {
        $request = $event->request;
        $response = $event->response;
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
                'end'              => Timestamp::now(),
                'protocol'         => parse_url($request->url(), PHP_URL_SCHEME) ?? 'http',
                'status'           => $response->status(),
                'response_body'    => TraceHelper::normalizeBody($response->body()),
                'response_headers' => TraceHelper::normalizeHeaders($this->stripTraceHeaders($response->headers())),
                'response_size'    => strlen($response->body()),
                'stats'            => json_encode($response->handlerStats(), JSON_UNESCAPED_SLASHES),
            ],
        );

        $this->persistTrace($attributes);
    }
}
