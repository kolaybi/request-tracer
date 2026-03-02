# Request Tracer

Standalone request tracing package for Laravel. Captures outgoing HTTP and SOAP requests, and optionally incoming requests. All traces are stored asynchronously via queued jobs.

## Requirements

- PHP 8.4+
- Laravel 12+
- `ext-soap` and `ext-simplexml` (only if using SOAP tracing)

## Installation

```bash
composer require kolaybi/request-tracer
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag=request-tracer-config
php artisan migrate
```

## Configuration

```php
// config/request-tracer.php

return [
    'connection'       => env('REQUEST_TRACER_DB_CONNECTION'),
    'schema'           => env('REQUEST_TRACER_DB_SCHEMA'),

    'queue_connection' => env('REQUEST_TRACER_QUEUE_CONNECTION', 'redis'),
    'queue'            => env('REQUEST_TRACER_QUEUE', 'request_logging'),

    'tenant_column'    => 'tenant_id',

    'max_body_size'    => (int) env('REQUEST_TRACER_MAX_BODY_SIZE', 0),
    'retention_days'   => (int) env('REQUEST_TRACER_RETENTION_DAYS', 0),

    'mask_sensitive'   => (bool) env('REQUEST_TRACER_MASK_SENSITIVE', false),
    'mask_value'       => env('REQUEST_TRACER_MASK_VALUE', '[REDACTED]'),
    'sensitive_keys'   => env(
        'REQUEST_TRACER_SENSITIVE_KEYS',
        'authorization,proxy-authorization,cookie,set-cookie,x-api-key,api-key,apikey,token,access_token,refresh_token,id_token,password,passcode,secret,client_secret,private_key',
    ),

    'context_provider' => null,

    'outgoing' => [
        'enabled'     => env('REQUEST_TRACER_OUTGOING_ENABLED', true),
        'table'       => 'outgoing_request_traces',
        'model'       => OutgoingRequestTrace::class,
        'sample_rate' => (float) env('REQUEST_TRACER_SAMPLE_RATE', 1.0),
    ],

    'incoming' => [
        'enabled'               => env('REQUEST_TRACER_INCOMING_ENABLED', false),
        'table'                 => 'incoming_request_traces',
        'model'                 => IncomingRequestTrace::class,
        'capture_response_body' => (bool) env('REQUEST_TRACER_INCOMING_CAPTURE_RESPONSE', false),
    ],
];
```

If `REQUEST_TRACER_MASK_SENSITIVE=true`, matching keys in headers and JSON/form payloads are masked before storage.

## Middleware

Add `RequestTracerMiddleware` to your middleware stack. It generates a `trace_id` for every request and records incoming traces when enabled.

```php
// bootstrap/app.php

use KolayBi\RequestTracer\Middleware\RequestTracerMiddleware;

->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(RequestTracerMiddleware::class);
})
```

## Context Provider

Implement `TraceContextProvider` to supply tenant, user, and server information:

```php
use KolayBi\RequestTracer\Contracts\TraceContextProvider;

class AppTraceContextProvider implements TraceContextProvider
{
    public function tenantId(): int|string|null
    {
        return auth()->user()?->company_id;
    }

    public function userId(): int|string|null
    {
        return auth()->id();
    }

    public function clientIp(): ?string
    {
        return request()?->ip();
    }

    public function serverIdentifier(): ?string
    {
        return gethostname();
    }
}
```

Register it in your config:

```php
'context_provider' => AppTraceContextProvider::class,
```

## Usage

### HTTP Tracing

Outgoing HTTP requests are traced automatically when `outgoing.enabled` is `true`. Use `channel()` or `traceOf()` to tag requests:

```php
Http::channel('payment-gateway')->post('https://api.example.com/charge', $data);

Http::traceOf('bank-api')
    ->withTraceExtra(['order_id' => 123])
    ->get('https://bank.example.com/status');
```

### SOAP Tracing

Extend `TracingSoapClient` for traced SOAP calls:

```php
use KolayBi\RequestTracer\Soap\TracingSoapClient;

$client = TracingSoapClient::with('https://service.example.com?wsdl');

$client->channel('e-invoice')->SomeOperation($params);
```

`TracingSoapClient` supports lazy initialization — set the WSDL later if needed:

```php
$client = new TracingSoapClient();
$client->setWsdl('https://service.example.com?wsdl');
$client->setOptions(['soap_version' => SOAP_1_2]);
```

### Incoming Tracing

Enable in config:

```env
REQUEST_TRACER_INCOMING_ENABLED=true
```

The middleware records every incoming request with method, path, route, status, timing, headers, and optionally the response body.

## Debugging

Display a chronological waterfall of all traces linked to a `trace_id`:

```bash
php artisan request-tracer:waterfall 01JEXAMPLE123
```

The command first prints a summary header:

```
Trace ID …………………………………………………… 01JEXAMPLE123
Tenant ID ………………………………………………… 42
User ID ……………………………………………………… 7
Client IP ………………………………………………… 192.168.1.10
First Start …………………………………………… 2026-02-28 12:00:00.000
Last End …………………………………………………… 2026-02-28 12:00:01.250
Total Duration …………………………………… 1250ms
```

Followed by a waterfall table of all traces sorted by start time:

```
+---+-----+----------+------+-----------------------------------------------+--------+----------+-----------------+--------+-------------------------+
| # | ID  | Type     | Method | Endpoint                                    | Status | Duration | Channel         | Server | Start                   |
+---+-----+----------+------+-----------------------------------------------+--------+----------+-----------------+--------+-------------------------+
| 1 | 501 | INCOMING | POST | api.example.com/webhooks (api/webhooks)       | 200    | 1250ms   | —               | web-01 | 2026-02-28 12:00:00.000 |
| 2 | 830 | OUTGOING | GET  | https://bank.example.com/status?ref=abc       | 200    | 320ms    | bank-api        | web-01 | 2026-02-28 12:00:00.100 |
| 3 | 831 | OUTGOING | POST | https://payment.example.com/charge            | 201    | 890ms    | payment-gateway | web-01 | 2026-02-28 12:00:00.350 |
+---+-----+----------+------+-----------------------------------------------+--------+----------+-----------------+--------+-------------------------+
```

This is useful for inspecting the full request flow — incoming request plus all outgoing calls it triggered — in chronological order.

### Inspect

Drill into a single trace by its ULID with progressive verbosity:

```bash
# Metadata only (type, method, endpoint, status, duration, timing, sizes, etc.)
php artisan request-tracer:inspect 01JEXAMPLE123

# + request/response headers
php artisan request-tracer:inspect 01JEXAMPLE123 -v

# + request/response body (truncated to 20 lines)
php artisan request-tracer:inspect 01JEXAMPLE123 -vv

# + body at 40 lines + exception, message, stats, extra (outgoing)
php artisan request-tracer:inspect 01JEXAMPLE123 -vvv

# Everything, no truncation
php artisan request-tracer:inspect 01JEXAMPLE123 --full
```

The command searches both incoming and outgoing tables automatically — no need to specify which.

## Data Retention

Purge old traces with the artisan command:

```bash
php artisan request-tracer:purge --days=30
php artisan request-tracer:purge --days=90 --chunk=10000
```

Or set `retention_days` in config and schedule it:

```php
$schedule->command('request-tracer:purge')->daily();
```

## How It Works

### Outgoing Traces

- `Http::globalRequestMiddleware` attaches `X-Trace-Started-At` to each request
- `Http::globalResponseMiddleware` attaches `X-Trace-Finished-At` to each response
- Channel and extra metadata are carried as `X-Trace-Channel` and `X-Trace-Extra` headers (per-request, no shared state)
- Event listeners (`ResponseReceived`, `ConnectionFailed`) build trace attributes and dispatch `StoreTraceJob`
- All `X-Trace-*` headers are stripped before persisting

### Incoming Traces

- `RequestTracerMiddleware` generates a `trace_id` and records start time
- After the response is returned, `IncomingTraceRecorder` builds attributes and dispatches `StoreTraceJob`
- The same `trace_id` links incoming and outgoing traces within a request

### SOAP Traces

- `TracingSoapClient` stores channel/extra as instance properties
- `__doRequest()` dispatches events with timing, channel, and extra
- Properties are reset after each call (no leakage between calls)

### Workers / Queue Jobs

- The middleware does not run on workers, but outgoing tracing works identically
- `trace_id` is lazily generated via `Context` on the first outgoing call
- Laravel resets `Context` between queue jobs, so each job gets a fresh `trace_id`

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](https://github.com/kolaybi/.github/blob/master/CONTRIBUTING.md) for details.

## License

Please see [License File](LICENSE.md) for more information.
