<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

it('saves a model when handled', function () {
    $job = new StoreTraceJob(
        ['host' => 'example.com', 'method' => 'GET', 'status' => 200],
        OutgoingRequestTrace::class,
    );

    $job->handle();

    expect(OutgoingRequestTrace::count())->toBe(1)
        ->and(OutgoingRequestTrace::first()->host)->toBe('example.com');
});
