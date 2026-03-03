<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

it('warns when no traces found', function () {
    $this->artisan('request-tracer:waterfall', ['trace_id' => 'nonexistent-trace'])
        ->expectsOutputToContain('No traces found')
        ->assertExitCode(1);
});

it('displays waterfall of outgoing traces', function () {
    OutgoingRequestTrace::create([
        'trace_id'   => 'waterfall-trace',
        'method'     => 'GET',
        'host'       => 'api.example.com',
        'path'       => '/users',
        'status'     => 200,
        'duration'   => 100,
        'start'      => '2026-01-01 00:00:00.000',
        'end'        => '2026-01-01 00:00:00.100',
        'channel'    => 'api',
        'protocol'   => 'https',
        'created_at' => now(),
    ]);

    OutgoingRequestTrace::create([
        'trace_id'   => 'waterfall-trace',
        'method'     => 'POST',
        'host'       => 'api.example.com',
        'path'       => '/log',
        'status'     => 201,
        'duration'   => 50,
        'start'      => '2026-01-01 00:00:00.100',
        'end'        => '2026-01-01 00:00:00.150',
        'channel'    => 'logging',
        'protocol'   => 'https',
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:waterfall', ['trace_id' => 'waterfall-trace'])
        ->expectsOutputToContain('waterfall-trace')
        ->expectsOutputToContain('GET')
        ->expectsOutputToContain('POST')
        ->assertExitCode(0);
});

it('combines incoming and outgoing traces', function () {
    IncomingRequestTrace::create([
        'trace_id'   => 'mixed-trace',
        'method'     => 'POST',
        'host'       => 'localhost',
        'path'       => '/api/webhook',
        'status'     => 200,
        'duration'   => 500,
        'start'      => '2026-01-01 00:00:00.000',
        'end'        => '2026-01-01 00:00:00.500',
        'created_at' => now(),
    ]);

    OutgoingRequestTrace::create([
        'trace_id'   => 'mixed-trace',
        'method'     => 'GET',
        'host'       => 'external.api.com',
        'path'       => '/data',
        'status'     => 200,
        'duration'   => 200,
        'start'      => '2026-01-01 00:00:00.050',
        'end'        => '2026-01-01 00:00:00.250',
        'protocol'   => 'https',
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:waterfall', ['trace_id' => 'mixed-trace'])
        ->expectsOutputToContain('INCOMING')
        ->expectsOutputToContain('OUTGOING')
        ->assertExitCode(0);
});
