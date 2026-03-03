<?php

use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request;
use KolayBi\RequestTracer\Listeners\Http\RequestSendingListener;
use KolayBi\RequestTracer\Support\RequestTimingStore;

it('stamps request timing on request sending event', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);

    $event = new RequestSending($request);

    $listener = new RequestSendingListener();
    $listener->handle($event);

    $pulled = RequestTimingStore::pull($psrRequest);

    expect($pulled)->toBeString()
        ->and($pulled)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/');
});

it('uses existing started_at from request attributes', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);

    // Set existing started_at via attributes
    $request->setRequestAttributes(['request_tracer' => ['started_at' => '2025-06-01 00:00:00.000000']]);

    $event = new RequestSending($request);

    $listener = new RequestSendingListener();
    $listener->handle($event);

    $pulled = RequestTimingStore::pull($psrRequest);

    expect($pulled)->toBe('2025-06-01 00:00:00.000000');
});

it('generates started_at when trace attributes are empty', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);
    $request->setRequestAttributes(['request_tracer' => []]);

    $event = new RequestSending($request);

    $listener = new RequestSendingListener();
    $listener->handle($event);

    expect(RequestTimingStore::pull($psrRequest))->toBeString();
});

it('handles non-array request_tracer attribute gracefully', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);
    $request->setRequestAttributes(['request_tracer' => 'invalid-string']);

    $event = new RequestSending($request);

    $listener = new RequestSendingListener();
    $listener->handle($event);

    expect(RequestTimingStore::pull($psrRequest))->toBeString();
});
