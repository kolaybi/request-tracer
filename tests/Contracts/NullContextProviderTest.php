<?php

use KolayBi\RequestTracer\Contracts\NullContextProvider;

it('returns null for tenant id', function () {
    expect(new NullContextProvider()->tenantId())->toBeNull();
});

it('returns null for user id', function () {
    expect(new NullContextProvider()->userId())->toBeNull();
});

it('returns request ip for client ip', function () {
    $provider = new NullContextProvider();

    // In test context, request()->ip() returns 127.0.0.1
    expect($provider->clientIp())->toBeString();
});

it('returns hostname for server identifier', function () {
    $provider = new NullContextProvider();

    expect($provider->serverIdentifier())->toBe(gethostname());
});
