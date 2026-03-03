<?php

use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;
use KolayBi\RequestTracer\Support\TraceHelper;

beforeEach(function () {
    Queue::fake();

    // Reset the static cache between tests.
    $reflection = new ReflectionClass(TraceHelper::class);
    $property = $reflection->getProperty('cachedSensitiveKeys');
    $property->setValue(null, null);
});

// --- dispatchTrace ---

it('dispatches StoreTraceJob with attributes and model class', function () {
    TraceHelper::dispatchTrace(
        ['host' => 'example.com', 'start' => '2026-01-01 00:00:00.000000', 'end' => '2026-01-01 00:00:01.000000'],
        OutgoingRequestTrace::class,
    );

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return OutgoingRequestTrace::class === $job->modelClass
            && 'example.com' === $job->attributes['host']
            && 1000 === $job->attributes['duration'];
    });
});

// --- calculateDuration ---

it('calculates duration in milliseconds', function () {
    $result = TraceHelper::calculateDuration('2026-01-01 00:00:00.000000', '2026-01-01 00:00:02.500000');

    expect($result)->toBe(2500);
});

it('returns null when start is null', function () {
    expect(TraceHelper::calculateDuration(null, '2026-01-01 00:00:00.000000'))->toBeNull();
});

it('returns null when end is null', function () {
    expect(TraceHelper::calculateDuration('2026-01-01 00:00:00.000000', null))->toBeNull();
});

// --- normalizeHeaders ---

it('encodes array headers as JSON', function () {
    $result = TraceHelper::normalizeHeaders(['Content-Type' => ['application/json']]);

    expect($result)->toBe('{"Content-Type":["application/json"]}');
});

it('returns string headers as-is', function () {
    $raw = "Content-Type: text/html\r\nHost: example.com";

    expect(TraceHelper::normalizeHeaders($raw))->toBe($raw);
});

it('masks sensitive array headers when enabled', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'authorization,cookie',
    ]);

    $result = TraceHelper::normalizeHeaders([
        'Authorization' => ['Bearer token123'],
        'Content-Type'  => ['application/json'],
    ]);

    $decoded = json_decode($result, true);

    expect($decoded['Authorization'])->toBe('[REDACTED]')
        ->and($decoded['Content-Type'])->toBe(['application/json']);
});

it('masks sensitive string headers when enabled', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'authorization',
    ]);

    $result = TraceHelper::normalizeHeaders("Authorization: Bearer token123\r\nContent-Type: text/html");

    expect($result)->toContain('Authorization: [REDACTED]')
        ->and($result)->toContain('Content-Type: text/html');
});

// --- normalizeBody ---

it('returns body as-is for simple strings', function () {
    expect(TraceHelper::normalizeBody('hello world'))->toBe('hello world');
});

it('masks sensitive JSON body keys when enabled', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'password',
    ]);

    $body = json_encode(['username' => 'john', 'password' => 'secret']);
    $result = TraceHelper::normalizeBody($body);

    $decoded = json_decode($result, true);

    expect($decoded['password'])->toBe('[REDACTED]')
        ->and($decoded['username'])->toBe('john');
});

it('masks sensitive form-encoded body keys when enabled', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'password',
    ]);

    $result = TraceHelper::normalizeBody('username=john&password=secret');

    expect($result)->toContain('password=%5BREDACTED%5D')
        ->and($result)->toContain('username=john');
});

it('truncates body when max_body_size is set', function () {
    config(['kolaybi.request-tracer.max_body_size' => 20]);

    $body = str_repeat('a', 100);
    $result = TraceHelper::normalizeBody($body);

    expect(strlen($result))->toBe(20)
        ->and($result)->toEndWith('... [truncated]');
});

it('does not truncate body when within limit', function () {
    config(['kolaybi.request-tracer.max_body_size' => 1000]);

    $body = 'short body';

    expect(TraceHelper::normalizeBody($body))->toBe('short body');
});

it('returns full body when max_body_size is 0', function () {
    config(['kolaybi.request-tracer.max_body_size' => 0]);

    $body = str_repeat('x', 10000);

    expect(TraceHelper::normalizeBody($body))->toBe($body);
});

it('base64 encodes non-UTF8 body', function () {
    $binary = "\x80\x81\x82\x83";

    $result = TraceHelper::normalizeBody($binary);

    expect($result)->toBe(base64_encode($binary));
});

it('masks nested JSON keys', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'token',
    ]);

    $body = json_encode(['data' => ['token' => 'abc123', 'name' => 'test']]);
    $result = TraceHelper::normalizeBody($body);
    $decoded = json_decode($result, true);

    expect($decoded['data']['token'])->toBe('[REDACTED]')
        ->and($decoded['data']['name'])->toBe('test');
});

it('uses custom mask value', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'password',
        'kolaybi.request-tracer.mask_value'     => '***',
    ]);

    $body = json_encode(['password' => 'secret']);
    $result = TraceHelper::normalizeBody($body);
    $decoded = json_decode($result, true);

    expect($decoded['password'])->toBe('***');
});

it('handles very small max_body_size without suffix', function () {
    config(['kolaybi.request-tracer.max_body_size' => 5]);

    $result = TraceHelper::normalizeBody(str_repeat('a', 100));

    expect($result)->toBe('aaaaa')
        ->and(strlen($result))->toBe(5);
});

it('caches sensitive keys across calls', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'authorization',
    ]);

    TraceHelper::normalizeHeaders(['Authorization' => ['token1']]);

    // Change the config — should still use cached value
    config(['kolaybi.request-tracer.sensitive_keys' => 'x-custom-key']);

    $result = TraceHelper::normalizeHeaders(['Authorization' => ['token2']]);
    $decoded = json_decode($result, true);

    // Still masked because cached keys contain 'authorization'
    expect($decoded['Authorization'])->toBe('[REDACTED]');
});

it('normalizes underscored sensitive keys to dashed', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'x_api_key',
    ]);

    $result = TraceHelper::normalizeHeaders(['X-Api-Key' => ['secret']]);
    $decoded = json_decode($result, true);

    expect($decoded['X-Api-Key'])->toBe('[REDACTED]');
});

it('does not mask when mask_sensitive is false', function () {
    config(['kolaybi.request-tracer.mask_sensitive' => false]);

    $result = TraceHelper::normalizeHeaders(['Authorization' => ['Bearer token']]);
    $decoded = json_decode($result, true);

    expect($decoded['Authorization'])->toBe(['Bearer token']);
});

it('skips non-header lines in string header masking', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'authorization',
    ]);

    $headers = "HTTP/1.1 200 OK\r\nAuthorization: Bearer token\r\nContent-Type: text/html";
    $result = TraceHelper::normalizeHeaders($headers);

    expect($result)->toContain('HTTP/1.1 200 OK')
        ->and($result)->toContain('Authorization: [REDACTED]')
        ->and($result)->toContain('Content-Type: text/html');
});

it('returns empty body unchanged when masking enabled', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'password',
    ]);

    expect(TraceHelper::normalizeBody(''))->toBe('');
});

it('returns plain text body unchanged when masking enabled', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 'password',
    ]);

    expect(TraceHelper::normalizeBody('plain text body without any structure'))->toBe('plain text body without any structure');
});

it('handles non-array non-string sensitive_keys config', function () {
    config([
        'kolaybi.request-tracer.mask_sensitive' => true,
        'kolaybi.request-tracer.sensitive_keys' => 42,
    ]);

    // Should not throw, just mask nothing
    $result = TraceHelper::normalizeHeaders(['Authorization' => ['Bearer token']]);
    $decoded = json_decode($result, true);

    expect($decoded['Authorization'])->toBe(['Bearer token']);
});
