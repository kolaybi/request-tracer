<?php

use Illuminate\Database\Connection;
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

it('preserves matching incoming rows from the most recent archive', function () {
    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/*']);

    $connection = DB::connection('testing');

    $connection->statement("CREATE TABLE {$archive} AS SELECT * FROM {$live} WHERE 0");
    $connection->table($archive)->insert([
        ['id' => '01JTEST000000000000QNB001', 'path' => 'qnb/reports', 'method' => 'GET'],
        ['id' => '01JTEST000000000000QNB002', 'path' => 'qnb/reports/123', 'method' => 'GET'],
        ['id' => '01JTEST00000000000NOISE01', 'path' => 'kolaybi/v1/anything', 'method' => 'GET'],
    ]);

    $this->artisan('request-tracer:preserve')
        ->expectsOutputToContain("Preserved 2 incoming row(s) from [{$archive}]")
        ->assertExitCode(0);

    expect($connection->table($persistent)->where('path', 'qnb/reports')->count())->toBe(1)
        ->and($connection->table($persistent)->where('path', 'qnb/reports/123')->count())->toBe(1)
        ->and($connection->table($persistent)->where('path', 'kolaybi/v1/anything')->count())->toBe(0);
});

it('preserves matching outgoing rows using trimmed host+path', function () {
    $live = config('kolaybi.request-tracer.outgoing.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    config(['kolaybi.request-tracer.outgoing.persist' => '*qnb*']);

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$archive} AS SELECT * FROM {$live} WHERE 0");
    $connection->table($archive)->insert([
        ['id' => '01JTEST000000000000OUT001', 'host' => 'api.qnbefinans.com', 'path' => '/v1/invoices', 'method' => 'POST'],
        ['id' => '01JTEST000000000000OUT002', 'host' => 'api.example.com', 'path' => '/qnb/relay', 'method' => 'POST'],
        ['id' => '01JTEST000000000000OUT003', 'host' => 'api.example.com', 'path' => '/other', 'method' => 'POST'],
    ]);

    $this->artisan('request-tracer:preserve --direction=outgoing')
        ->expectsOutputToContain("Preserved 2 outgoing row(s) from [{$archive}]")
        ->assertExitCode(0);

    expect($connection->table($persistent)->count())->toBe(2)
        ->and($connection->table($persistent)->where('id', '01JTEST000000000000OUT003')->count())->toBe(0);
});

it('preserves from a specific archive when --date is provided', function () {
    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $targetDate = now()->subDays(3)->format('Ymd');
    $targetArchive = "{$live}_{$targetDate}";
    $otherDate = now()->subDays(1)->format('Ymd');
    $otherArchive = "{$live}_{$otherDate}";

    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/*']);

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$targetArchive} AS SELECT * FROM {$live} WHERE 0");
    $connection->statement("CREATE TABLE {$otherArchive} AS SELECT * FROM {$live} WHERE 0");

    $connection->table($targetArchive)->insert([
        ['id' => '01JTEST00000000000DATE001', 'path' => 'qnb/old', 'method' => 'GET'],
    ]);
    $connection->table($otherArchive)->insert([
        ['id' => '01JTEST00000000000DATE002', 'path' => 'qnb/new', 'method' => 'GET'],
    ]);

    $this->artisan("request-tracer:preserve --date={$targetDate} --direction=incoming")
        ->expectsOutputToContain("Preserved 1 incoming row(s) from [{$targetArchive}]")
        ->assertExitCode(0);

    expect($connection->table($persistent)->where('path', 'qnb/old')->count())->toBe(1)
        ->and($connection->table($persistent)->where('path', 'qnb/new')->count())->toBe(0);
});

it('preserves from every archive when --all is provided', function () {
    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $d1 = now()->subDays(3)->format('Ymd');
    $d2 = now()->subDays(2)->format('Ymd');
    $a1 = "{$live}_{$d1}";
    $a2 = "{$live}_{$d2}";

    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/*']);

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$a1} AS SELECT * FROM {$live} WHERE 0");
    $connection->statement("CREATE TABLE {$a2} AS SELECT * FROM {$live} WHERE 0");
    $connection->table($a1)->insert([['id' => '01JTEST0000000000ALL00001', 'path' => 'qnb/a', 'method' => 'GET']]);
    $connection->table($a2)->insert([['id' => '01JTEST0000000000ALL00002', 'path' => 'qnb/b', 'method' => 'GET']]);

    $this->artisan('request-tracer:preserve --all --direction=incoming')->assertExitCode(0);

    expect($connection->table($persistent)->count())->toBe(2);
});

it('respects --direction=incoming and skips outgoing', function () {
    $incomingLive = config('kolaybi.request-tracer.incoming.table');
    $outgoingLive = config('kolaybi.request-tracer.outgoing.table');
    $outgoingPersistent = "{$outgoingLive}_persistent";
    $dateSuffix = now()->format('Ymd');

    config([
        'kolaybi.request-tracer.incoming.persist' => 'qnb/*',
        'kolaybi.request-tracer.outgoing.persist' => '*qnb*',
    ]);

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$incomingLive}_{$dateSuffix} AS SELECT * FROM {$incomingLive} WHERE 0");
    $connection->statement("CREATE TABLE {$outgoingLive}_{$dateSuffix} AS SELECT * FROM {$outgoingLive} WHERE 0");
    $connection->table("{$outgoingLive}_{$dateSuffix}")->insert([
        ['id' => '01JTEST0000000000DIR00001', 'host' => 'api.qnb.com', 'path' => '/x', 'method' => 'GET'],
    ]);

    $this->artisan('request-tracer:preserve --direction=incoming')->assertExitCode(0);

    expect($connection->table($outgoingPersistent)->count())->toBe(0);
});

it('fails when --direction is an unknown value', function () {
    $this->artisan('request-tracer:preserve --direction=sideways')
        ->expectsOutputToContain('Invalid --direction value [sideways]')
        ->assertExitCode(1);
});

it('is idempotent — re-running over the same archive does not duplicate', function () {
    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/*']);

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$archive} AS SELECT * FROM {$live} WHERE 0");
    $connection->table($archive)->insert([
        ['id' => '01JTEST000000000000QNB001', 'path' => 'qnb/reports', 'method' => 'GET'],
    ]);

    $this->artisan('request-tracer:preserve')->assertExitCode(0);
    $this->artisan('request-tracer:preserve')->assertExitCode(0);

    expect($connection->table($persistent)->count())->toBe(1);
});

it('skips a direction silently when its persist config is empty', function () {
    $live = config('kolaybi.request-tracer.incoming.table');
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$archive} AS SELECT * FROM {$live} WHERE 0");
    $connection->table($archive)->insert([
        ['id' => '01JTEST00000000000EMPTY01', 'path' => 'qnb/reports', 'method' => 'GET'],
    ]);

    $this->artisan('request-tracer:preserve --direction=incoming')
        ->expectsOutputToContain('Preserved 0 incoming row(s) (no patterns configured)')
        ->assertExitCode(0);

    expect($connection->table("{$live}_persistent")->count())->toBe(0);
});

it('warns and exits 0 when no archive exists for a direction', function () {
    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/*']);

    $live = config('kolaybi.request-tracer.incoming.table');

    $this->artisan('request-tracer:preserve --direction=incoming')
        ->expectsOutputToContain("No archives found for [{$live}]")
        ->assertExitCode(0);
});

it('warns and exits 0 when --date targets a non-existent archive', function () {
    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/*']);

    $live = config('kolaybi.request-tracer.incoming.table');

    $this->artisan('request-tracer:preserve --direction=incoming --date=20200101')
        ->expectsOutputToContain("No archives found for [{$live}]")
        ->assertExitCode(0);
});

it('warns and exits 0 when the persistent table does not exist', function () {
    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/*']);

    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$archive} AS SELECT * FROM {$live} WHERE 0");
    $connection->statement("DROP TABLE {$persistent}");

    $this->artisan('request-tracer:preserve --direction=incoming')
        ->expectsOutputToContain("Persistent table [{$persistent}] does not exist")
        ->assertExitCode(0);
});

it('warns and exits 0 on unsupported database driver', function () {
    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->allows('getDriverName')->andReturn('sqlsrv');

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:preserve')
        ->expectsOutputToContain('Unsupported database driver [sqlsrv] for preserve command.')
        ->assertExitCode(0);
});

it('uses INSERT IGNORE on MySQL', function () {
    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/*']);

    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->allows('getDriverName')->andReturn('mysql');
    $mockConnection->allows('getDatabaseName')->andReturn('test_db');

    // tableExists for persistent table — exists.
    $mockConnection->allows('selectOne')
        ->with(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === $persistent),
        )
        ->andReturn((object) ['1' => 1]);

    // discoverArchiveTables returns one archive.
    $mockConnection->allows('select')
        ->with(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?',
            Mockery::on(fn($args) => $args[1] === "{$live}_%"),
        )
        ->andReturn([(object) ['table_name' => $archive]]);

    $mockConnection->expects('affectingStatement')
        ->with(
            Mockery::pattern('/INSERT IGNORE INTO ' . preg_quote($persistent, '/') . ' SELECT \* FROM ' . preg_quote($archive, '/') . ' WHERE path LIKE \? ESCAPE/'),
            ['qnb/%'],
        )
        ->andReturn(3);

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:preserve --direction=incoming')
        ->expectsOutputToContain("Preserved 3 incoming row(s) from [{$archive}]")
        ->assertExitCode(0);
});

it('uses ON CONFLICT DO NOTHING on PostgreSQL', function () {
    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/*']);

    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->allows('getDriverName')->andReturn('pgsql');

    $mockConnection->allows('selectOne')
        ->with(
            'SELECT 1 FROM pg_tables WHERE schemaname = ? AND tablename = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === $persistent),
        )
        ->andReturn((object) ['1' => 1]);

    $mockConnection->allows('select')
        ->with(
            'SELECT tablename FROM pg_tables WHERE schemaname = ? AND tablename LIKE ?',
            Mockery::on(fn($args) => $args[1] === "{$live}_%"),
        )
        ->andReturn([(object) ['tablename' => $archive]]);

    $mockConnection->expects('affectingStatement')
        ->with(
            Mockery::pattern('/INSERT INTO ' . preg_quote($persistent, '/') . ' SELECT \* FROM ' . preg_quote($archive, '/') . ' WHERE path LIKE \? ESCAPE .+ ON CONFLICT \(id\) DO NOTHING/'),
            ['qnb/%'],
        )
        ->andReturn(2);

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:preserve --direction=incoming')
        ->expectsOutputToContain("Preserved 2 incoming row(s) from [{$archive}]")
        ->assertExitCode(0);
});

it('translates ? glob to single-char wildcard', function () {
    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    config(['kolaybi.request-tracer.incoming.persist' => 'qnb/v?/reports']);

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$archive} AS SELECT * FROM {$live} WHERE 0");
    $connection->table($archive)->insert([
        ['id' => '01JTEST00000000000QGL00001', 'path' => 'qnb/v1/reports', 'method' => 'GET'],
        ['id' => '01JTEST00000000000QGL00002', 'path' => 'qnb/v22/reports', 'method' => 'GET'],
    ]);

    $this->artisan('request-tracer:preserve --direction=incoming')->assertExitCode(0);

    expect($connection->table($persistent)->where('path', 'qnb/v1/reports')->count())->toBe(1)
        ->and($connection->table($persistent)->where('path', 'qnb/v22/reports')->count())->toBe(0);
});

it('accepts an array as persist config', function () {
    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    config(['kolaybi.request-tracer.incoming.persist' => ['qnb/*', 'zdonusum/*']]);

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$archive} AS SELECT * FROM {$live} WHERE 0");
    $connection->table($archive)->insert([
        ['id' => '01JTEST00000000000ARR00001', 'path' => 'qnb/reports', 'method' => 'GET'],
        ['id' => '01JTEST00000000000ARR00002', 'path' => 'zdonusum/x', 'method' => 'GET'],
    ]);

    $this->artisan('request-tracer:preserve --direction=incoming')->assertExitCode(0);

    expect($connection->table($persistent)->count())->toBe(2);
});

it('uses CONCAT+IFNULL outgoing match on MySQL', function () {
    config(['kolaybi.request-tracer.outgoing.persist' => '*qnb*']);

    $live = config('kolaybi.request-tracer.outgoing.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->allows('getDriverName')->andReturn('mysql');
    $mockConnection->allows('getDatabaseName')->andReturn('test_db');

    $mockConnection->allows('selectOne')
        ->with(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === $persistent),
        )
        ->andReturn((object) ['1' => 1]);

    $mockConnection->allows('select')
        ->with(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?',
            Mockery::any(),
        )
        ->andReturn([(object) ['table_name' => $archive]]);

    $mockConnection->expects('affectingStatement')
        ->with(
            Mockery::pattern("/TRIM\\(BOTH '\\/' FROM CONCAT\\(IFNULL\\(host, ''\\), IFNULL\\(path, ''\\)\\)\\) LIKE \\?/"),
            ['%qnb%'],
        )
        ->andReturn(1);

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:preserve --direction=outgoing')
        ->assertExitCode(0);
});

it('uses COALESCE outgoing match on PostgreSQL', function () {
    config(['kolaybi.request-tracer.outgoing.persist' => '*qnb*']);

    $live = config('kolaybi.request-tracer.outgoing.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->allows('getDriverName')->andReturn('pgsql');

    $mockConnection->allows('selectOne')
        ->with(
            'SELECT 1 FROM pg_tables WHERE schemaname = ? AND tablename = ? LIMIT 1',
            Mockery::on(fn($args) => $args[1] === $persistent),
        )
        ->andReturn((object) ['1' => 1]);

    $mockConnection->allows('select')
        ->with(
            'SELECT tablename FROM pg_tables WHERE schemaname = ? AND tablename LIKE ?',
            Mockery::any(),
        )
        ->andReturn([(object) ['tablename' => $archive]]);

    $mockConnection->expects('affectingStatement')
        ->with(
            Mockery::pattern("/TRIM\\(BOTH '\\/' FROM \\(COALESCE\\(host, ''\\) \\|\\| COALESCE\\(path, ''\\)\\)\\) LIKE \\?/"),
            ['%qnb%'],
        )
        ->andReturn(1);

    DB::shouldReceive('connection')
        ->once()
        ->with(config('kolaybi.request-tracer.connection'))
        ->andReturn($mockConnection);

    $this->artisan('request-tracer:preserve --direction=outgoing')
        ->assertExitCode(0);
});

it('escapes literal % and _ in patterns', function () {
    $live = config('kolaybi.request-tracer.incoming.table');
    $persistent = "{$live}_persistent";
    $dateSuffix = now()->format('Ymd');
    $archive = "{$live}_{$dateSuffix}";

    config(['kolaybi.request-tracer.incoming.persist' => 'a%b']);

    $connection = DB::connection('testing');
    $connection->statement("CREATE TABLE {$archive} AS SELECT * FROM {$live} WHERE 0");
    $connection->table($archive)->insert([
        ['id' => '01JTEST00000000000ESC00001', 'path' => 'a%b', 'method' => 'GET'],
        ['id' => '01JTEST00000000000ESC00002', 'path' => 'axb', 'method' => 'GET'],
        ['id' => '01JTEST00000000000ESC00003', 'path' => 'axyzb', 'method' => 'GET'],
    ]);

    $this->artisan('request-tracer:preserve --direction=incoming')->assertExitCode(0);

    expect($connection->table($persistent)->where('path', 'a%b')->count())->toBe(1)
        ->and($connection->table($persistent)->where('path', 'axb')->count())->toBe(0)
        ->and($connection->table($persistent)->where('path', 'axyzb')->count())->toBe(0);
});
