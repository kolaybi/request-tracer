<?php

use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

it('has no timestamps', function () {
    $model = new OutgoingRequestTrace();

    expect($model->timestamps)->toBeFalse();
});

it('has an empty guarded array', function () {
    $model = new OutgoingRequestTrace();

    expect($model->getGuarded())->toBe([]);
});

it('uses configured connection', function () {
    config(['kolaybi.request-tracer.connection' => 'tracing_db']);

    $model = new OutgoingRequestTrace();

    expect($model->getConnectionName())->toBe('tracing_db');
});

it('returns null connection by default', function () {
    config(['kolaybi.request-tracer.connection' => null]);

    expect(new OutgoingRequestTrace()->getConnectionName())->toBeNull();
});

it('uses configured table name', function () {
    config([
        'kolaybi.request-tracer.outgoing.table' => 'custom_outgoing',
        'kolaybi.request-tracer.schema'         => null,
    ]);

    expect(new OutgoingRequestTrace()->getTable())->toBe('custom_outgoing');
});

it('prepends schema to table name', function () {
    config([
        'kolaybi.request-tracer.outgoing.table' => 'outgoing_request_traces',
        'kolaybi.request-tracer.schema'         => 'tracing',
    ]);

    expect(new OutgoingRequestTrace()->getTable())->toBe('tracing.outgoing_request_traces');
});

it('uses default table name from config fallback', function () {
    expect(new OutgoingRequestTrace()->getTable())->toBe('outgoing_request_traces');
});

it('casts integer columns to proper types', function () {
    $model = new OutgoingRequestTrace();
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

    $casts = new OutgoingRequestTrace()->getCasts();

    expect($casts)->toHaveKey('company_id', 'integer')
        ->not->toHaveKey('tenant_id');
});

it('uses configured tenant and user cast types', function () {
    config([
        'kolaybi.request-tracer.tenant_cast' => 'string',
        'kolaybi.request-tracer.user_cast'   => 'string',
    ]);

    $casts = new OutgoingRequestTrace()->getCasts();

    expect($casts)
        ->toHaveKey('tenant_id', 'string')
        ->toHaveKey('user_id', 'string');
});
