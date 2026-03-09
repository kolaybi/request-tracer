<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

function seedTailOutgoing(array $attributes = []): OutgoingRequestTrace
{
    return OutgoingRequestTrace::create(array_merge([
        'host'     => 'api.example.com',
        'path'     => '/v1/users',
        'method'   => 'GET',
        'status'   => 200,
        'duration' => 100,
    ], $attributes));
}

function seedTailIncoming(array $attributes = []): IncomingRequestTrace
{
    return IncomingRequestTrace::create(array_merge([
        'host'     => 'myapp.test',
        'path'     => '/dashboard',
        'method'   => 'GET',
        'status'   => 200,
        'duration' => 50,
    ], $attributes));
}

it('displays startup banner with default interval', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1])
        ->expectsOutputToContain('Tailing traces every 2s')
        ->assertExitCode(0);
});

it('does not show pre-existing outgoing traces', function () {
    seedTailOutgoing(['host' => 'old.example.com']);

    Artisan::call('request-tracer:tail', ['--max-polls' => 1]);
    $output = Artisan::output();

    expect($output)
        ->toContain('Tailing traces')
        ->not->toContain('old.example.com');
});

it('does not show pre-existing incoming traces', function () {
    seedTailIncoming(['host' => 'old-app.test']);

    Artisan::call('request-tracer:tail', ['--max-polls' => 1]);
    $output = Artisan::output();

    expect($output)
        ->toContain('Tailing traces')
        ->not->toContain('old-app.test');
});

it('accepts type outgoing filter', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing'])
        ->assertExitCode(0);
});

it('accepts type incoming filter', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--type' => 'incoming'])
        ->assertExitCode(0);
});

it('accepts host filter', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--host' => 'api.*'])
        ->assertExitCode(0);
});

it('accepts status filter with exact code', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--status' => '500'])
        ->assertExitCode(0);
});

it('accepts status filter with range', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--status' => '5xx'])
        ->assertExitCode(0);
});

it('accepts channel filter', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--channel' => 'payments'])
        ->assertExitCode(0);
});

it('clamps interval minimum to 1 second', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--interval' => 0])
        ->expectsOutputToContain('every 1s')
        ->assertExitCode(0);
});

it('clamps interval maximum to 60 seconds', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--interval' => 999])
        ->expectsOutputToContain('every 60s')
        ->assertExitCode(0);
});

it('skips incoming cursor when type is outgoing', function () {
    seedTailIncoming();

    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing']);
    $output = Artisan::output();

    expect($output)->not->toContain('myapp.test');
});

it('skips outgoing cursor when type is incoming', function () {
    seedTailOutgoing();

    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'incoming']);
    $output = Artisan::output();

    expect($output)->not->toContain('api.example.com');
});

it('exits after max-polls iterations', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1])
        ->assertExitCode(0);
});

it('uses custom interval from option', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--interval' => 5])
        ->expectsOutputToContain('every 5s')
        ->assertExitCode(0);
});

it('handles high-volume pre-existing traces without rendering them', function () {
    $connection = DB::connection('testing');
    $now = now();

    $outgoingRows = [];
    $incomingRows = [];

    for ($i = 0; $i < 1500; $i++) {
        $outgoingRows[] = [
            'id'         => (string) Str::ulid(),
            'host'       => "bulk-out-{$i}.example.com",
            'path'       => '/bulk',
            'method'     => 'GET',
            'status'     => 200,
            'duration'   => 1,
            'created_at' => $now,
        ];

        $incomingRows[] = [
            'id'         => (string) Str::ulid(),
            'host'       => "bulk-in-{$i}.test",
            'path'       => '/bulk',
            'method'     => 'GET',
            'status'     => 200,
            'duration'   => 1,
            'created_at' => $now,
        ];
    }

    foreach (array_chunk($outgoingRows, 100) as $chunk) {
        $connection->table(config('kolaybi.request-tracer.outgoing.table'))->insert($chunk);
    }

    foreach (array_chunk($incomingRows, 100) as $chunk) {
        $connection->table(config('kolaybi.request-tracer.incoming.table'))->insert($chunk);
    }

    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--interval' => 1]);
    $output = Artisan::output();

    expect($output)
        ->toContain('Tailing traces every 1s')
        ->not->toContain('bulk-out-0.example.com')
        ->not->toContain('bulk-in-0.test');
});
