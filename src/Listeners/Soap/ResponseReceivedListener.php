<?php

namespace KolayBi\RequestTracer\Listeners\Soap;

use Illuminate\Support\Str;
use KolayBi\RequestTracer\Events\Soap\ResponseReceivedEvent;
use KolayBi\RequestTracer\Listeners\AbstractTraceListener;
use KolayBi\RequestTracer\Support\TraceHelper;
use SoapClient;
use Throwable;

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
                'query'            => $this->extractAction($event->action, $event->request),
                'status'           => $this->extractStatusCode($soapClient),
                'response_body'    => null === $event->response ? null : TraceHelper::normalizeBody($event->response),
                'response_headers' => TraceHelper::normalizeHeaders($soapClient->__getLastResponseHeaders() ?? ''),
                'response_size'    => strlen($event->response ?? ''),
            ],
        );

        $this->persistTrace($attributes);
    }

    private function extractAction(string $action, string $request): string
    {
        $extracted = Str::remove('http://tempuri.org/', $action);

        return $extracted ?: $this->extractSoapBodyOperationName($request);
    }

    private function extractSoapBodyOperationName(string $request): ?string
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

    private function extractStatusCode(SoapClient $soapClient): int
    {
        return (int) Str::match('/HTTP\/[\d.]+\s*\K\d+/', $soapClient->__getLastResponseHeaders() ?? '') ?: 0;
    }
}
