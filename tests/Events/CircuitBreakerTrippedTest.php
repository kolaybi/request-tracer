<?php

use KolayBi\RequestTracer\Events\CircuitBreakerTripped;

it('stores host, channel, and failures', function () {
    $event = new CircuitBreakerTripped('api.example.com', 'payments', 5);

    expect($event->host)->toBe('api.example.com')
        ->and($event->channel)->toBe('payments')
        ->and($event->failures)->toBe(5);
});

it('accepts null channel', function () {
    $event = new CircuitBreakerTripped('api.example.com', null, 3);

    expect($event->channel)->toBeNull();
});
