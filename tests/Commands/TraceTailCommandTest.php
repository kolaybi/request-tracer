<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KolayBi\RequestTracer\Commands\TraceTailCommand;
use KolayBi\RequestTracer\Enums\TraceDirection;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

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

function tailRenderLine(int $verbosity, IncomingRequestTrace|OutgoingRequestTrace $trace, TraceDirection $direction): string
{
    $command = new TraceTailCommand();
    $buffered = new BufferedOutput($verbosity);
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffered));

    new ReflectionMethod($command, 'renderLine')->invoke($command, $trace, $direction);

    return $buffered->fetch();
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

it('renders new outgoing traces that arrive after startup', function () {
    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');
    $inserted = false;

    DB::listen(function ($query) use ($connection, $outgoing, &$inserted) {
        if (!$inserted && str_starts_with($query->sql, 'select "id" from') && str_contains($query->sql, $outgoing)) {
            $inserted = true;
            $connection->table($outgoing)->insert([
                'id'         => (string) Str::ulid(),
                'host'       => 'new-outgoing.example.com',
                'path'       => '/api/test',
                'method'     => 'POST',
                'status'     => 201,
                'duration'   => 42,
                'created_at' => now(),
            ]);
        }
    });

    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing', '--interval' => 1])
        ->expectsOutputToContain('new-outgoing.example.com')
        ->assertExitCode(0);
});

it('renders new incoming traces that arrive after startup', function () {
    $incoming = config('kolaybi.request-tracer.incoming.table');
    $connection = DB::connection('testing');
    $inserted = false;

    DB::listen(function ($query) use ($connection, $incoming, &$inserted) {
        if (!$inserted && str_starts_with($query->sql, 'select "id" from') && str_contains($query->sql, $incoming)) {
            $inserted = true;
            $connection->table($incoming)->insert([
                'id'         => (string) Str::ulid(),
                'host'       => 'new-incoming.test',
                'path'       => '/dashboard',
                'method'     => 'GET',
                'status'     => 200,
                'duration'   => 33,
                'created_at' => now(),
            ]);
        }
    });

    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--type' => 'incoming', '--interval' => 1])
        ->expectsOutputToContain('new-incoming.test')
        ->assertExitCode(0);
});

it('renders colorStatus and renderLine correctly for all status branches', function () {
    // Use reflection to test the private renderLine/colorStatus methods directly
    $command = new TraceTailCommand();
    $buffered = new BufferedOutput();
    $input = new ArrayInput([]);
    $command->setOutput(new OutputStyle($input, $buffered));

    $renderLine = new ReflectionMethod($command, 'renderLine');

    // 2xx status (green)
    $trace = new OutgoingRequestTrace(
        ['host' => 'a.com', 'path' => '/ok', 'method' => 'GET', 'status' => 200, 'duration' => 42, 'channel' => null, 'created_at' => now()],
    );
    $renderLine->invoke($command, $trace, TraceDirection::Outgoing);

    // 4xx status (yellow)
    $trace = new OutgoingRequestTrace(
        ['host' => 'b.com', 'path' => '/missing', 'method' => 'GET', 'status' => 404, 'duration' => 10, 'channel' => null, 'created_at' => now()],
    );
    $renderLine->invoke($command, $trace, TraceDirection::Outgoing);

    // 5xx status (red)
    $trace = new OutgoingRequestTrace(
        ['host' => 'c.com', 'path' => '/error', 'method' => 'GET', 'status' => 500, 'duration' => 5000, 'channel' => null, 'created_at' => now()],
    );
    $renderLine->invoke($command, $trace, TraceDirection::Outgoing);

    // null status (white dash)
    $trace = new OutgoingRequestTrace(
        ['host' => 'd.com', 'path' => '/null', 'method' => 'GET', 'status' => null, 'duration' => null, 'channel' => null, 'created_at' => now()],
    );
    $renderLine->invoke($command, $trace, TraceDirection::Outgoing);

    // 1xx status (white)
    $trace = new OutgoingRequestTrace(
        ['host' => 'e.com', 'path' => '/info', 'method' => 'GET', 'status' => 100, 'duration' => 1, 'channel' => null, 'created_at' => now()],
    );
    $renderLine->invoke($command, $trace, TraceDirection::Outgoing);

    // With channel
    $trace = new OutgoingRequestTrace(
        ['host' => 'f.com', 'path' => '/pay', 'method' => 'POST', 'status' => 200, 'duration' => 100, 'channel' => 'payments', 'created_at' => now()],
    );
    $renderLine->invoke($command, $trace, TraceDirection::Outgoing);

    // Incoming trace (no channel)
    $inTrace = new IncomingRequestTrace(
        ['host' => 'g.test', 'path' => '/dashboard', 'method' => 'GET', 'status' => 200, 'duration' => 50, 'created_at' => now()],
    );
    $renderLine->invoke($command, $inTrace, TraceDirection::Incoming);

    $output = $buffered->fetch();

    expect($output)
        ->toContain('a.com')
        ->toContain('42ms')
        ->toContain('b.com')
        ->toContain('c.com')
        ->toContain('d.com')
        ->toContain('e.com')
        ->toContain('f.com')
        ->toContain('payments')
        ->toContain('g.test')
        ->toContain('OUTGOING')
        ->toContain('INCOMING');
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

it('renders channel for incoming traces in tail output', function () {
    $command = new TraceTailCommand();
    $buffered = new BufferedOutput();
    $input = new ArrayInput([]);
    $command->setOutput(new OutputStyle($input, $buffered));

    $renderLine = new ReflectionMethod($command, 'renderLine');

    $trace = new IncomingRequestTrace([
        'host'   => 'app.test', 'path' => '/api', 'method' => 'GET',
        'status' => 200, 'duration' => 50, 'channel' => 'mobile', 'created_at' => now(),
    ]);
    $renderLine->invoke($command, $trace, TraceDirection::Incoming);

    $output = $buffered->fetch();

    expect($output)->toContain('mobile');
});

it('truncates long endpoints at default verbosity', function () {
    $trace = new OutgoingRequestTrace([
        'host'   => 'api.example.com', 'path' => '/v1/' . str_repeat('x', 80),
        'method' => 'GET', 'status' => 200, 'duration' => 10, 'created_at' => now(),
    ]);

    $output = tailRenderLine(OutputInterface::VERBOSITY_NORMAL, $trace, TraceDirection::Outgoing);

    expect($output)
        ->toContain('...')
        ->not->toContain(str_repeat('x', 80));
});

it('renders the full endpoint and record id at -v', function () {
    $longPath = '/v1/' . str_repeat('x', 80);
    $trace = new OutgoingRequestTrace([
        'id'     => '01HZTAILVERBOSEID000000000',
        'host'   => 'api.example.com', 'path' => $longPath,
        'method' => 'GET', 'status' => 200, 'duration' => 10, 'created_at' => now(),
    ]);

    $output = tailRenderLine(OutputInterface::VERBOSITY_VERBOSE, $trace, TraceDirection::Outgoing);

    expect($output)
        ->toContain($longPath)
        ->toContain('id=01HZTAILVERBOSEID000000000');
});

it('does not render identity context at -v', function () {
    $trace = new OutgoingRequestTrace([
        'id'         => '01HZTAILVERBOSEID000000000',
        'host'       => 'a.com', 'path' => '/x', 'method' => 'GET', 'status' => 200, 'duration' => 1,
        'trace_id'   => 'corr-1', 'tenant_id' => 42, 'user_id' => 7, 'client_ip' => '1.2.3.4',
        'created_at' => now(),
    ]);

    $output = tailRenderLine(OutputInterface::VERBOSITY_VERBOSE, $trace, TraceDirection::Outgoing);

    expect($output)
        ->not->toContain('trace=')
        ->not->toContain('tenant=')
        ->not->toContain('user=')
        ->not->toContain('ip=');
});

it('renders identity context at -vv', function () {
    $trace = new OutgoingRequestTrace([
        'id'         => '01HZTAILVERBOSEID000000000',
        'host'       => 'a.com', 'path' => '/x', 'method' => 'GET', 'status' => 200, 'duration' => 1,
        'trace_id'   => 'corr-1', 'tenant_id' => 42, 'user_id' => 7, 'client_ip' => '1.2.3.4',
        'created_at' => now(),
    ]);

    $output = tailRenderLine(OutputInterface::VERBOSITY_VERY_VERBOSE, $trace, TraceDirection::Outgoing);

    expect($output)
        ->toContain('trace=corr-1')
        ->toContain('tenant=42')
        ->toContain('user=7')
        ->toContain('ip=1.2.3.4');
});

it('omits null identity fields at -vv', function () {
    $trace = new OutgoingRequestTrace([
        'id'         => '01HZTAILVERBOSEID000000000',
        'host'       => 'a.com', 'path' => '/x', 'method' => 'GET', 'status' => 200, 'duration' => 1,
        'created_at' => now(),
    ]);

    $output = tailRenderLine(OutputInterface::VERBOSITY_VERY_VERBOSE, $trace, TraceDirection::Outgoing);

    expect($output)
        ->toContain('id=01HZTAILVERBOSEID000000000')
        ->not->toContain('trace=')
        ->not->toContain('tenant=')
        ->not->toContain('user=')
        ->not->toContain('ip=');
});

it('does not render size and server context at -vv', function () {
    $trace = new OutgoingRequestTrace([
        'id'            => '01HZTAILVERBOSEID000000000',
        'host'          => 'a.com', 'path' => '/x', 'method' => 'GET', 'status' => 200, 'duration' => 1,
        'request_size'  => 123, 'response_size' => 456, 'server_identifier' => 'web-1',
        'message'       => 'Connection refused',
        'created_at'    => now(),
    ]);

    $output = tailRenderLine(OutputInterface::VERBOSITY_VERY_VERBOSE, $trace, TraceDirection::Outgoing);

    expect($output)
        ->not->toContain('req=')
        ->not->toContain('res=')
        ->not->toContain('server=')
        ->not->toContain('msg=');
});

it('renders sizes, server and message at -vvv', function () {
    $trace = new OutgoingRequestTrace([
        'id'            => '01HZTAILVERBOSEID000000000',
        'host'          => 'a.com', 'path' => '/x', 'method' => 'GET', 'status' => 500, 'duration' => 1,
        'request_size'  => 123, 'response_size' => 456, 'server_identifier' => 'web-1',
        'message'       => 'Connection refused',
        'created_at'    => now(),
    ]);

    $output = tailRenderLine(OutputInterface::VERBOSITY_DEBUG, $trace, TraceDirection::Outgoing);

    expect($output)
        ->toContain('req=123B')
        ->toContain('res=456B')
        ->toContain('server=web-1')
        ->toContain('msg=Connection refused');
});

it('omits message and null size fields for incoming traces at -vvv', function () {
    $trace = new IncomingRequestTrace([
        'id'         => '01HZTAILVERBOSEID000000000',
        'host'       => 'app.test', 'path' => '/x', 'method' => 'GET', 'status' => 200, 'duration' => 1,
        'message'    => 'should-not-appear',
        'created_at' => now(),
    ]);

    $output = tailRenderLine(OutputInterface::VERBOSITY_DEBUG, $trace, TraceDirection::Incoming);

    expect($output)
        ->toContain('id=01HZTAILVERBOSEID000000000')
        ->not->toContain('req=')
        ->not->toContain('res=')
        ->not->toContain('server=')
        ->not->toContain('msg=');
});

it('renders verbose lines end to end with the -v flag', function () {
    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');
    $inserted = false;
    $longPath = '/api/' . str_repeat('segment/', 12) . 'end';

    DB::listen(function ($query) use ($connection, $outgoing, &$inserted, $longPath) {
        if (!$inserted && str_starts_with($query->sql, 'select "id" from') && str_contains($query->sql, $outgoing)) {
            $inserted = true;
            $connection->table($outgoing)->insert([
                'id'         => (string) Str::ulid(),
                'host'       => 'verbose.example.com',
                'path'       => $longPath,
                'method'     => 'GET',
                'status'     => 200,
                'duration'   => 42,
                'created_at' => now(),
            ]);
        }
    });

    $buffer = new BufferedOutput();
    $exitCode = Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing', '--interval' => 1, '-v' => true], $buffer);

    expect($exitCode)->toBe(0)
        ->and($buffer->fetch())
        ->toContain('verbose.example.com' . $longPath)
        ->toContain('id=');
});

function tailInsertOutgoingOnFirstPoll(array $rows): void
{
    $outgoing = config('kolaybi.request-tracer.outgoing.table');
    $connection = DB::connection('testing');
    $inserted = false;

    DB::listen(function ($query) use ($connection, $outgoing, &$inserted, $rows) {
        if (!$inserted && str_starts_with($query->sql, 'select "id" from') && str_contains($query->sql, $outgoing)) {
            $inserted = true;
            $connection->table($outgoing)->insert(array_map(fn(array $row) => array_merge([
                'id'         => (string) Str::ulid(),
                'path'       => '/api/test',
                'status'     => 200,
                'duration'   => 10,
                'created_at' => now(),
            ], $row), $rows));
        }
    });
}

it('accepts method filter', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--method' => 'GET'])
        ->assertExitCode(0);
});

it('filters by method', function () {
    tailInsertOutgoingOnFirstPoll([
        ['host' => 'get-request.example.com', 'method' => 'GET'],
        ['host' => 'options-request.example.com', 'method' => 'OPTIONS'],
    ]);

    $buffer = new BufferedOutput();
    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing', '--interval' => 1, '--method' => 'GET'], $buffer);

    expect($buffer->fetch())
        ->toContain('get-request.example.com')
        ->not->toContain('options-request.example.com');
});

it('filters by multiple comma separated methods', function () {
    tailInsertOutgoingOnFirstPoll([
        ['host' => 'get-request.example.com', 'method' => 'GET'],
        ['host' => 'post-request.example.com', 'method' => 'POST'],
        ['host' => 'options-request.example.com', 'method' => 'OPTIONS'],
    ]);

    $buffer = new BufferedOutput();
    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing', '--interval' => 1, '--method' => 'GET,POST'], $buffer);

    expect($buffer->fetch())
        ->toContain('get-request.example.com')
        ->toContain('post-request.example.com')
        ->not->toContain('options-request.example.com');
});

it('filters method case insensitively', function () {
    tailInsertOutgoingOnFirstPoll([
        ['host' => 'get-request.example.com', 'method' => 'GET'],
        ['host' => 'options-request.example.com', 'method' => 'OPTIONS'],
    ]);

    $buffer = new BufferedOutput();
    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing', '--interval' => 1, '--method' => 'get'], $buffer);

    expect($buffer->fetch())
        ->toContain('get-request.example.com')
        ->not->toContain('options-request.example.com');
});

it('filters type case insensitively', function () {
    tailInsertOutgoingOnFirstPoll([
        ['host' => 'uppercase-type.example.com', 'method' => 'GET'],
    ]);

    $buffer = new BufferedOutput();
    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'OUTGOING', '--interval' => 1], $buffer);

    expect($buffer->fetch())->toContain('uppercase-type.example.com');
});

it('filters status range case insensitively', function () {
    tailInsertOutgoingOnFirstPoll([
        ['host' => 'server-error.example.com', 'method' => 'GET', 'status' => 500],
        ['host' => 'success.example.com', 'method' => 'GET', 'status' => 200],
    ]);

    $buffer = new BufferedOutput();
    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing', '--interval' => 1, '--status' => '5XX'], $buffer);

    expect($buffer->fetch())
        ->toContain('server-error.example.com')
        ->not->toContain('success.example.com');
});

it('filters channel case insensitively', function () {
    tailInsertOutgoingOnFirstPoll([
        ['host' => 'payments-channel.example.com', 'method' => 'GET', 'channel' => 'payments'],
        ['host' => 'other-channel.example.com', 'method' => 'GET', 'channel' => 'billing'],
    ]);

    $buffer = new BufferedOutput();
    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing', '--interval' => 1, '--channel' => 'PAYMENTS'], $buffer);

    expect($buffer->fetch())
        ->toContain('payments-channel.example.com')
        ->not->toContain('other-channel.example.com');
});

it('rejects an invalid type value', function () {
    $this->artisan('request-tracer:tail', ['--max-polls' => 1, '--type' => 'sideways'])
        ->expectsOutputToContain('Invalid type')
        ->assertExitCode(1);
});

it('filters host case insensitively', function () {
    tailInsertOutgoingOnFirstPoll([
        ['host' => 'api.example.com', 'method' => 'GET'],
        ['host' => 'other.example.com', 'method' => 'GET'],
    ]);

    $buffer = new BufferedOutput();
    Artisan::call('request-tracer:tail', ['--max-polls' => 1, '--type' => 'outgoing', '--interval' => 1, '--host' => 'API.*'], $buffer);

    expect($buffer->fetch())
        ->toContain('api.example.com')
        ->not->toContain('other.example.com');
});
