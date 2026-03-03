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
