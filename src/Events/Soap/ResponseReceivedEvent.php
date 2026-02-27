<?php

namespace KolayBi\RequestTracer\Events\Soap;

use Illuminate\Foundation\Events\Dispatchable;
use SoapClient;

class ResponseReceivedEvent
{
    use Dispatchable;

    public function __construct(
        public readonly SoapClient $soapClient,
        public readonly string $request,
        public readonly string $location,
        public readonly string $action,
        public readonly ?string $response,
        public readonly ?string $channel,
        public readonly array|string|null $extra,
        public readonly string $start,
        public readonly string $end,
    ) {}
}
