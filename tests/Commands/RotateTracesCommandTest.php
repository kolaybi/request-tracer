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

it('rotates using MySQL driver with RENAME TABLE', function () {
    config(['kolaybi.request-tracer.retention_days' => 0]);

    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $incoming = config('kolaybi.request-tracer.incoming.table');
    $dateSuffix = now()->format('Ymd');

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->shouldReceive('getDriverName')->andReturn('mysql');

    // tableExists check: archive doesn't exist, base table exists
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === "{$outgoing}_{$dateSuffix}" || $args[1] === "{$incoming}_{$dateSuffix}"),
        )
        ->andReturn(null);
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === $outgoing || $args[1] === $incoming),
        )
        ->andReturn((object) ['1' => 1]);
    $mockConnection->shouldReceive('getDatabaseName')->andReturn('test_db');

    // MySQL rotation statements
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/DROP TABLE IF EXISTS/'))->twice();
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/CREATE TABLE .+ LIKE/'))->twice();
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/RENAME TABLE/'))->twice();

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain("Rotated [{$outgoing}]")
        ->expectsOutputToContain("Rotated [{$incoming}]")
        ->assertExitCode(0);
});

it('rotates using PostgreSQL driver with ALTER TABLE RENAME in transaction', function () {
    config(['kolaybi.request-tracer.retention_days' => 0]);

    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $incoming = config('kolaybi.request-tracer.incoming.table');
    $dateSuffix = now()->format('Ymd');

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->shouldReceive('getDriverName')->andReturn('pgsql');

    // tableExists — archive doesn't exist, base table exists
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM pg_tables WHERE schemaname = ? AND tablename = ? LIMIT 1',
            Mockery::on(fn($args) => str_ends_with($args[1], "_{$dateSuffix}")),
        )
        ->andReturn(null);
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM pg_tables WHERE schemaname = ? AND tablename = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === $outgoing || $args[1] === $incoming),
        )
        ->andReturn((object) ['1' => 1]);

    // PgSQL rotation
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/DROP TABLE IF EXISTS/'))->twice();
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/CREATE TABLE .+ \(LIKE .+ INCLUDING ALL\)/'))->twice();
    $mockConnection->shouldReceive('transaction')->twice()->andReturnUsing(function ($callback) use ($mockConnection) {
        $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/ALTER TABLE .+ RENAME TO/'));
        $callback();
    });

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain("Rotated [{$outgoing}]")
        ->expectsOutputToContain("Rotated [{$incoming}]")
        ->assertExitCode(0);
});

it('skips when SQLite CREATE TABLE SQL is empty', function () {
    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $incoming = config('kolaybi.request-tracer.incoming.table');
    $dateSuffix = now()->format('Ymd');

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->shouldReceive('getDriverName')->andReturn('sqlite');

    // tableExists — archive doesn't exist, base table exists
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM sqlite_master WHERE type = ? AND name = ? LIMIT 1',
            Mockery::on(fn($args) => str_ends_with($args[1], "_{$dateSuffix}")),
        )
        ->andReturn(null);
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM sqlite_master WHERE type = ? AND name = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === $outgoing || $args[1] === $incoming),
        )
        ->andReturn((object) ['1' => 1]);

    // DROP temp table
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/DROP TABLE IF EXISTS/'))->twice();

    // Return null/empty CREATE SQL — should trigger skip
    $mockConnection->shouldReceive('selectOne')
        ->with(
            Mockery::pattern('/SELECT .* FROM sqlite_master/'),
            Mockery::on(fn($args) => 'table' === $args[0]),
        )
        ->andReturn((object) ['create_sql' => '']);

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain('Could not read CREATE TABLE SQL')
        ->assertExitCode(0);
});

it('discovers MySQL archive tables during retention cleanup', function () {
    config(['kolaybi.request-tracer.retention_days' => 5]);

    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $incoming = config('kolaybi.request-tracer.incoming.table');
    $dateSuffix = now()->format('Ymd');
    $oldDate = now()->subDays(10)->format('Ymd');

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->shouldReceive('getDriverName')->andReturn('mysql');
    $mockConnection->shouldReceive('getDatabaseName')->andReturn('test_db');

    // tableExists — archive doesn't exist, base table exists
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            Mockery::on(fn($args) => str_ends_with($args[1], "_{$dateSuffix}")),
        )
        ->andReturn(null);
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === $outgoing || $args[1] === $incoming),
        )
        ->andReturn((object) ['1' => 1]);

    // MySQL rotation
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/DROP TABLE IF EXISTS/'))->andReturn(true);
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/CREATE TABLE .+ LIKE/'))->andReturn(true);
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/RENAME TABLE/'))->andReturn(true);

    // discoverArchiveTables for retention cleanup — return old archive
    $mockConnection->shouldReceive('select')
        ->with(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?',
            Mockery::on(fn($args) => str_contains($args[1], $outgoing)),
        )
        ->andReturn([(object) ['table_name' => "{$outgoing}_{$oldDate}"]]);
    $mockConnection->shouldReceive('select')
        ->with(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?',
            Mockery::on(fn($args) => str_contains($args[1], $incoming)),
        )
        ->andReturn([(object) ['table_name' => "{$incoming}_{$oldDate}"]]);

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain('Dropped 1 old archive')
        ->assertExitCode(0);
});

it('discovers PostgreSQL archive tables during retention cleanup', function () {
    config(['kolaybi.request-tracer.retention_days' => 5]);

    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $incoming = config('kolaybi.request-tracer.incoming.table');
    $dateSuffix = now()->format('Ymd');
    $oldDate = now()->subDays(10)->format('Ymd');

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->shouldReceive('getDriverName')->andReturn('pgsql');

    // tableExists — archive doesn't exist, base table exists
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM pg_tables WHERE schemaname = ? AND tablename = ? LIMIT 1',
            Mockery::on(fn($args) => str_ends_with($args[1], "_{$dateSuffix}")),
        )
        ->andReturn(null);
    $mockConnection->shouldReceive('selectOne')
        ->with(
            'SELECT 1 FROM pg_tables WHERE schemaname = ? AND tablename = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === $outgoing || $args[1] === $incoming),
        )
        ->andReturn((object) ['1' => 1]);

    // PgSQL rotation
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/DROP TABLE IF EXISTS/'))->andReturn(true);
    $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/CREATE TABLE .+ \(LIKE .+ INCLUDING ALL\)/'))->andReturn(true);
    $mockConnection->shouldReceive('transaction')->andReturnUsing(function ($callback) use ($mockConnection) {
        $mockConnection->shouldReceive('statement')->with(Mockery::pattern('/ALTER TABLE .+ RENAME TO/'));
        $callback();
    });

    // discoverArchiveTables for retention — return old archive
    $mockConnection->shouldReceive('select')
        ->with(
            'SELECT tablename FROM pg_tables WHERE schemaname = ? AND tablename LIKE ?',
            Mockery::on(fn($args) => str_contains($args[1], $outgoing)),
        )
        ->andReturn([(object) ['tablename' => "{$outgoing}_{$oldDate}"]]);
    $mockConnection->shouldReceive('select')
        ->with(
            'SELECT tablename FROM pg_tables WHERE schemaname = ? AND tablename LIKE ?',
            Mockery::on(fn($args) => str_contains($args[1], $incoming)),
        )
        ->andReturn([(object) ['tablename' => "{$incoming}_{$oldDate}"]]);

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:rotate')
        ->expectsOutputToContain('Dropped 1 old archive')
        ->assertExitCode(0);
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
