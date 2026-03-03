<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
