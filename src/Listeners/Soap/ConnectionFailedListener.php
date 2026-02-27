<?php

namespace KolayBi\RequestTracer\Listeners\Soap;

use Illuminate\Support\Str;
use KolayBi\RequestTracer\Events\Soap\ConnectionFailedEvent;
use KolayBi\RequestTracer\Listeners\AbstractTraceListener;
use Throwable;

class ConnectionFailedListener extends AbstractTraceListener
{
    public function handle(ConnectionFailedEvent $event): void
    {
        $soapClient = $event->soapClient;
        $exception = $event->exception;

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
                'end'       => $event->end,
                'protocol'  => 'soap',
                'query'     => $this->extractAction($event->action, $event->request),
                'status'    => $exception->getCode(),
                'message'   => $exception->getMessage(),
                'exception' => $this->formatException($exception),
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
}
