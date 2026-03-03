<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Listeners\Http\ConnectionFailedListener;

beforeEach(function () {
    Queue::fake();
    Context::add('trace_id', 'test-trace-id');
});

it('records connection failure trace', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('POST', 'https://api.example.com/timeout');
    $request = new Request($psrRequest);
    $exception = new ConnectionException('Connection timed out', 28);

    $event = new ConnectionFailed($request, $exception);

    $listener = new ConnectionFailedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'POST' === $job->attributes['method']
            && 'api.example.com' === $job->attributes['host']
            && 28 === $job->attributes['status']
            && 'Connection timed out' === $job->attributes['message']
            && str_contains($job->attributes['exception'], 'ConnectionFailedListenerTest.php')
            && 'test-trace-id' === $job->attributes['trace_id'];
    });
});

it('extracts channel from request attributes on failure', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);
    $request->setRequestAttributes(['request_tracer' => ['channel' => 'erp']]);
    $exception = new ConnectionException('fail');

    $event = new ConnectionFailed($request, $exception);

    $listener = new ConnectionFailedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, fn(StoreTraceJob $job) => 'erp' === $job->attributes['channel']);
});

it('generates trace_id when not in context', function () {
    Context::forget('trace_id');

    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);
    $exception = new ConnectionException('fail');

    $event = new ConnectionFailed($request, $exception);

    $listener = new ConnectionFailedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return null !== $job->attributes['trace_id']
            && 26 === strlen($job->attributes['trace_id']);
    });

    // Also added to context
    expect(Context::get('trace_id'))->toBeString()->toHaveLength(26);
});
