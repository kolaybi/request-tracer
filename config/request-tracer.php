<?php

use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

return [
    // Database
    'connection'                 => env('REQUEST_TRACER_DB_CONNECTION'),
    'schema'                     => env('REQUEST_TRACER_DB_SCHEMA'),

    // Queue
    'queue_connection'           => env('REQUEST_TRACER_QUEUE_CONNECTION', 'redis'),
    'queue'                      => env('REQUEST_TRACER_QUEUE', 'request_logging'),

    // Tenant column name (configurable: 'company_id', 'tenant_id', 'organization_id', etc.)
    'tenant_column'              => 'tenant_id',
    'tenant_cast'                => 'integer', // Eloquent cast: 'integer', 'string', or any cast type
    'user_cast'                  => 'integer', // Eloquent cast: 'integer', 'string', or any cast type

    // Tracing options
    'max_body_size'              => (int) env('REQUEST_TRACER_MAX_BODY_SIZE', 0), // 0 = unlimited
    'retention_days'             => (int) env('REQUEST_TRACER_RETENTION_DAYS', 0), // 0 = forever
    'exclude_body_content_types' => env('REQUEST_TRACER_EXCLUDE_BODY_CONTENT_TYPES', ''), // Comma-separated prefixes (e.g. 'image/,video/,application/pdf,application/octet-stream')

    // Masking options
    'mask_sensitive'             => (bool) env('REQUEST_TRACER_MASK_SENSITIVE', false),
    'mask_value'                 => env('REQUEST_TRACER_MASK_VALUE', '[REDACTED]'),
    'sensitive_keys'             => env(
        'REQUEST_TRACER_SENSITIVE_KEYS',
        'authorization,proxy-authorization,cookie,set-cookie,x-api-key,api-key,apikey,token,access_token,refresh_token,id_token,password,passcode,secret,client_secret,private_key',
    ),

    // Context provider (app-specific: tenant, user, server info)
    'context_provider'           => null, // class-string<TraceContextProvider>

    // Outgoing request tracing
    'outgoing'                   => [
        'enabled'     => env('REQUEST_TRACER_OUTGOING_ENABLED', true),
        'table'       => 'outgoing_request_traces',
        'model'       => OutgoingRequestTrace::class,
        'sample_rate' => (float) env('REQUEST_TRACER_OUTGOING_SAMPLE_RATE', 1.0),
        'only'        => env('REQUEST_TRACER_OUTGOING_ONLY', ''), // Comma-separated host/path patterns (supports wildcards: 'api.example.com*')
        'except'      => env('REQUEST_TRACER_OUTGOING_EXCEPT', ''), // Comma-separated host/path patterns (supports wildcards: '*.internal.com*')
    ],

    // Incoming request tracing
    'incoming'                   => [
        'enabled'               => env('REQUEST_TRACER_INCOMING_ENABLED', false),
        'table'                 => 'incoming_request_traces',
        'model'                 => IncomingRequestTrace::class,
        'sample_rate'           => (float) env('REQUEST_TRACER_INCOMING_SAMPLE_RATE', 1.0),
        'only'                  => env('REQUEST_TRACER_INCOMING_ONLY', ''), // Comma-separated paths (supports wildcards: 'api/orders*')
        'except'                => env('REQUEST_TRACER_INCOMING_EXCEPT', ''), // Comma-separated paths (supports wildcards: 'health*,telescope*')
        'capture_response_body' => (bool) env('REQUEST_TRACER_INCOMING_CAPTURE_RESPONSE', false),
    ],
];
