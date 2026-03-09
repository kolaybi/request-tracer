<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

function createOutgoing(array $attributes = []): OutgoingRequestTrace
{
    $trace = OutgoingRequestTrace::create(array_merge([
        'host'     => 'api.example.com',
        'method'   => 'GET',
        'status'   => 200,
        'duration' => 100,
    ], collect($attributes)->except('created_at')->all()));

    if (isset($attributes['created_at'])) {
        $trace->newQuery()->where('id', $trace->id)->update(['created_at' => $attributes['created_at']]);
    }

    return $trace;
}

function createIncoming(array $attributes = []): IncomingRequestTrace
{
    $trace = IncomingRequestTrace::create(array_merge([
        'host'     => 'myapp.test',
        'method'   => 'GET',
        'status'   => 200,
        'duration' => 50,
    ], collect($attributes)->except('created_at')->all()));

    if (isset($attributes['created_at'])) {
        $trace->newQuery()->where('id', $trace->id)->update(['created_at' => $attributes['created_at']]);
    }

    return $trace;
}

it('shows no traces message when tables are empty', function () {
    $this->artisan('request-tracer:stats')
        ->expectsOutputToContain('No traces')
        ->assertExitCode(0);
});

it('displays outgoing stats', function () {
    createOutgoing(['status' => 200, 'duration' => 100]);
    createOutgoing(['status' => 200, 'duration' => 200]);
    createOutgoing(['status' => 500, 'duration' => 300]);

    Artisan::call('request-tracer:stats');
    $output = Artisan::output();

    expect($output)
        ->toContain('Outgoing')
        ->toContain('Total Requests')
        ->toContain('3')
        ->toContain('2xx Success')
        ->toContain('5xx Server Errors');
});

it('displays incoming stats', function () {
    createIncoming(['status' => 200, 'duration' => 50]);
    createIncoming(['status' => 404, 'duration' => 30]);

    Artisan::call('request-tracer:stats');
    $output = Artisan::output();

    expect($output)
        ->toContain('Incoming')
        ->toContain('Total Requests')
        ->toContain('4xx Client Errors');
});

it('filters by --type=outgoing', function () {
    createOutgoing();
    createIncoming();

    Artisan::call('request-tracer:stats', ['--type' => 'outgoing']);
    $output = Artisan::output();

    expect($output)
        ->toContain('Outgoing')
        ->not->toContain('Incoming');
});

it('filters by --type=incoming', function () {
    createOutgoing();
    createIncoming();

    Artisan::call('request-tracer:stats', ['--type' => 'incoming']);
    $output = Artisan::output();

    expect($output)
        ->toContain('Incoming')
        ->not->toContain('Outgoing');
});

it('respects --hours time window', function () {
    createOutgoing(['created_at' => now()->subHours(2)]);
    createOutgoing(['created_at' => now()->subHours(48)]);

    Artisan::call('request-tracer:stats', ['--hours' => 6, '--type' => 'outgoing']);
    $output = Artisan::output();

    expect($output)->toContain('Total Requests');
    // Only 1 trace within the 6-hour window
    expect($output)->toMatch('/Total Requests\s*\.+\s*1\b/');
});

it('shows top hosts breakdown', function () {
    createOutgoing(['host' => 'api.example.com']);
    createOutgoing(['host' => 'api.example.com']);
    createOutgoing(['host' => 'cdn.example.com']);

    Artisan::call('request-tracer:stats', ['--type' => 'outgoing']);
    $output = Artisan::output();

    expect($output)
        ->toContain('Top Hosts')
        ->toContain('api.example.com')
        ->toContain('cdn.example.com');
});

it('shows top channels for outgoing traces', function () {
    createOutgoing(['channel' => 'payments']);
    createOutgoing(['channel' => 'payments']);
    createOutgoing(['channel' => 'notifications']);

    Artisan::call('request-tracer:stats', ['--type' => 'outgoing']);
    $output = Artisan::output();

    expect($output)
        ->toContain('Top Channels')
        ->toContain('payments')
        ->toContain('notifications');
});

it('shows duration stats', function () {
    createOutgoing(['duration' => 100]);
    createOutgoing(['duration' => 300]);

    Artisan::call('request-tracer:stats', ['--type' => 'outgoing']);
    $output = Artisan::output();

    expect($output)
        ->toContain('Avg Duration')
        ->toContain('200ms')
        ->toContain('Min Duration')
        ->toContain('100ms')
        ->toContain('Max Duration')
        ->toContain('300ms');
});

it('calculates error rate from 5xx responses', function () {
    createOutgoing(['status' => 200]);
    createOutgoing(['status' => 200]);
    createOutgoing(['status' => 200]);
    createOutgoing(['status' => 500]);

    Artisan::call('request-tracer:stats', ['--type' => 'outgoing']);
    $output = Artisan::output();

    expect($output)
        ->toContain('Error Rate (5xx)')
        ->toContain('25%');
});

it('enforces minimum 1 hour window', function () {
    createOutgoing();

    $this->artisan('request-tracer:stats', ['--hours' => -5])
        ->expectsOutputToContain('Last 1 hour(s)')
        ->assertExitCode(0);
});

it('does not show channels section for incoming traces', function () {
    createIncoming();

    Artisan::call('request-tracer:stats', ['--type' => 'incoming']);
    $output = Artisan::output();

    expect($output)
        ->toContain('Incoming')
        ->not->toContain('Top Channels');
});
