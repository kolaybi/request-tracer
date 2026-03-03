<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Listeners\Http\ResponseReceivedListener;

beforeEach(function () {
    Queue::fake();
    Context::add('trace_id', 'test-trace-id');
});

it('records outgoing response trace', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('POST', 'https://api.example.com/users', ['Content-Type' => 'application/json'], '{"name":"John"}');
    $request = new Request($psrRequest);
    $psrResponse = new Psr7Response(200, ['Content-Type' => 'application/json'], '{"id":1}');
    $response = new Response($psrResponse);

    $event = new ResponseReceived($request, $response);

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'POST' === $job->attributes['method']
            && 'api.example.com' === $job->attributes['host']
            && '/users' === $job->attributes['path']
            && 200 === $job->attributes['status']
            && 'test-trace-id' === $job->attributes['trace_id']
            && 'https' === $job->attributes['protocol'];
    });
});

it('extracts channel from request attributes', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);
    $request->setRequestAttributes(['request_tracer' => ['channel' => 'payment-gateway']]);
    $psrResponse = new Psr7Response(200, [], 'ok');
    $response = new Response($psrResponse);

    $event = new ResponseReceived($request, $response);

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'payment-gateway' === $job->attributes['channel'];
    });
});

it('extracts extra from request attributes', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);
    $request->setRequestAttributes(['request_tracer' => ['extra' => ['invoice_id' => 123]]]);
    $psrResponse = new Psr7Response(200);
    $response = new Response($psrResponse);

    $event = new ResponseReceived($request, $response);

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return '{"invoice_id":123}' === $job->attributes['extra'];
    });
});

it('strips X-Trace headers from request and response', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com', ['X-Trace-Custom' => 'val', 'Accept' => 'text/html']);
    $request = new Request($psrRequest);
    $psrResponse = new Psr7Response(200, ['X-Trace-Something' => 'val2', 'Content-Type' => 'text/html']);
    $response = new Response($psrResponse);

    $event = new ResponseReceived($request, $response);

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        $reqHeaders = json_decode($job->attributes['request_headers'], true);
        $resHeaders = json_decode($job->attributes['response_headers'], true);

        return !isset($reqHeaders['X-Trace-Custom'])
            && isset($reqHeaders['Accept'])
            && !isset($resHeaders['X-Trace-Something'])
            && isset($resHeaders['Content-Type']);
    });
});

it('respects sample rate of 0 and drops trace', function () {
    config(['kolaybi.request-tracer.outgoing.sample_rate' => 0.0]);

    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);
    $psrResponse = new Psr7Response(200);
    $response = new Response($psrResponse);

    $event = new ResponseReceived($request, $response);

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertNotPushed(StoreTraceJob::class);
});
