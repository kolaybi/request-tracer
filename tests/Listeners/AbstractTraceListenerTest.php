<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Listeners\AbstractTraceListener;
use KolayBi\RequestTracer\Support\RequestTimingStore;

beforeEach(function () {
    Queue::fake();
    Context::add('trace_id', 'test-trace-id');

    $this->listener = new class () extends AbstractTraceListener {
        public function callBuildTraceAttributes(...$args): array
        {
            return $this->buildTraceAttributes(...$args);
        }

        public function callExtractTraceString(mixed $value): ?string
        {
            return $this->extractTraceString($value);
        }

        public function callExtractSoapBodyOperationName(string $request): ?string
        {
            return $this->extractSoapBodyOperationName($request);
        }

        public function callPersistTrace(array $attributes): void
        {
            $this->persistTrace($attributes);
        }

        public function callFormatException(Throwable $e): string
        {
            return $this->formatException($e);
        }

        public function callResolveStartedAt(Request $request, array $traceAttributes): string
        {
            return $this->resolveStartedAt($request, $traceAttributes);
        }

        public function callExtractSoapAction(string $action, string $request): string
        {
            return $this->extractSoapAction($action, $request);
        }

        public function callResolveTraceId(): ?string
        {
            return $this->resolveTraceId();
        }

        public function callStripTraceHeaders(array $headers): array
        {
            return $this->stripTraceHeaders($headers);
        }

        public function callExtractTraceAttributes(array $attributes): array
        {
            return $this->extractTraceAttributes($attributes);
        }
    };
});

// ──────────────────────────────────────────────────
// Original tests
// ──────────────────────────────────────────────────

it('extractTraceString returns last element of array', function () {
    expect($this->listener->callExtractTraceString(['first', 'last']))->toBe('last');
});

it('extractTraceString returns null for empty string', function () {
    expect($this->listener->callExtractTraceString(''))->toBeNull();
});

it('extractTraceString returns null for null', function () {
    expect($this->listener->callExtractTraceString(null))->toBeNull();
});

it('extractTraceString casts non-string to string', function () {
    expect($this->listener->callExtractTraceString(42))->toBe('42');
});

it('extractSoapBodyOperationName returns null for invalid XML', function () {
    expect($this->listener->callExtractSoapBodyOperationName('not xml'))->toBeNull();
});

it('extractSoapBodyOperationName extracts operation from valid SOAP envelope', function () {
    $xml = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body><GetInvoice/></SOAP-ENV:Body></SOAP-ENV:Envelope>';

    expect($this->listener->callExtractSoapBodyOperationName($xml))->toBe('GetInvoice');
});

it('persistTrace respects sample rate 1.0 (always trace)', function () {
    config(['kolaybi.request-tracer.outgoing.sample_rate' => 1.0]);

    $this->listener->callPersistTrace(['host' => 'example.com', 'start' => null, 'end' => null]);

    Queue::assertPushed(StoreTraceJob::class);
});

it('persistTrace drops trace at sample rate 0.0', function () {
    config(['kolaybi.request-tracer.outgoing.sample_rate' => 0.0]);

    $this->listener->callPersistTrace(['host' => 'example.com', 'start' => null, 'end' => null]);

    Queue::assertNotPushed(StoreTraceJob::class);
});

// ──────────────────────────────────────────────────
// Depth: buildTraceAttributes
// ──────────────────────────────────────────────────

it('buildTraceAttributes masks sensitive query params', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'access_token',
    ]);

    $attrs = $this->listener->callBuildTraceAttributes(
        url: 'https://api.example.com/v1/users?access_token=secret&page=1',
        method: 'GET',
        body: '',
        headers: [],
    );

    expect($attrs['query'])->toContain('access_token=%5BREDACTED%5D')
        ->and($attrs['query'])->toContain('page=1');
});

it('buildTraceAttributes populates all expected keys', function () {
    $attrs = $this->listener->callBuildTraceAttributes(
        url: 'https://api.example.com/v1/users?page=2',
        method: 'POST',
        body: '{"name":"John"}',
        headers: ['Content-Type' => 'application/json'],
        channel: 'payment',
        extra: ['order_id' => 42],
        start: '2026-01-01 00:00:00.000000',
    );

    expect($attrs)
        ->toHaveKey('trace_id', 'test-trace-id')
        ->toHaveKey('channel', 'payment')
        ->toHaveKey('method', 'POST')
        ->toHaveKey('host', 'api.example.com')
        ->toHaveKey('path', '/v1/users')
        ->toHaveKey('query', 'page=2')
        ->toHaveKey('request_size', 15) // strlen('{"name":"John"}')
        ->toHaveKey('start', '2026-01-01 00:00:00.000000')
        ->toHaveKey('extra', '{"order_id":42}')
        ->toHaveKey('response_body', null)
        ->toHaveKey('response_headers', null)
        ->toHaveKey('response_size', null)
        ->toHaveKey('message', null)
        ->toHaveKey('exception', null)
        ->toHaveKey('stats', null);
});

it('buildTraceAttributes handles URL with no path or query', function () {
    $attrs = $this->listener->callBuildTraceAttributes(
        url: 'https://example.com',
        method: 'GET',
        body: '',
        headers: [],
    );

    expect($attrs['host'])->toBe('example.com')
        ->and($attrs['path'])->toBeNull()
        ->and($attrs['query'])->toBeNull();
});

it('buildTraceAttributes passes string extra as-is (not JSON-encoded)', function () {
    $attrs = $this->listener->callBuildTraceAttributes(
        url: 'https://example.com/test',
        method: 'GET',
        body: '',
        headers: [],
        extra: 'raw-string-extra',
    );

    expect($attrs['extra'])->toBe('raw-string-extra');
});

it('buildTraceAttributes passes null extra as null', function () {
    $attrs = $this->listener->callBuildTraceAttributes(
        url: 'https://example.com/test',
        method: 'GET',
        body: '',
        headers: [],
        extra: null,
    );

    expect($attrs['extra'])->toBeNull();
});

// ──────────────────────────────────────────────────
// Depth: formatException
// ──────────────────────────────────────────────────

it('formatException includes file, line number, and stack trace', function () {
    $line = __LINE__ + 1;
    $exception = new RuntimeException('Test error');

    $formatted = $this->listener->callFormatException($exception);

    expect($formatted)
        ->toContain(__FILE__ . ':' . $line)
        ->toContain('#0'); // stack trace frame
});

// ──────────────────────────────────────────────────
// Depth: resolveStartedAt
// ──────────────────────────────────────────────────

it('resolveStartedAt prefers traceAttributes started_at', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);

    $result = $this->listener->callResolveStartedAt($request, [
        'started_at' => '2026-01-01 12:00:00.000000',
    ]);

    expect($result)->toBe('2026-01-01 12:00:00.000000');
});

it('resolveStartedAt falls back to RequestTimingStore', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);

    RequestTimingStore::stamp($psrRequest, '2026-02-01 08:00:00.000000');

    $result = $this->listener->callResolveStartedAt($request, []);

    expect($result)->toBe('2026-02-01 08:00:00.000000');
});

it('resolveStartedAt falls back to Timestamp::now() as last resort', function () {
    $psrRequest = new GuzzleHttp\Psr7\Request('GET', 'https://example.com');
    $request = new Request($psrRequest);

    $before = now()->format('Y-m-d H:i:s');
    $result = $this->listener->callResolveStartedAt($request, []);
    $after = now()->addSecond()->format('Y-m-d H:i:s');

    // Result should be a timestamp string in the current timeframe
    expect($result)->toBeString()
        ->and(substr($result, 0, 10))->toBe(now()->format('Y-m-d'));
});

// ──────────────────────────────────────────────────
// Depth: extractSoapAction
// ──────────────────────────────────────────────────

it('extractSoapAction strips tempuri prefix', function () {
    $result = $this->listener->callExtractSoapAction(
        'http://tempuri.org/GetInvoice',
        '<irrelevant/>',
    );

    expect($result)->toBe('GetInvoice');
});

it('extractSoapAction returns full action when not tempuri', function () {
    $result = $this->listener->callExtractSoapAction(
        'https://custom.namespace.com/GetInvoice',
        '<irrelevant/>',
    );

    expect($result)->toBe('https://custom.namespace.com/GetInvoice');
});

it('extractSoapAction falls back to body extraction when tempuri strip leaves empty', function () {
    $soapBody = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body><SendDocument/></SOAP-ENV:Body></SOAP-ENV:Envelope>';

    $result = $this->listener->callExtractSoapAction(
        'http://tempuri.org/',
        $soapBody,
    );

    expect($result)->toBe('SendDocument');
});

// ──────────────────────────────────────────────────
// Depth: extractTraceAttributes
// ──────────────────────────────────────────────────

it('extractTraceAttributes returns empty array when key is missing', function () {
    $result = $this->listener->callExtractTraceAttributes([
        'some_other_key' => 'value',
    ]);

    expect($result)->toBe([]);
});

it('extractTraceAttributes returns the request_tracer array when present', function () {
    $result = $this->listener->callExtractTraceAttributes([
        'request_tracer' => ['channel' => 'api', 'extra' => ['x' => 1]],
    ]);

    expect($result)->toBe(['channel' => 'api', 'extra' => ['x' => 1]]);
});

it('extractTraceAttributes returns empty array when value is not an array', function () {
    $result = $this->listener->callExtractTraceAttributes([
        'request_tracer' => 'not-an-array',
    ]);

    expect($result)->toBe([]);
});

// ──────────────────────────────────────────────────
// Depth: outgoing URL filtering (only / except)
// ──────────────────────────────────────────────────

it('traces URL when no only/except patterns configured', function () {
    config([
        'kolaybi.request-tracer.outgoing.only'        => '',
        'kolaybi.request-tracer.outgoing.except'      => '',
        'kolaybi.request-tracer.outgoing.sample_rate' => 1.0,
    ]);

    $this->listener->callPersistTrace(['host' => 'api.example.com', 'path' => '/v1/orders']);

    Queue::assertPushed(StoreTraceJob::class);
});

it('traces URL matching only patterns', function () {
    config([
        'kolaybi.request-tracer.outgoing.only'        => 'api.example.com*',
        'kolaybi.request-tracer.outgoing.sample_rate' => 1.0,
    ]);

    $this->listener->callPersistTrace(['host' => 'api.example.com', 'path' => '/v1/orders']);

    Queue::assertPushed(StoreTraceJob::class);
});

it('skips URL not matching only patterns', function () {
    config([
        'kolaybi.request-tracer.outgoing.only'        => 'api.example.com*',
        'kolaybi.request-tracer.outgoing.sample_rate' => 1.0,
    ]);

    $this->listener->callPersistTrace(['host' => 'internal.service.local', 'path' => '/health']);

    Queue::assertNotPushed(StoreTraceJob::class);
});

it('skips URL matching except patterns', function () {
    config([
        'kolaybi.request-tracer.outgoing.only'        => '',
        'kolaybi.request-tracer.outgoing.except'      => '*.internal.com*',
        'kolaybi.request-tracer.outgoing.sample_rate' => 1.0,
    ]);

    $this->listener->callPersistTrace(['host' => 'svc.internal.com', 'path' => '/api/data']);

    Queue::assertNotPushed(StoreTraceJob::class);
});

it('traces URL not matching except patterns', function () {
    config([
        'kolaybi.request-tracer.outgoing.only'        => '',
        'kolaybi.request-tracer.outgoing.except'      => '*.internal.com*',
        'kolaybi.request-tracer.outgoing.sample_rate' => 1.0,
    ]);

    $this->listener->callPersistTrace(['host' => 'api.example.com', 'path' => '/v1/orders']);

    Queue::assertPushed(StoreTraceJob::class);
});

it('only takes precedence over except for outgoing', function () {
    config([
        'kolaybi.request-tracer.outgoing.only'        => 'api.example.com*',
        'kolaybi.request-tracer.outgoing.except'      => 'api.example.com/v1/orders*',
        'kolaybi.request-tracer.outgoing.sample_rate' => 1.0,
    ]);

    $this->listener->callPersistTrace(['host' => 'api.example.com', 'path' => '/v1/orders']);

    // 'only' matches, so it's traced — 'except' is ignored when 'only' is set
    Queue::assertPushed(StoreTraceJob::class);
});
