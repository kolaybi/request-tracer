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
        'kolaybi.request-tracer.outgoing.table'  => 'custom_outgoing',
        'kolaybi.request-tracer.schema'          => null,
    ]);

    expect(new OutgoingRequestTrace()->getTable())->toBe('custom_outgoing');
});

it('prepends schema to table name', function () {
    config([
        'kolaybi.request-tracer.outgoing.table'  => 'outgoing_request_traces',
        'kolaybi.request-tracer.schema'          => 'tracing',
    ]);

    expect(new OutgoingRequestTrace()->getTable())->toBe('tracing.outgoing_request_traces');
});

it('uses default table name from config fallback', function () {
    expect(new OutgoingRequestTrace()->getTable())->toBe('outgoing_request_traces');
});
