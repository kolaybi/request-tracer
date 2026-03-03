<?php

use GuzzleHttp\Psr7\Request;
use KolayBi\RequestTracer\Support\RequestTimingStore;

it('stores and pulls a timestamp for a PSR request', function () {
    $request = new Request('GET', 'https://example.com');

    RequestTimingStore::stamp($request, '2026-01-01 00:00:00.000000');

    expect(RequestTimingStore::pull($request))->toBe('2026-01-01 00:00:00.000000');
});

it('returns null when no timestamp was stored', function () {
    $request = new Request('GET', 'https://example.com/unknown');

    expect(RequestTimingStore::pull($request))->toBeNull();
});

it('removes timestamp after pull', function () {
    $request = new Request('GET', 'https://example.com/once');

    RequestTimingStore::stamp($request, '2026-01-01 00:00:00.000000');
    RequestTimingStore::pull($request);

    expect(RequestTimingStore::pull($request))->toBeNull();
});

it('stores separate timestamps for different requests', function () {
    $request1 = new Request('GET', 'https://example.com/a');
    $request2 = new Request('GET', 'https://example.com/b');

    RequestTimingStore::stamp($request1, 'time-a');
    RequestTimingStore::stamp($request2, 'time-b');

    expect(RequestTimingStore::pull($request1))->toBe('time-a')
        ->and(RequestTimingStore::pull($request2))->toBe('time-b');
});
