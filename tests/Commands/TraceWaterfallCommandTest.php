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

it('renders dash for total duration when start/end is null', function () {
    OutgoingRequestTrace::create([
        'trace_id'   => 'null-timing-trace',
        'method'     => 'GET',
        'host'       => 'api.example.com',
        'path'       => '/health',
        'status'     => 200,
        'start'      => null,
        'end'        => null,
        'duration'   => null,
        'protocol'   => 'https',
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:waterfall', ['trace_id' => 'null-timing-trace'])
        ->expectsOutputToContain('—')
        ->assertExitCode(0);
});

it('renders dash for null duration in row', function () {
    OutgoingRequestTrace::create([
        'trace_id'   => 'no-duration-trace',
        'method'     => 'POST',
        'host'       => 'api.example.com',
        'path'       => '/timeout',
        'status'     => 0,
        'start'      => '2026-01-01 00:00:00.000',
        'end'        => null,
        'duration'   => null,
        'protocol'   => 'https',
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:waterfall', ['trace_id' => 'no-duration-trace'])
        ->expectsOutputToContain('—')
        ->assertExitCode(0);
});

it('finds traces in rotated archive tables', function () {
    $outgoingTable = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');

    // Simulate rotation: create archive table and move a trace into it
    $archiveTable = "{$outgoingTable}_20260301";
    $connection->statement("CREATE TABLE \"{$archiveTable}\" AS SELECT * FROM \"{$outgoingTable}\" WHERE 0");

    $connection->table($archiveTable)->insert([
        'id'         => '01JARCHIVED00000000000001',
        'trace_id'   => 'archived-trace',
        'method'     => 'GET',
        'host'       => 'old.example.com',
        'path'       => '/archived',
        'status'     => 200,
        'duration'   => 100,
        'start'      => '2026-03-01 12:00:00.000',
        'end'        => '2026-03-01 12:00:00.100',
        'created_at' => '2026-03-01 12:00:00',
    ]);

    $this->artisan('request-tracer:waterfall', ['trace_id' => 'archived-trace'])
        ->expectsOutputToContain('archived-trace')
        ->expectsOutputToContain('old.example.com')
        ->assertExitCode(0);
});

it('combines current and archived traces in waterfall', function () {
    $outgoingTable = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');

    // Current table trace
    OutgoingRequestTrace::create([
        'trace_id'   => 'split-trace',
        'method'     => 'POST',
        'host'       => 'current.example.com',
        'path'       => '/new',
        'status'     => 201,
        'start'      => '2026-03-09 12:00:00.000',
        'end'        => '2026-03-09 12:00:00.200',
        'created_at' => now(),
    ]);

    // Archived trace with same trace_id
    $archiveTable = "{$outgoingTable}_20260308";
    $connection->statement("CREATE TABLE \"{$archiveTable}\" AS SELECT * FROM \"{$outgoingTable}\" WHERE 0");

    $connection->table($archiveTable)->insert([
        'id'         => '01JARCHIVED00000000000002',
        'trace_id'   => 'split-trace',
        'method'     => 'GET',
        'host'       => 'archived.example.com',
        'path'       => '/old',
        'status'     => 200,
        'start'      => '2026-03-08 12:00:00.000',
        'end'        => '2026-03-08 12:00:00.100',
        'created_at' => '2026-03-08 12:00:00',
    ]);

    $this->artisan('request-tracer:waterfall', ['trace_id' => 'split-trace'])
        ->expectsOutputToContain('current.example.com')
        ->expectsOutputToContain('archived.example.com')
        ->assertExitCode(0);
});

it('sorts traces chronologically across mixed types', function () {
    // Create outgoing trace with later start time first
    OutgoingRequestTrace::create([
        'trace_id'   => 'sort-trace',
        'method'     => 'GET',
        'host'       => 'second.com',
        'path'       => '/b',
        'status'     => 200,
        'duration'   => 50,
        'start'      => '2026-01-01 00:00:01.000',
        'end'        => '2026-01-01 00:00:01.050',
        'protocol'   => 'https',
        'created_at' => now(),
    ]);

    // Create incoming trace with earlier start time
    IncomingRequestTrace::create([
        'trace_id'   => 'sort-trace',
        'method'     => 'POST',
        'host'       => 'first.com',
        'path'       => '/a',
        'status'     => 200,
        'duration'   => 100,
        'start'      => '2026-01-01 00:00:00.000',
        'end'        => '2026-01-01 00:00:00.100',
        'created_at' => now(),
    ]);

    $this->artisan('request-tracer:waterfall', ['trace_id' => 'sort-trace'])
        ->expectsOutputToContain('INCOMING')
        ->expectsOutputToContain('OUTGOING')
        ->assertExitCode(0);
});

it('shows channel for incoming traces in waterfall', function () {
    $traceId = 'test-trace-wf-channel';

    IncomingRequestTrace::create([
        'trace_id' => $traceId,
        'host'     => 'myapp.test',
        'path'     => '/api/test',
        'method'   => 'GET',
        'status'   => 200,
        'channel'  => 'mobile',
        'start'    => '2026-01-01 00:00:00.000',
        'end'      => '2026-01-01 00:00:00.100',
        'duration' => 100,
    ]);

    Artisan::call('request-tracer:waterfall', ['trace_id' => $traceId]);
    $output = Artisan::output();

    expect($output)->toContain('mobile');
});
