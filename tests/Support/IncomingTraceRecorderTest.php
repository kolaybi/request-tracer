<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Contracts\TraceContextProvider;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Support\IncomingTraceRecorder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

beforeEach(function () {
    Queue::fake();
    Context::add('trace_id', 'test-trace-id');
});

it('records an incoming request trace', function () {
    $request = Request::create('/api/users', 'GET');
    $response = new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:01.000000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return IncomingRequestTrace::class === $job->modelClass
            && 'GET' === $job->attributes['method']
            && 'api/users' === $job->attributes['path']
            && 200 === $job->attributes['status']
            && 'test-trace-id' === $job->attributes['trace_id'];
    });
});

it('uses custom model class from config', function () {
    config(['kolaybi.request-tracer.incoming.model' => 'App\CustomTrace']);

    $request = Request::create('/test', 'POST', [], [], [], [], '{"a":1}');
    $response = new Response('ok', 200);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.500000');

    Queue::assertPushed(StoreTraceJob::class, fn(StoreTraceJob $job) => 'App\CustomTrace' === $job->modelClass);
});

it('captures response body when configured', function () {
    config(['kolaybi.request-tracer.incoming.capture_response_body' => true]);

    $request = Request::create('/test', 'GET');
    $response = new Response('response body', 200);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.100000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'response body' === $job->attributes['response_body'];
    });
});

it('does not capture response body by default', function () {
    config(['kolaybi.request-tracer.incoming.capture_response_body' => false]);

    $request = Request::create('/test', 'GET');
    $response = new Response('response body', 200);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.100000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return null === $job->attributes['response_body'];
    });
});

it('uses content-length header when getContent returns false', function () {
    $request = Request::create('/test', 'GET');

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getContent')->andReturn(false);
    $response->headers = new ResponseHeaderBag([
        'Content-Length' => '1234',
        'Content-Type'   => 'application/octet-stream',
    ]);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:01.000000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 1234 === $job->attributes['response_size'];
    });
});

it('returns null size when content is false and no content-length header', function () {
    $request = Request::create('/test', 'GET');

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getContent')->andReturn(false);
    $response->headers = new ResponseHeaderBag([]);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:01.000000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return null === $job->attributes['response_size'];
    });
});

it('returns null response body when capture enabled but getContent returns false', function () {
    config(['kolaybi.request-tracer.incoming.capture_response_body' => true]);

    $request = Request::create('/test', 'GET');

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getContent')->andReturn(false);
    $response->headers = new ResponseHeaderBag([]);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.100000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return null === $job->attributes['response_body'];
    });
});

it('uses custom tenant column from config', function () {
    config(['kolaybi.request-tracer.tenant_column' => 'company_id']);

    $request = Request::create('/test', 'GET');
    $response = new Response('', 200);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.100000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return array_key_exists('company_id', $job->attributes);
    });
});

it('resolves context provider for tenant and user', function () {
    $provider = Mockery::mock(TraceContextProvider::class);
    $provider->shouldReceive('tenantId')->andReturn(42);
    $provider->shouldReceive('userId')->andReturn(7);
    $provider->shouldReceive('clientIp')->andReturn('10.0.0.1');
    $provider->shouldReceive('serverIdentifier')->andReturn('web-01');

    app()->instance(TraceContextProvider::class, $provider);

    $request = Request::create('/test', 'GET');
    $response = new Response('', 200);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.100000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 42 === $job->attributes['tenant_id']
            && 7 === $job->attributes['user_id']
            && 'web-01' === $job->attributes['server_identifier'];
    });
});

it('returns zero response size for zero Content-Length', function () {
    $request = Request::create('/test', 'GET');

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getContent')->andReturn(false);
    $response->headers = new ResponseHeaderBag([
        'Content-Length' => '0',
    ]);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.100000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 0 === $job->attributes['response_size'];
    });
});

it('returns null response size for non-numeric Content-Length', function () {
    $request = Request::create('/test', 'GET');

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getContent')->andReturn(false);
    $response->headers = new ResponseHeaderBag([
        'Content-Length' => 'not-a-number',
    ]);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.100000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return null === $job->attributes['response_size'];
    });
});

it('clamps negative Content-Length to zero', function () {
    $request = Request::create('/test', 'GET');

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getContent')->andReturn(false);
    $response->headers = new ResponseHeaderBag([
        'Content-Length' => '-5',
    ]);

    $recorder = new IncomingTraceRecorder();
    $recorder->record($request, $response, '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.100000');

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 0 === $job->attributes['response_size'];
    });
});
