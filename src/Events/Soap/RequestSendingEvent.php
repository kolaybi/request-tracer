<?php

namespace KolayBi\RequestTracer\Events\Soap;

use Illuminate\Foundation\Events\Dispatchable;
use SoapClient;

class RequestSendingEvent
{
    use Dispatchable;

    public function __construct(
        public readonly SoapClient $soapClient,
        public readonly string $request,
        public readonly string $location,
        public readonly string $action,
    ) {}
}
