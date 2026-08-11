<?php

use KolayBi\RequestTracer\Enums\TraceDirection;

it('parses direction input case insensitively', function (string $input, TraceDirection $expected) {
    expect(TraceDirection::tryFromInput($input))->toBe($expected);
})->with([
    ['outgoing', TraceDirection::Outgoing],
    ['OUTGOING', TraceDirection::Outgoing],
    ['Outgoing', TraceDirection::Outgoing],
    ['incoming', TraceDirection::Incoming],
    ['INCOMING', TraceDirection::Incoming],
]);

it('returns null for null, empty and invalid input', function (?string $input) {
    expect(TraceDirection::tryFromInput($input))->toBeNull();
})->with([
    'null'    => [null],
    'empty'   => [''],
    'invalid' => ['sideways'],
]);
