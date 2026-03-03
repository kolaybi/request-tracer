<?php

use Carbon\CarbonImmutable;
use KolayBi\RequestTracer\Support\Timestamp;

it('returns a formatted datetime string with microseconds', function () {
    $result = Timestamp::now();

    expect($result)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/');
});

it('returns a parseable datetime', function () {
    $result = Timestamp::now();

    $parsed = CarbonImmutable::parse($result);

    expect($parsed)->toBeInstanceOf(CarbonImmutable::class);
});
