<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

function seedOutgoing(array $attributes = []): OutgoingRequestTrace
{
    $trace = OutgoingRequestTrace::create(array_merge([
        'host'     => 'api.example.com',
        'path'     => '/v1/users',
        'method'   => 'GET',
        'status'   => 200,
        'duration' => 100,
    ], collect($attributes)->except('created_at')->all()));

    if (isset($attributes['created_at'])) {
        $trace->newQuery()->where('id', $trace->id)->update(['created_at' => $attributes['created_at']]);
    }

    return $trace;
}

function seedIncoming(array $attributes = []): IncomingRequestTrace
{
    $trace = IncomingRequestTrace::create(array_merge([
        'host'     => 'myapp.test',
        'path'     => '/dashboard',
        'method'   => 'GET',
        'status'   => 200,
        'duration' => 50,
    ], collect($attributes)->except('created_at')->all()));

    if (isset($attributes['created_at'])) {
        $trace->newQuery()->where('id', $trace->id)->update(['created_at' => $attributes['created_at']]);
    }

    return $trace;
}

it('shows warning when no traces match', function () {
    $this->artisan('request-tracer:search', ['--host' => 'nonexistent.com'])
        ->expectsOutputToContain('No traces found')
        ->assertExitCode(1);
});

it('lists all traces when no filters given', function () {
    seedOutgoing();
    seedIncoming();

    Artisan::call('request-tracer:search');
    $output = Artisan::output();

    expect($output)
        ->toContain('api.example.com')
        ->toContain('myapp.test');
});

it('filters by host', function () {
    seedOutgoing(['host' => 'api.example.com']);
    seedOutgoing(['host' => 'cdn.example.com']);

    Artisan::call('request-tracer:search', ['--host' => 'api.*']);
    $output = Artisan::output();

    expect($output)
        ->toContain('api.example.com')
        ->not->toContain('cdn.example.com');
});

it('filters by exact status code', function () {
    seedOutgoing(['status' => 200]);
    seedOutgoing(['status' => 500]);

    Artisan::call('request-tracer:search', ['--status' => '500']);
    $output = Artisan::output();

    expect($output)->toContain('500')
        ->and($output)->toMatch('/Results\s*\.+\s*1\b/');
});

it('filters by status range like 5xx', function () {
    seedOutgoing(['status' => 200]);
    seedOutgoing(['status' => 500]);
    seedOutgoing(['status' => 503]);

    Artisan::call('request-tracer:search', ['--status' => '5xx']);
    $output = Artisan::output();

    expect($output)->toMatch('/Results\s*\.+\s*2\b/');
});

it('filters by HTTP method', function () {
    seedOutgoing(['method' => 'GET']);
    seedOutgoing(['method' => 'POST']);

    Artisan::call('request-tracer:search', ['--method' => 'post']);
    $output = Artisan::output();

    expect($output)->toContain('POST')
        ->and($output)->toMatch('/Results\s*\.+\s*1\b/');
});

it('filters by path with wildcard', function () {
    seedOutgoing(['path' => '/v1/users']);
    seedOutgoing(['path' => '/v1/orders']);

    Artisan::call('request-tracer:search', ['--path' => '*/users']);
    $output = Artisan::output();

    expect($output)->toContain('/v1/users')
        ->and($output)->toMatch('/Results\s*\.+\s*1\b/');
});

it('filters by channel for outgoing traces', function () {
    seedOutgoing(['channel' => 'payments']);
    seedOutgoing(['channel' => 'notifications']);

    Artisan::call('request-tracer:search', ['--channel' => 'payments', '--type' => 'outgoing']);
    $output = Artisan::output();

    expect($output)->toContain('payments')
        ->not->toContain('notifications');
});

it('filters by date range', function () {
    seedOutgoing(['created_at' => '2026-01-01 00:00:00']);
    seedOutgoing(['created_at' => '2026-06-01 00:00:00']);

    Artisan::call('request-tracer:search', ['--from' => '2026-05-01', '--to' => '2026-07-01']);
    $output = Artisan::output();

    expect($output)->toMatch('/Results\s*\.+\s*1\b/');
});

it('filters by minimum duration', function () {
    seedOutgoing(['duration' => 50]);
    seedOutgoing(['duration' => 500]);

    Artisan::call('request-tracer:search', ['--min-duration' => '200']);
    $output = Artisan::output();

    expect($output)->toContain('500ms')
        ->and($output)->toMatch('/Results\s*\.+\s*1\b/');
});

it('filters by maximum duration', function () {
    seedOutgoing(['duration' => 50]);
    seedOutgoing(['duration' => 500]);

    Artisan::call('request-tracer:search', ['--max-duration' => '100']);
    $output = Artisan::output();

    expect($output)->toContain('50ms')
        ->and($output)->toMatch('/Results\s*\.+\s*1\b/');
});

it('filters by type outgoing', function () {
    seedOutgoing();
    seedIncoming();

    Artisan::call('request-tracer:search', ['--type' => 'outgoing']);
    $output = Artisan::output();

    expect($output)->toContain('OUTGOING')
        ->not->toContain('INCOMING');
});

it('filters by type incoming', function () {
    seedOutgoing();
    seedIncoming();

    Artisan::call('request-tracer:search', ['--type' => 'incoming']);
    $output = Artisan::output();

    expect($output)->toContain('INCOMING')
        ->not->toContain('OUTGOING');
});

it('respects --limit', function () {
    for ($i = 0; $i < 10; $i++) {
        seedOutgoing();
    }

    Artisan::call('request-tracer:search', ['--limit' => '3']);
    $output = Artisan::output();

    expect($output)->toMatch('/Results\s*\.+\s*3\b/');
});

it('combines multiple filters', function () {
    seedOutgoing(['host' => 'api.example.com', 'status' => 200, 'method' => 'GET']);
    seedOutgoing(['host' => 'api.example.com', 'status' => 500, 'method' => 'POST']);
    seedOutgoing(['host' => 'cdn.example.com', 'status' => 200, 'method' => 'GET']);

    Artisan::call('request-tracer:search', ['--host' => 'api.*', '--status' => '200']);
    $output = Artisan::output();

    expect($output)->toMatch('/Results\s*\.+\s*1\b/');
});

it('filters by channel for incoming traces', function () {
    seedIncoming(['channel' => 'mobile']);
    seedIncoming(['channel' => 'web']);

    Artisan::call('request-tracer:search', ['--channel' => 'mobile', '--type' => 'incoming']);
    $output = Artisan::output();

    expect($output)->toContain('mobile')
        ->and($output)->toMatch('/Results\s*\.+\s*1\b/');
});

it('shows channel column for incoming traces in search results', function () {
    seedIncoming(['channel' => 'jet']);

    Artisan::call('request-tracer:search', ['--type' => 'incoming']);
    $output = Artisan::output();

    expect($output)->toContain('jet');
});
