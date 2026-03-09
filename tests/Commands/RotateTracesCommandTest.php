<?php

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['kolaybi.request-tracer.connection' => 'testing']);
});

it('rotates outgoing and incoming tables', function () {
    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $incoming = config('kolaybi.request-tracer.incoming.table');
    $dateSuffix = now()->format('Ymd');

    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain("Rotated [{$outgoing}]")
        ->expectsOutputToContain("Rotated [{$incoming}]")
        ->assertExitCode(0);

    $connection = DB::connection('testing');

    // Archive tables should exist
    expect($connection->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", ["{$outgoing}_{$dateSuffix}"]))->not->toBeNull()
        ->and($connection->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", ["{$incoming}_{$dateSuffix}"]))->not->toBeNull();

    // Original tables should still exist (fresh copies)
    expect($connection->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$outgoing]))->not->toBeNull()
        ->and($connection->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$incoming]))->not->toBeNull();
});

it('skips rotation if archive table already exists', function () {
    $outgoing = config('kolaybi.request-tracer.outgoing.table');

    // Run once to create the archive
    $this->artisan('request-tracer:rotate')->assertExitCode(0);

    // Run again — should skip
    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain('already exists')
        ->assertExitCode(0);
});

it('drops old archive tables past retention', function () {
    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');

    // Create a fake old archive table (20 days ago)
    $oldDate = now()->subDays(20)->format('Ymd');
    $oldArchive = "{$outgoing}_{$oldDate}";
    $connection->statement("CREATE TABLE {$oldArchive} AS SELECT * FROM {$outgoing} WHERE 0");

    // Create a fake recent archive table (5 days ago)
    $recentDate = now()->subDays(5)->format('Ymd');
    $recentArchive = "{$outgoing}_{$recentDate}";
    $connection->statement("CREATE TABLE {$recentArchive} AS SELECT * FROM {$outgoing} WHERE 0");

    $this->artisan('request-tracer:rotate', ['--days' => 15])
        ->expectsOutputToContain('Dropped 1 old archive')
        ->assertExitCode(0);

    // Old archive should be gone
    expect($connection->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$oldArchive]))->toBeNull();

    // Recent archive should remain
    expect($connection->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$recentArchive]))->not->toBeNull();
});

it('preserves data in archive table after rotation', function () {
    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');
    $dateSuffix = now()->format('Ymd');

    // Insert a row into the current table
    $connection->table($outgoing)->insert([
        'id'     => '01JTEST000000000000000001',
        'host'   => 'api.example.com',
        'method' => 'GET',
    ]);

    $this->artisan('request-tracer:rotate')->assertExitCode(0);

    // Archive should have the old row
    $archived = $connection->table("{$outgoing}_{$dateSuffix}")->count();
    expect($archived)->toBe(1);

    // Current table should be empty
    $current = $connection->table($outgoing)->count();
    expect($current)->toBe(0);
});

it('skips non-existent table', function () {
    // Trigger lazy migration before changing config
    DB::connection('testing')->select('SELECT 1');

    config(['kolaybi.request-tracer.outgoing.table' => 'non_existent_table']);

    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain('does not exist')
        ->assertExitCode(0);
});

it('uses retention_days from config', function () {
    config(['kolaybi.request-tracer.retention_days' => 10]);

    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');

    // Create old archive (15 days ago)
    $oldDate = now()->subDays(15)->format('Ymd');
    $oldArchive = "{$outgoing}_{$oldDate}";
    $connection->statement("CREATE TABLE {$oldArchive} AS SELECT * FROM {$outgoing} WHERE 0");

    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain('Dropped 1 old archive')
        ->assertExitCode(0);

    expect($connection->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$oldArchive]))->toBeNull();
});

it('does not drop archives when retention is not configured', function () {
    config(['kolaybi.request-tracer.retention_days' => 0]);

    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');

    // Create old archive
    $oldDate = now()->subDays(60)->format('Ymd');
    $oldArchive = "{$outgoing}_{$oldDate}";
    $connection->statement("CREATE TABLE {$oldArchive} AS SELECT * FROM {$outgoing} WHERE 0");

    $this->artisan('request-tracer:rotate')->assertExitCode(0);

    // Archive should still exist
    expect($connection->selectOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$oldArchive]))->not->toBeNull();
});

it('warns and skips when database driver is unsupported', function () {
    config(['kolaybi.request-tracer.retention_days' => 0]);

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->shouldReceive('getDriverName')->twice()->andReturn('sqlsrv');

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain('Unsupported database driver [sqlsrv] for rotate command.')
        ->assertExitCode(0);
});
