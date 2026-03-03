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
        'kolaybi.request-tracer.incoming.table'  => 'custom_incoming',
        'kolaybi.request-tracer.schema'          => null,
    ]);

    expect(new IncomingRequestTrace()->getTable())->toBe('custom_incoming');
});

it('prepends schema to table name', function () {
    config([
        'kolaybi.request-tracer.incoming.table'  => 'incoming_request_traces',
        'kolaybi.request-tracer.schema'          => 'tracing',
    ]);

    expect(new IncomingRequestTrace()->getTable())->toBe('tracing.incoming_request_traces');
});
