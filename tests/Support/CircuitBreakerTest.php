<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use KolayBi\RequestTracer\Events\CircuitBreakerTripped;
use KolayBi\RequestTracer\Support\CircuitBreaker;

beforeEach(function () {
    config([
        'cache.default'                                            => 'array',
        'kolaybi.request-tracer.circuit_breaker.enabled'           => true,
        'kolaybi.request-tracer.circuit_breaker.failure_threshold' => 3,
        'kolaybi.request-tracer.circuit_breaker.recovery_after'    => 60,
    ]);
    Cache::flush();
    Event::fake();
});

it('does not dispatch event below threshold', function () {
    $cb = app(CircuitBreaker::class);

    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);

    Event::assertNotDispatched(CircuitBreakerTripped::class);
});

it('dispatches event at threshold', function () {
    $cb = app(CircuitBreaker::class);

    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);

    Event::assertDispatched(CircuitBreakerTripped::class, function (CircuitBreakerTripped $e) {
        return 'api.example.com' === $e->host && null === $e->channel && 3 === $e->failures;
    });
});

it('does not re-dispatch event beyond threshold', function () {
    $cb = app(CircuitBreaker::class);

    for ($i = 0; $i < 6; $i++) {
        $cb->recordFailure('api.example.com', null);
    }

    Event::assertDispatchedTimes(CircuitBreakerTripped::class, 1);
});

it('records success and resets failure counter', function () {
    $cb = app(CircuitBreaker::class);

    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);
    $cb->recordSuccess('api.example.com', null);

    $status = $cb->getStatus('api.example.com', null);

    expect($status['failures'])->toBe(0)
        ->and($status['healthy'])->toBeTrue();
});

it('returns healthy status with no failures', function () {
    $cb = app(CircuitBreaker::class);

    $status = $cb->getStatus('api.example.com', null);

    expect($status['healthy'])->toBeTrue()
        ->and($status['tripped'])->toBeFalse()
        ->and($status['recovering'])->toBeFalse();
});

it('returns tripped status when threshold reached', function () {
    $cb = app(CircuitBreaker::class);

    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);

    $status = $cb->getStatus('api.example.com', null);

    expect($status['tripped'])->toBeTrue()
        ->and($status['healthy'])->toBeFalse()
        ->and($status['failures'])->toBe(3);
});

it('tracks separate endpoints independently', function () {
    $cb = app(CircuitBreaker::class);

    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);
    $cb->recordSuccess('cdn.example.com', null);

    $endpoints = $cb->allEndpoints();

    expect($endpoints)->toHaveCount(2);
    expect($endpoints[0]['host'])->toBe('api.example.com');
    expect($endpoints[0]['tripped'])->toBeTrue();
    expect($endpoints[1]['host'])->toBe('cdn.example.com');
    expect($endpoints[1]['healthy'])->toBeTrue();
});

it('tracks endpoints with different channels separately', function () {
    $cb = app(CircuitBreaker::class);

    $cb->recordFailure('api.example.com', 'payments');
    $cb->recordFailure('api.example.com', 'payments');
    $cb->recordFailure('api.example.com', 'payments');
    $cb->recordSuccess('api.example.com', 'notifications');

    $endpoints = $cb->allEndpoints();

    expect($endpoints)->toHaveCount(2);

    $payments = collect($endpoints)->firstWhere('channel', 'payments');
    $notifications = collect($endpoints)->firstWhere('channel', 'notifications');

    expect($payments['tripped'])->toBeTrue()
        ->and($notifications['healthy'])->toBeTrue();
});

it('separates incoming and outgoing endpoints', function () {
    $cb = app(CircuitBreaker::class);

    $cb->recordFailure('api.example.com', null, 'outgoing');
    $cb->recordFailure('api.example.com', null, 'outgoing');
    $cb->recordFailure('api.example.com', null, 'outgoing');
    $cb->recordFailure('/api/orders', null, 'incoming');

    $outgoing = $cb->getStatus('api.example.com', null, 'outgoing');
    $incoming = $cb->getStatus('/api/orders', null, 'incoming');

    expect($outgoing['failures'])->toBe(3)
        ->and($outgoing['tripped'])->toBeTrue()
        ->and($outgoing['direction'])->toBe('outgoing')
        ->and($incoming['failures'])->toBe(1)
        ->and($incoming['tripped'])->toBeFalse()
        ->and($incoming['direction'])->toBe('incoming');
});

it('includes direction in allEndpoints', function () {
    $cb = app(CircuitBreaker::class);

    $cb->recordSuccess('api.example.com', null, 'outgoing');
    $cb->recordSuccess('/api/orders', null, 'incoming');

    $endpoints = $cb->allEndpoints();
    $directions = collect($endpoints)->pluck('direction')->all();

    expect($endpoints)->toHaveCount(2)
        ->and($directions)->toContain('outgoing')
        ->and($directions)->toContain('incoming');
});

it('dispatches event with direction', function () {
    $cb = app(CircuitBreaker::class);

    $cb->recordFailure('/api/orders', null, 'incoming');
    $cb->recordFailure('/api/orders', null, 'incoming');
    $cb->recordFailure('/api/orders', null, 'incoming');

    Event::assertDispatched(CircuitBreakerTripped::class, function (CircuitBreakerTripped $e) {
        return '/api/orders' === $e->host && 'incoming' === $e->direction;
    });
});

it('caps registry at 500 entries', function () {
    $cb = app(CircuitBreaker::class);

    for ($i = 0; $i < 501; $i++) {
        $cb->recordSuccess("host-{$i}.example.com", null);
    }

    $endpoints = $cb->allEndpoints();

    expect(count($endpoints))->toBeLessThanOrEqual(500);
});

it('ignores record calls with empty host', function () {
    $cb = app(CircuitBreaker::class);

    $cb->record('', null, 500);

    expect($cb->allEndpoints())->toBeEmpty();
});

it('does not treat null status as failure when no exception exists', function () {
    $cb = app(CircuitBreaker::class);

    $cb->record('api.example.com', null, null);

    $status = $cb->getStatus('api.example.com', null);

    expect($status['failures'])->toBe(0)
        ->and($status['healthy'])->toBeTrue()
        ->and($status['tripped'])->toBeFalse();
});

it('returns disabled when config is false', function () {
    config(['kolaybi.request-tracer.circuit_breaker.enabled' => false]);

    $cb = app(CircuitBreaker::class);

    expect($cb->isEnabled())->toBeFalse();
});
