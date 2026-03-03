<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

function createOutgoingTrace(array $attributes): OutgoingRequestTrace
{
    $trace = OutgoingRequestTrace::create(collect($attributes)->except('created_at')->all());

    if (isset($attributes['created_at'])) {
        $trace->newQuery()->where('id', $trace->id)->update(['created_at' => $attributes['created_at']]);
    }

    return $trace;
}

function createIncomingTrace(array $attributes): IncomingRequestTrace
{
    $trace = IncomingRequestTrace::create(collect($attributes)->except('created_at')->all());

    if (isset($attributes['created_at'])) {
        $trace->newQuery()->where('id', $trace->id)->update(['created_at' => $attributes['created_at']]);
    }

    return $trace;
}

it('warns when retention is not configured', function () {
    $this->artisan('request-tracer:purge')
        ->expectsOutputToContain('Retention not configured')
        ->assertExitCode(1);
});

it('warns when days option is 0', function () {
    $this->artisan('request-tracer:purge', ['--days' => 0])
        ->expectsOutputToContain('Retention not configured')
        ->assertExitCode(1);
});

it('warns when chunk is less than 1', function () {
    $this->artisan('request-tracer:purge', ['--days' => 30, '--chunk' => 0])
        ->expectsOutputToContain('Chunk must be a positive integer')
        ->assertExitCode(1);
});

it('purges old traces', function () {
    createOutgoingTrace(['host' => 'old.example.com', 'created_at' => now()->subDays(60)]);
    createOutgoingTrace(['host' => 'recent.example.com', 'created_at' => now()->subDays(5)]);
    createIncomingTrace(['host' => 'old-incoming.example.com', 'created_at' => now()->subDays(60)]);

    $this->artisan('request-tracer:purge', ['--days' => 30])
        ->expectsOutputToContain('Purged 1 outgoing traces')
        ->expectsOutputToContain('Purged 1 incoming traces')
        ->assertExitCode(0);

    expect(OutgoingRequestTrace::count())->toBe(1)
        ->and(OutgoingRequestTrace::first()->host)->toBe('recent.example.com');
});

it('uses retention_days from config', function () {
    config(['kolaybi.request-tracer.retention_days' => 30]);

    createOutgoingTrace(['host' => 'old.example.com', 'created_at' => now()->subDays(60)]);

    $this->artisan('request-tracer:purge')
        ->expectsOutputToContain('Purged 1 outgoing traces')
        ->assertExitCode(0);
});

it('overrides config retention with --days option', function () {
    config(['kolaybi.request-tracer.retention_days' => 90]);

    createOutgoingTrace(['host' => 'old.example.com', 'created_at' => now()->subDays(60)]);

    $this->artisan('request-tracer:purge', ['--days' => 30])
        ->expectsOutputToContain('Purged 1 outgoing traces')
        ->assertExitCode(0);
});
