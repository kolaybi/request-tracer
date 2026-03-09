<?php

use KolayBi\RequestTracer\Events\CircuitBreakerTripped;

it('stores host, channel, failures, and direction', function () {
    $event = new CircuitBreakerTripped('api.example.com', 'payments', 5, 'outgoing');

    expect($event->host)->toBe('api.example.com')
        ->and($event->channel)->toBe('payments')
        ->and($event->failures)->toBe(5)
        ->and($event->direction)->toBe('outgoing');
});

it('accepts null channel', function () {
    $event = new CircuitBreakerTripped('api.example.com', null, 3);

    expect($event->channel)->toBeNull();
});

it('defaults direction to outgoing', function () {
    $event = new CircuitBreakerTripped('api.example.com', null, 3);

    expect($event->direction)->toBe('outgoing');
});

it('accepts incoming direction', function () {
    $event = new CircuitBreakerTripped('/api/orders', null, 5, 'incoming');

    expect($event->direction)->toBe('incoming')
        ->and($event->host)->toBe('/api/orders');
});
