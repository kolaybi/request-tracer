<?php

namespace KolayBi\RequestTracer\Listeners\Soap;

use Illuminate\Support\Str;
use KolayBi\RequestTracer\Events\Soap\ResponseReceivedEvent;
use KolayBi\RequestTracer\Listeners\AbstractTraceListener;
use KolayBi\RequestTracer\Support\TraceHelper;
use SoapClient;

class ResponseReceivedListener extends AbstractTraceListener
{
    public function handle(ResponseReceivedEvent $event): void
    {
        $soapClient = $event->soapClient;

        $attributes = array_merge(
            $this->buildTraceAttributes(
                url: $event->location,
                method: 'POST',
                body: $event->request,
                headers: $soapClient->__getLastRequestHeaders() ?? '',
                channel: $event->channel,
                extra: $event->extra,
                start: $event->start,
            ),
            [
                'end'              => $event->end,
                'protocol'         => 'soap',
                'query'            => $this->extractSoapAction($event->action, $event->request),
                'status'           => $this->extractStatusCode($soapClient),
                'response_body'    => null === $event->response ? null : TraceHelper::normalizeBody($event->response),
                'response_headers' => TraceHelper::normalizeHeaders($soapClient->__getLastResponseHeaders() ?? ''),
                'response_size'    => null !== $event->response ? strlen($event->response) : null,
            ],
        );

        $this->persistTrace($attributes);
    }

    private function extractStatusCode(SoapClient $soapClient): int
    {
        return (int) Str::match('/HTTP\/[\d.]+\s*\K\d+/', $soapClient->__getLastResponseHeaders() ?? '') ?: 0;
    }
}
