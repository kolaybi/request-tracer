<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Middleware\RequestTracerMiddleware;

beforeEach(fn() => Queue::fake());

it('adds a trace_id to context', function () {
    $middleware = new RequestTracerMiddleware();
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn() => new Response('ok'));

    expect(Context::get('trace_id'))->toBeString()
        ->and(Context::get('trace_id'))->toHaveLength(26); // ULID length
});

it('records incoming trace when enabled', function () {
    config(['kolaybi.request-tracer.incoming.enabled' => true]);

    $middleware = new RequestTracerMiddleware();
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn() => new Response('ok'));

    Queue::assertPushed(StoreTraceJob::class);
});

it('does not record incoming trace when disabled', function () {
    config(['kolaybi.request-tracer.incoming.enabled' => false]);

    $middleware = new RequestTracerMiddleware();
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn() => new Response('ok'));

    Queue::assertNotPushed(StoreTraceJob::class);
});

it('passes response through', function () {
    $middleware = new RequestTracerMiddleware();
    $request = Request::create('/test', 'GET');
    $expected = new Response('ok', 200);

    $result = $middleware->handle($request, fn() => $expected);

    expect($result)->toBe($expected);
});

it('does not record when response is not a symfony response', function () {
    config(['kolaybi.request-tracer.incoming.enabled' => true]);

    $middleware = new RequestTracerMiddleware();
    $request = Request::create('/test', 'GET');

    $result = $middleware->handle($request, fn() => 'plain string');

    expect($result)->toBe('plain string');
    Queue::assertNotPushed(StoreTraceJob::class);
});

it('generates unique trace_id for consecutive requests', function () {
    $middleware = new RequestTracerMiddleware();

    $request1 = Request::create('/first', 'GET');
    $middleware->handle($request1, fn() => new Response('ok'));
    $traceId1 = Context::get('trace_id');

    // Reset context for second request
    Context::forget('trace_id');

    $request2 = Request::create('/second', 'GET');
    $middleware->handle($request2, fn() => new Response('ok'));
    $traceId2 = Context::get('trace_id');

    expect($traceId1)->toBeString()->toHaveLength(26)
        ->and($traceId2)->toBeString()->toHaveLength(26)
        ->and($traceId1)->not->toBe($traceId2);
});

it('passes middleware parameter channel to trace recorder', function () {
    config(['kolaybi.request-tracer.incoming.enabled' => true]);

    $middleware = new RequestTracerMiddleware();
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn() => new Response('ok'), 'web');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'web' === $job->attributes['channel'];
    });
});

it('resolves channel from configured header', function () {
    config([
        'kolaybi.request-tracer.incoming.enabled'        => true,
        'kolaybi.request-tracer.incoming.channel_header' => 'X-Channel',
    ]);

    $middleware = new RequestTracerMiddleware();
    $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CHANNEL' => 'mobile']);

    $middleware->handle($request, fn() => new Response('ok'));

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'mobile' === $job->attributes['channel'];
    });
});

it('header channel takes priority over middleware parameter', function () {
    config([
        'kolaybi.request-tracer.incoming.enabled'        => true,
        'kolaybi.request-tracer.incoming.channel_header' => 'X-Channel',
    ]);

    $middleware = new RequestTracerMiddleware();
    $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CHANNEL' => 'partner-api']);

    $middleware->handle($request, fn() => new Response('ok'), 'web');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'partner-api' === $job->attributes['channel'];
    });
});

it('channel is null when no header and no middleware parameter', function () {
    config(['kolaybi.request-tracer.incoming.enabled' => true]);

    $middleware = new RequestTracerMiddleware();
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn() => new Response('ok'));

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return null === $job->attributes['channel'];
    });
});
