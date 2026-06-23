<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Tests\Fixtures\IntChannel;
use KolayBi\RequestTracer\Tests\Fixtures\PureChannel;
use KolayBi\RequestTracer\Tests\Fixtures\StringChannel;

beforeEach(fn() => Queue::fake());

it('registers traceOf method on Http client', function () {
    Http::fake(['*' => Http::response('ok')]);

    $response = Http::traceOf('payment')->get('https://example.com/api');

    expect($response->ok())->toBeTrue();
});

it('registers channel as alias of traceOf', function () {
    Http::fake(['*' => Http::response('ok')]);

    $response = Http::channel('payment')->get('https://example.com/api');

    expect($response->ok())->toBeTrue();
});

it('registers withTraceExtra method on Http client', function () {
    Http::fake(['*' => Http::response('ok')]);

    $response = Http::withTraceExtra(['invoice_id' => 123])->get('https://example.com/api');

    expect($response->ok())->toBeTrue();
});

it('passes null channel via traceOf', function () {
    Http::fake(['*' => Http::response('ok')]);

    $response = Http::traceOf(null)->get('https://example.com/api');

    expect($response->ok())->toBeTrue();
});

it('passes null extra via withTraceExtra', function () {
    Http::fake(['*' => Http::response('ok')]);

    $response = Http::withTraceExtra(null)->get('https://example.com/api');

    expect($response->ok())->toBeTrue();
});

it('stores a string-backed enum channel as its value', function () {
    Context::add('trace_id', 'test-trace-id');
    Http::fake(['*' => Http::response('ok')]);

    Http::traceOf(StringChannel::EInvoice)->get('https://example.com/api');

    Queue::assertPushed(StoreTraceJob::class, fn(StoreTraceJob $job) => 'e-invoice' === $job->attributes['channel']);
});

it('stores an int-backed enum channel as its value', function () {
    Context::add('trace_id', 'test-trace-id');
    Http::fake(['*' => Http::response('ok')]);

    Http::channel(IntChannel::EInvoice)->get('https://example.com/api');

    Queue::assertPushed(StoreTraceJob::class, fn(StoreTraceJob $job) => '2' === $job->attributes['channel']);
});

it('stores a pure unit enum channel as its case name', function () {
    Context::add('trace_id', 'test-trace-id');
    Http::fake(['*' => Http::response('ok')]);

    Http::traceOf(PureChannel::Payment)->get('https://example.com/api');

    Queue::assertPushed(StoreTraceJob::class, fn(StoreTraceJob $job) => 'Payment' === $job->attributes['channel']);
});
