<?php

use Illuminate\Database\Connection;
use KolayBi\RequestTracer\Commands\Concerns\QueriesArchiveTables;

beforeEach(function () {
    $this->queriesArchiveTables = new class () {
        use QueriesArchiveTables {
            discoverArchiveTables as public;
        }
    };
});

it('returns empty archive table list for unsupported drivers', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
    $connection->shouldNotReceive('select');

    $tables = $this->queriesArchiveTables->discoverArchiveTables($connection, 'outgoing_request_traces', null);

    expect($tables)->toBe([]);
});
