<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use KolayBi\RequestTracer\Support\CircuitBreaker;

beforeEach(function () {
    config([
        'cache.default'                                            => 'array',
        'kolaybi.request-tracer.circuit_breaker.enabled'           => true,
        'kolaybi.request-tracer.circuit_breaker.failure_threshold' => 3,
        'kolaybi.request-tracer.circuit_breaker.recovery_after'    => 60,
    ]);
    Cache::flush();
});

it('shows disabled message when circuit breaker is off', function () {
    config(['kolaybi.request-tracer.circuit_breaker.enabled' => false]);

    $this->artisan('request-tracer:health')
        ->expectsOutputToContain('Circuit breaker is disabled')
        ->assertExitCode(0);
});

it('shows no endpoints message when registry is empty', function () {
    $this->artisan('request-tracer:health')
        ->expectsOutputToContain('No endpoints monitored')
        ->assertExitCode(0);
});

it('shows DEGRADED for tripped endpoint', function () {
    $cb = app(CircuitBreaker::class);
    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);
    $cb->recordFailure('api.example.com', null);

    Artisan::call('request-tracer:health');
    $output = Artisan::output();

    expect($output)->toContain('api.example.com')
        ->toContain('DEGRADED');
});

it('shows HEALTHY for endpoint with zero failures', function () {
    $cb = app(CircuitBreaker::class);
    $cb->recordSuccess('api.example.com', null);

    Artisan::call('request-tracer:health');
    $output = Artisan::output();

    expect($output)->toContain('api.example.com')
        ->toContain('HEALTHY');
});

it('shows Type column with OUTGOING and INCOMING', function () {
    $cb = app(CircuitBreaker::class);
    $cb->recordSuccess('api.example.com', null, 'outgoing');
    $cb->recordSuccess('/api/orders', null, 'incoming');

    Artisan::call('request-tracer:health');
    $output = Artisan::output();

    expect($output)->toContain('OUTGOING')
        ->toContain('INCOMING')
        ->toContain('api.example.com')
        ->toContain('/api/orders');
});
