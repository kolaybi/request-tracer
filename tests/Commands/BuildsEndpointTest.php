<?php

use KolayBi\RequestTracer\Commands\Concerns\BuildsEndpoint;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

// Create anonymous class to test the trait
beforeEach(function () {
    $this->builder = new class () {
        use BuildsEndpoint {
            buildEndpoint as public;
        }
    };
});

it('builds endpoint with protocol, host, and path', function () {
    $trace = new OutgoingRequestTrace();
    $trace->forceFill(['protocol' => 'https', 'host' => 'api.example.com', 'path' => '/users']);

    expect($this->builder->buildEndpoint($trace, 'OUTGOING'))->toBe('https://api.example.com/users');
});

it('builds endpoint without protocol', function () {
    $trace = new OutgoingRequestTrace();
    $trace->forceFill(['host' => 'api.example.com', 'path' => '/users']);

    expect($this->builder->buildEndpoint($trace, 'OUTGOING'))->toBe('api.example.com/users');
});

it('includes query string', function () {
    $trace = new OutgoingRequestTrace();
    $trace->forceFill(['host' => 'api.example.com', 'path' => '/search', 'query' => 'q=test']);

    expect($this->builder->buildEndpoint($trace, 'OUTGOING'))->toBe('api.example.com/search?q=test');
});

it('includes route for incoming traces', function () {
    $trace = new IncomingRequestTrace();
    $trace->forceFill(['host' => 'localhost', 'path' => '/api/users/5', 'route' => 'api/users/{id}']);

    expect($this->builder->buildEndpoint($trace, 'INCOMING'))->toBe('localhost/api/users/5 (api/users/{id})');
});

it('does not include route for outgoing traces', function () {
    $trace = new OutgoingRequestTrace();
    $trace->forceFill(['host' => 'example.com', 'path' => '/api']);

    expect($this->builder->buildEndpoint($trace, 'OUTGOING'))->toBe('example.com/api');
});

it('returns dash for empty endpoint', function () {
    $trace = new OutgoingRequestTrace();

    expect($this->builder->buildEndpoint($trace, 'OUTGOING'))->toBe('—');
});

it('handles path only without host', function () {
    $trace = new OutgoingRequestTrace();
    $trace->forceFill(['path' => '/health']);

    expect($this->builder->buildEndpoint($trace, 'OUTGOING'))->toBe('/health');
});
