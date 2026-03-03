<?php

use KolayBi\RequestTracer\Contracts\NullContextProvider;
use KolayBi\RequestTracer\Contracts\TraceContextProvider;

it('registers TraceContextProvider as singleton', function () {
    $provider1 = app(TraceContextProvider::class);
    $provider2 = app(TraceContextProvider::class);

    expect($provider1)->toBe($provider2);
});

it('uses NullContextProvider when no context_provider is configured', function () {
    config(['kolaybi.request-tracer.context_provider' => null]);

    // Force a fresh resolution
    app()->forgetInstance(TraceContextProvider::class);

    expect(app(TraceContextProvider::class))->toBeInstanceOf(NullContextProvider::class);
});

it('merges package config', function () {
    expect(config('kolaybi.request-tracer.queue_connection'))->toBeString()
        ->and(config('kolaybi.request-tracer.outgoing.enabled'))->toBeBool()
        ->and(config('kolaybi.request-tracer.incoming.enabled'))->toBeBool();
});
