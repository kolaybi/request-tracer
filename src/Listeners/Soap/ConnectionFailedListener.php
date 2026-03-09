<?php

namespace KolayBi\RequestTracer\Listeners\Soap;

use KolayBi\RequestTracer\Events\Soap\ConnectionFailedEvent;
use KolayBi\RequestTracer\Listeners\AbstractTraceListener;

class ConnectionFailedListener extends AbstractTraceListener
{
    public function handle(ConnectionFailedEvent $event): void
    {
        $soapClient = $event->soapClient;
        $exception = $event->exception;

        $willPersist = $this->shouldPersist($event->location);

        if (!$willPersist) {
            $urlParts = parse_url($event->location);

            $this->updateCircuitBreaker([
                'host'      => $urlParts['host'] ?? null,
                'path'      => $urlParts['path'] ?? null,
                'channel'   => $event->channel,
                'status'    => $exception->getCode(),
                'exception' => $this->formatException($exception),
            ]);

            return;
        }

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
                'query'     => $this->extractSoapAction($event->action, $event->request),
                'status'    => $exception->getCode(),
                'message'   => $exception->getMessage(),
                'exception' => $this->formatException($exception),
            ],
        );

        $this->persistTrace($attributes, preChecked: true);
    }
}
