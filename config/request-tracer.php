<?php

use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

return [
    // Database
    'connection'       => env('REQUEST_TRACER_DB_CONNECTION'),
    'schema'           => env('REQUEST_TRACER_DB_SCHEMA'),

    // Queue
    'queue_connection' => env('REQUEST_TRACER_QUEUE_CONNECTION', 'redis'),
    'queue'            => env('REQUEST_TRACER_QUEUE', 'request_logging'),

    // Tenant column name (configurable: 'company_id', 'tenant_id', 'organization_id', etc.)
    'tenant_column'    => 'tenant_id',

    // Tracing options
    'max_body_size'    => (int) env('REQUEST_TRACER_MAX_BODY_SIZE', 0), // 0 = unlimited
    'retention_days'   => (int) env('REQUEST_TRACER_RETENTION_DAYS', 0), // 0 = forever

    // Context provider (app-specific: tenant, user, server info)
    'context_provider' => null, // class-string<TraceContextProvider>

    // Outgoing request tracing
    'outgoing'         => [
        'enabled'     => env('REQUEST_TRACER_OUTGOING_ENABLED', true),
        'table'       => 'outgoing_request_traces',
        'model'       => OutgoingRequestTrace::class,
        'sample_rate' => (float) env('REQUEST_TRACER_SAMPLE_RATE', 1.0),
    ],

    // Incoming request tracing
    'incoming'         => [
        'enabled'               => env('REQUEST_TRACER_INCOMING_ENABLED', false),
        'table'                 => 'incoming_request_traces',
        'model'                 => IncomingRequestTrace::class,
        'capture_response_body' => (bool) env('REQUEST_TRACER_INCOMING_CAPTURE_RESPONSE', false),
    ],
];
