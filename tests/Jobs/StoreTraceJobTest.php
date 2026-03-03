<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

it('is a queued job', function () {
    expect(new StoreTraceJob([], OutgoingRequestTrace::class))
        ->toBeInstanceOf(ShouldQueue::class);
});

it('stores attributes and model class', function () {
    $attributes = ['host' => 'example.com', 'method' => 'GET'];
    $job = new StoreTraceJob($attributes, OutgoingRequestTrace::class);

    expect($job->attributes)->toBe($attributes)
        ->and($job->modelClass)->toBe(OutgoingRequestTrace::class);
});

it('dispatches to configured queue and connection', function () {
    Queue::fake();

    config([
        'kolaybi.request-tracer.queue_connection' => 'redis',
        'kolaybi.request-tracer.queue'            => 'tracing',
    ]);

    StoreTraceJob::dispatch(['host' => 'example.com'], OutgoingRequestTrace::class)
        ->onConnection('redis')
        ->onQueue('tracing');

    Queue::assertPushed(StoreTraceJob::class);
});
