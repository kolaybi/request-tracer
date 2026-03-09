<?php

namespace KolayBi\RequestTracer\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CircuitBreakerTripped
{
    use Dispatchable;

    public function __construct(
        public readonly string $host,
        public readonly ?string $channel,
        public readonly int $failures,
        public readonly string $direction = 'outgoing',
    ) {}
}
