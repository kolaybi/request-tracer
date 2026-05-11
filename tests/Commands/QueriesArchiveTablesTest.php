<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use KolayBi\RequestTracer\Commands\Concerns\QueriesArchiveTables;

beforeEach(function () {
    $this->queriesArchiveTables = new class () {
        use QueriesArchiveTables {
            discoverArchiveTables as public;
            resolveBaseTable as public;
        }
    };
});

it('returns empty archive table list for unsupported drivers', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->expects('getDriverName')->andReturn('sqlsrv');
    $connection->expects('select')->never();

    $tables = $this->queriesArchiveTables->discoverArchiveTables($connection, 'outgoing_request_traces', null);

    expect($tables)->toBe([]);
});

it('discovers MySQL archive tables matching date suffix pattern', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->expects('getDriverName')->andReturn('mysql');
    $connection->expects('getDatabaseName')->andReturn('test_db');

    $connection->expects('select')
        ->with(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?',
            ['test_db', 'outgoing_request_traces_%'],
        )
        ->andReturn([
            (object) ['table_name' => 'outgoing_request_traces_20260301'],
            (object) ['table_name' => 'outgoing_request_traces_temp'],  // non-date, should be filtered
        ]);

    $tables = $this->queriesArchiveTables->discoverArchiveTables($connection, 'outgoing_request_traces', null);

    expect($tables)->toBe(['outgoing_request_traces_20260301']);
});

it('discovers MySQL archive tables with schema override', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->expects('getDriverName')->andReturn('mysql');

    $connection->expects('select')
        ->with(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?',
            ['custom_schema', 'outgoing_request_traces_%'],
        )
        ->andReturn([(object) ['table_name' => 'outgoing_request_traces_20260301']]);

    $tables = $this->queriesArchiveTables->discoverArchiveTables($connection, 'outgoing_request_traces', 'custom_schema');

    expect($tables)->toBe(['outgoing_request_traces_20260301']);
});

it('discovers MySQL archive tables using TABLE_NAME column variant', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->expects('getDriverName')->andReturn('mysql');
    $connection->expects('getDatabaseName')->andReturn('test_db');

    // Some MySQL versions return TABLE_NAME instead of table_name
    $row = new stdClass();
    $row->TABLE_NAME = 'outgoing_request_traces_20260301';
    $connection->expects('select')->andReturn([$row]);

    $tables = $this->queriesArchiveTables->discoverArchiveTables($connection, 'outgoing_request_traces', null);

    expect($tables)->toBe(['outgoing_request_traces_20260301']);
});

it('discovers PostgreSQL archive tables matching date suffix pattern', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->expects('getDriverName')->andReturn('pgsql');

    $connection->expects('select')
        ->with(
            'SELECT tablename FROM pg_tables WHERE schemaname = ? AND tablename LIKE ?',
            ['public', 'outgoing_request_traces_%'],
        )
        ->andReturn([
            (object) ['tablename' => 'outgoing_request_traces_20260301'],
            (object) ['tablename' => 'outgoing_request_traces_20260302'],
        ]);

    $tables = $this->queriesArchiveTables->discoverArchiveTables($connection, 'outgoing_request_traces', null);

    expect($tables)->toBe(['outgoing_request_traces_20260301', 'outgoing_request_traces_20260302']);
});

it('discovers PostgreSQL archive tables with custom schema', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->expects('getDriverName')->andReturn('pgsql');

    $connection->expects('select')
        ->with(
            'SELECT tablename FROM pg_tables WHERE schemaname = ? AND tablename LIKE ?',
            ['custom_schema', 'outgoing_request_traces_%'],
        )
        ->andReturn([(object) ['tablename' => 'outgoing_request_traces_20260301']]);

    $tables = $this->queriesArchiveTables->discoverArchiveTables($connection, 'outgoing_request_traces', 'custom_schema');

    expect($tables)->toBe(['outgoing_request_traces_20260301']);
});

it('strips schema prefix from model table name', function () {
    $model = Mockery::mock(Model::class);
    $model->allows('getTable')->andReturn('my_schema.outgoing_request_traces');

    config(['kolaybi.request-tracer.schema' => 'my_schema']);

    $result = $this->queriesArchiveTables->resolveBaseTable($model);

    expect($result)->toBe('outgoing_request_traces');
});

it('returns table name unchanged when no schema prefix present', function () {
    $model = Mockery::mock(Model::class);
    $model->allows('getTable')->andReturn('outgoing_request_traces');

    config(['kolaybi.request-tracer.schema' => 'my_schema']);

    $result = $this->queriesArchiveTables->resolveBaseTable($model);

    expect($result)->toBe('outgoing_request_traces');
});
