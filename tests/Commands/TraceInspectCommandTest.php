<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

it('shows error when trace is not found', function () {
    $this->artisan('request-tracer:inspect', ['id' => '01jn2xk8r4m5yg9w7q3h0t6v'])
        ->expectsOutputToContain('Trace not found')
        ->assertExitCode(1);
});

it('inspects an outgoing trace by id', function () {
    $trace = OutgoingRequestTrace::create([
        'method'     => 'GET',
        'host'       => 'api.example.com',
        'path'       => '/users',
        'status'     => 200,
        'duration'   => 150,
        'start'      => '2026-01-01 00:00:00.000',
        'end'        => '2026-01-01 00:00:00.150',
        'protocol'   => 'https',
        'channel'    => 'payment',
        'trace_id'   => 'test-trace',
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id])
        ->expectsOutputToContain('GET')
        ->expectsOutputToContain('OUTGOING')
        ->assertExitCode(0);
});

it('inspects an incoming trace when outgoing not found', function () {
    $trace = IncomingRequestTrace::create([
        'method'     => 'POST',
        'host'       => 'localhost',
        'path'       => '/api/webhook',
        'status'     => 201,
        'duration'   => 50,
        'start'      => '2026-01-01 00:00:00.000',
        'end'        => '2026-01-01 00:00:00.050',
        'trace_id'   => 'incoming-trace',
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id])
        ->expectsOutputToContain('POST')
        ->expectsOutputToContain('INCOMING')
        ->assertExitCode(0);
});

it('shows headers at verbosity level -v', function () {
    $trace = OutgoingRequestTrace::create([
        'method'           => 'GET',
        'host'             => 'example.com',
        'status'           => 200,
        'request_headers'  => json_encode(['Content-Type' => 'application/json']),
        'response_headers' => json_encode(['X-Request-Id' => 'abc']),
        'created_at'       => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id, '-v' => true])
        ->expectsOutputToContain('Content-Type')
        ->assertExitCode(0);
});

it('shows body at verbosity level -vv', function () {
    $trace = OutgoingRequestTrace::create([
        'method'        => 'POST',
        'host'          => 'example.com',
        'status'        => 200,
        'request_body'  => '{"name":"test"}',
        'response_body' => '{"id":1}',
        'created_at'    => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id, '-vv' => true])
        ->expectsOutputToContain('name')
        ->assertExitCode(0);
});

it('handles raw string headers (non-JSON)', function () {
    $trace = OutgoingRequestTrace::create([
        'method'           => 'POST',
        'host'             => 'soap.example.com',
        'status'           => 200,
        'request_headers'  => "Content-Type: text/xml\r\nHost: soap.example.com",
        'response_headers' => "HTTP/1.1 200 OK\r\nContent-Type: text/xml",
        'created_at'       => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id, '-v' => true])
        ->expectsOutputToContain('Content-Type')
        ->assertExitCode(0);
});

it('handles null status and null sizes', function () {
    $trace = OutgoingRequestTrace::create([
        'method'     => 'GET',
        'host'       => 'example.com',
        'status'     => null,
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id])
        ->assertExitCode(0);
});

it('renders body truncation at -vv verbosity', function () {
    $longJson = json_encode(array_fill(0, 50, ['key' => 'value']));

    $trace = OutgoingRequestTrace::create([
        'method'        => 'POST',
        'host'          => 'example.com',
        'status'        => 200,
        'request_body'  => $longJson,
        'response_body' => $longJson,
        'created_at'    => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id, '-vv' => true])
        ->expectsOutputToContain('truncated')
        ->assertExitCode(0);
});

it('shows null body placeholder at -vv verbosity', function () {
    $trace = OutgoingRequestTrace::create([
        'method'     => 'GET',
        'host'       => 'example.com',
        'status'     => 200,
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id, '-vv' => true])
        ->assertExitCode(0);
});

it('formats status below 200 with white color', function () {
    $trace = OutgoingRequestTrace::create([
        'method'        => 'GET',
        'host'          => 'example.com',
        'status'        => 100,
        'request_size'  => 1024,
        'response_size' => 2048,
        'created_at'    => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id])
        ->expectsOutputToContain('1,024 bytes')
        ->assertExitCode(0);
});

it('shows extras at -vvv verbosity without --full flag', function () {
    $trace = OutgoingRequestTrace::create([
        'method'     => 'POST',
        'host'       => 'example.com',
        'status'     => 500,
        'exception'  => 'RuntimeException',
        'message'    => 'Connection timed out',
        'stats'      => '{"total_time":1.2}',
        'extra'      => '{"retry":true}',
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id, '-vvv' => true])
        ->expectsOutputToContain('RuntimeException')
        ->expectsOutputToContain('Connection timed out')
        ->assertExitCode(0);
});

it('inspects a trace in a rotated archive table', function () {
    $outgoingTable = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');

    $archiveTable = "{$outgoingTable}_20260301";
    $connection->statement("CREATE TABLE \"{$archiveTable}\" AS SELECT * FROM \"{$outgoingTable}\" WHERE 0");

    $connection->table($archiveTable)->insert([
        'id'         => '01JARCHIVED00000000000001',
        'method'     => 'GET',
        'host'       => 'archived.example.com',
        'path'       => '/old-endpoint',
        'status'     => 200,
        'duration'   => 75,
        'created_at' => '2026-03-01 12:00:00',
    ]);

    $this->artisan('request-tracer:inspect', ['id' => '01JARCHIVED00000000000001'])
        ->expectsOutputToContain('OUTGOING')
        ->expectsOutputToContain('archived.example.com')
        ->assertExitCode(0);
});

it('shows full output with --full flag', function () {
    $trace = OutgoingRequestTrace::create([
        'method'           => 'POST',
        'host'             => 'example.com',
        'status'           => 500,
        'request_body'     => '{"name":"test"}',
        'response_body'    => '{"error":"fail"}',
        'request_headers'  => json_encode(['Authorization' => 'Bearer token']),
        'response_headers' => json_encode(['Content-Type' => 'application/json']),
        'exception'        => 'SomeException at line 42',
        'message'          => 'Something went wrong',
        'stats'            => '{"total_time":0.5}',
        'extra'            => '{"invoice_id":123}',
        'created_at'       => now(),
    ]);

    $this->artisan('request-tracer:inspect', ['id' => $trace->id, '--full' => true])
        ->expectsOutputToContain('Something went wrong')
        ->expectsOutputToContain('SomeException')
        ->assertExitCode(0);
});

it('shows channel for incoming traces', function () {
    $trace = IncomingRequestTrace::create([
        'host'    => 'myapp.test',
        'path'    => '/api/test',
        'method'  => 'GET',
        'status'  => 200,
        'channel' => 'mobile',
    ]);

    Artisan::call('request-tracer:inspect', ['id' => $trace->id]);
    $output = Artisan::output();

    expect($output)
        ->toContain('Channel')
        ->toContain('mobile');
});
