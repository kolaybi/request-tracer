<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
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

it('captures response body, size, and headers correctly', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://api.example.com/data');
    $request = new Request($psrRequest);
    $responseBody = '{"items":[1,2,3]}';
    $psrResponse = new Psr7Response(200, ['X-Request-Id' => 'abc', 'Content-Type' => 'application/json'], $responseBody);
    $response = new Response($psrResponse);

    $event = new ResponseReceived($request, $response);

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) use ($responseBody) {
        return $responseBody === $job->attributes['response_body']
            && strlen($responseBody) === $job->attributes['response_size']
            && null !== $job->attributes['response_headers'];
    });
});

it('records handler stats as JSON', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://api.example.com/stats');
    $request = new Request($psrRequest);
    $psrResponse = new Psr7Response(200, [], 'ok');
    $response = new Response($psrResponse);

    $event = new ResponseReceived($request, $response);

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        // stats should be a valid JSON string (even if empty object/array)
        return null !== $job->attributes['stats']
            && null !== json_decode($job->attributes['stats']);
    });
});

it('masks sensitive headers in handler stats request_header when enabled', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'authorization',
    ]);

    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://api.example.com/stats');
    $request = new Request($psrRequest);
    $psrResponse = new Psr7Response(200, [], 'ok');
    $response = new Response($psrResponse);
    $response->transferStats = new TransferStats($psrRequest, $psrResponse, 0.5, null, [
        'total_time'     => 0.5,
        'request_header' => "GET /stats HTTP/1.1\r\nHost: api.example.com\r\nAuthorization: Bearer secret-token\r\nAccept: application/json\r\n\r\n",
    ]);

    $listener = new ResponseReceivedListener();
    $listener->handle(new ResponseReceived($request, $response));

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        $stats = json_decode($job->attributes['stats'], true);

        return "GET /stats HTTP/1.1\r\nHost: api.example.com\r\nAuthorization: [REDACTED]\r\nAccept: application/json\r\n\r\n" === $stats['request_header']
            && 0.5 === $stats['total_time'];
    });
});

it('masks sensitive query params in handler stats url when enabled', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'access_token',
    ]);

    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://api.example.com/stats?access_token=secret-token&page=2');
    $request = new Request($psrRequest);
    $psrResponse = new Psr7Response(200, [], 'ok');
    $response = new Response($psrResponse);
    $response->transferStats = new TransferStats($psrRequest, $psrResponse, 0.5, null, [
        'url' => 'https://api.example.com/stats?access_token=secret-token&page=2',
    ]);

    $listener = new ResponseReceivedListener();
    $listener->handle(new ResponseReceived($request, $response));

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        $stats = json_decode($job->attributes['stats'], true);

        return 'https://api.example.com/stats?access_token=%5BREDACTED%5D&page=2' === $stats['url'];
    });
});
