<?php

use KolayBi\RequestTracer\Models\IncomingRequestTrace;

it('has no timestamps', function () {
    $model = new IncomingRequestTrace();

    expect($model->timestamps)->toBeFalse();
});

it('has an empty guarded array', function () {
    $model = new IncomingRequestTrace();

    expect($model->getGuarded())->toBe([]);
});

it('uses configured connection', function () {
    config(['kolaybi.request-tracer.connection' => 'tracing_db']);

    expect(new IncomingRequestTrace()->getConnectionName())->toBe('tracing_db');
});

it('returns null connection by default', function () {
    config(['kolaybi.request-tracer.connection' => null]);

    expect(new IncomingRequestTrace()->getConnectionName())->toBeNull();
});

it('uses configured table name', function () {
    config([
        'kolaybi.request-tracer.incoming.table' => 'custom_incoming',
        'kolaybi.request-tracer.schema'         => null,
    ]);

    expect(new IncomingRequestTrace()->getTable())->toBe('custom_incoming');
});

it('prepends schema to table name', function () {
    config([
        'kolaybi.request-tracer.incoming.table' => 'incoming_request_traces',
        'kolaybi.request-tracer.schema'         => 'tracing',
    ]);

    expect(new IncomingRequestTrace()->getTable())->toBe('tracing.incoming_request_traces');
});

it('casts integer columns to proper types', function () {
    $model = new IncomingRequestTrace();
    $casts = $model->getCasts();

    expect($casts)
        ->toHaveKey('duration', 'integer')
        ->toHaveKey('status', 'integer')
        ->toHaveKey('request_size', 'integer')
        ->toHaveKey('response_size', 'integer')
        ->toHaveKey('user_id', 'integer')
        ->toHaveKey('tenant_id', 'integer');
});

it('casts custom tenant column', function () {
    config(['kolaybi.request-tracer.tenant_column' => 'company_id']);

    $casts = new IncomingRequestTrace()->getCasts();

    expect($casts)->toHaveKey('company_id', 'integer')
        ->not->toHaveKey('tenant_id');
});

it('uses configured tenant and user cast types', function () {
    config([
        'kolaybi.request-tracer.tenant_cast' => 'string',
        'kolaybi.request-tracer.user_cast'   => 'string',
    ]);

    $casts = new IncomingRequestTrace()->getCasts();

    expect($casts)
        ->toHaveKey('tenant_id', 'string')
        ->toHaveKey('user_id', 'string');
});
