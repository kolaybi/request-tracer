<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

it('shows error when first trace is not found', function () {
    $trace = OutgoingRequestTrace::create(['host' => 'a.com', 'method' => 'GET', 'status' => 200]);

    $this->artisan('request-tracer:diff', ['id1' => 'nonexistent', 'id2' => $trace->id])
        ->expectsOutputToContain('Trace not found: nonexistent')
        ->assertExitCode(1);
});

it('shows error when second trace is not found', function () {
    $trace = OutgoingRequestTrace::create(['host' => 'a.com', 'method' => 'GET', 'status' => 200]);

    $this->artisan('request-tracer:diff', ['id1' => $trace->id, 'id2' => 'nonexistent'])
        ->expectsOutputToContain('Trace not found: nonexistent')
        ->assertExitCode(1);
});

it('compares two identical outgoing traces', function () {
    $t1 = OutgoingRequestTrace::create(['host' => 'api.example.com', 'method' => 'GET', 'status' => 200, 'duration' => 100]);
    $t2 = OutgoingRequestTrace::create(['host' => 'api.example.com', 'method' => 'GET', 'status' => 200, 'duration' => 100]);

    Artisan::call('request-tracer:diff', ['id1' => $t1->id, 'id2' => $t2->id]);
    $output = Artisan::output();

    expect($output)
        ->toContain('OUTGOING')
        ->toContain('GET')
        ->not->toContain('→');
});

it('highlights differences between traces', function () {
    $t1 = OutgoingRequestTrace::create(['host' => 'api.example.com', 'method' => 'GET', 'status' => 200, 'duration' => 100]);
    $t2 = OutgoingRequestTrace::create(['host' => 'api.example.com', 'method' => 'POST', 'status' => 500, 'duration' => 2000]);

    Artisan::call('request-tracer:diff', ['id1' => $t1->id, 'id2' => $t2->id]);
    $output = Artisan::output();

    expect($output)->toContain('→');
});

it('compares incoming and outgoing traces', function () {
    $t1 = OutgoingRequestTrace::create(['host' => 'api.example.com', 'method' => 'GET', 'status' => 200]);
    $t2 = IncomingRequestTrace::create(['host' => 'myapp.test', 'method' => 'POST', 'status' => 201]);

    Artisan::call('request-tracer:diff', ['id1' => $t1->id, 'id2' => $t2->id]);
    $output = Artisan::output();

    expect($output)
        ->toContain('OUTGOING')
        ->toContain('INCOMING');
});

it('shows header diff at -v verbosity', function () {
    $t1 = OutgoingRequestTrace::create([
        'host'             => 'api.example.com',
        'method'           => 'GET',
        'status'           => 200,
        'request_headers'  => json_encode(['Content-Type' => ['application/json'], 'Authorization' => ['Bearer abc']]),
        'response_headers' => json_encode(['X-Request-Id' => ['123']]),
    ]);
    $t2 = OutgoingRequestTrace::create([
        'host'             => 'api.example.com',
        'method'           => 'GET',
        'status'           => 200,
        'request_headers'  => json_encode(['Content-Type' => ['text/html']]),
        'response_headers' => json_encode(['X-Request-Id' => ['456']]),
    ]);

    Artisan::call('request-tracer:diff', ['id1' => $t1->id, 'id2' => $t2->id, '-v' => true]);
    $output = Artisan::output();

    expect($output)
        ->toContain('Request Headers')
        ->toContain('Response Headers');
});

it('shows body diff at -vv verbosity', function () {
    $t1 = OutgoingRequestTrace::create([
        'host'          => 'api.example.com',
        'method'        => 'POST',
        'status'        => 200,
        'request_body'  => '{"name":"Alice"}',
        'response_body' => '{"ok":true}',
    ]);
    $t2 = OutgoingRequestTrace::create([
        'host'          => 'api.example.com',
        'method'        => 'POST',
        'status'        => 200,
        'request_body'  => '{"name":"Bob"}',
        'response_body' => '{"ok":false}',
    ]);

    Artisan::call('request-tracer:diff', ['id1' => $t1->id, 'id2' => $t2->id, '-vv' => true]);
    $output = Artisan::output();

    expect($output)
        ->toContain('Request Body')
        ->toContain('Response Body')
        ->toContain('Similarity');
});

it('shows identical marker for matching bodies', function () {
    $t1 = OutgoingRequestTrace::create([
        'host'          => 'api.example.com',
        'method'        => 'GET',
        'status'        => 200,
        'request_body'  => 'same body',
        'response_body' => 'same response',
    ]);
    $t2 = OutgoingRequestTrace::create([
        'host'          => 'api.example.com',
        'method'        => 'GET',
        'status'        => 200,
        'request_body'  => 'same body',
        'response_body' => 'same response',
    ]);

    Artisan::call('request-tracer:diff', ['id1' => $t1->id, 'id2' => $t2->id, '-vv' => true]);
    $output = Artisan::output();

    expect($output)->toContain('(identical)');
});

it('shows no differences marker for identical headers', function () {
    $headers = json_encode(['Content-Type' => ['application/json']]);

    $t1 = OutgoingRequestTrace::create([
        'host'            => 'a.com', 'method' => 'GET', 'status' => 200,
        'request_headers' => $headers, 'response_headers' => $headers,
    ]);
    $t2 = OutgoingRequestTrace::create([
        'host'            => 'a.com', 'method' => 'GET', 'status' => 200,
        'request_headers' => $headers, 'response_headers' => $headers,
    ]);

    Artisan::call('request-tracer:diff', ['id1' => $t1->id, 'id2' => $t2->id, '-v' => true]);
    $output = Artisan::output();

    expect($output)->toContain('(no differences)');
});
