<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

it('creates the incoming persistent table during migration', function () {
    $expected = config('kolaybi.request-tracer.incoming.table') . '_persistent';

    expect(DB::connection('testing')->selectOne(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
        [$expected],
    ))->not->toBeNull();
});

it('creates the outgoing persistent table during migration', function () {
    $expected = config('kolaybi.request-tracer.outgoing.table') . '_persistent';

    expect(DB::connection('testing')->selectOne(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
        [$expected],
    ))->not->toBeNull();
});

it('registers the preserve command', function () {
    $this->artisan('request-tracer:preserve')->assertExitCode(0);
});
