<?php

use KolayBi\RequestTracer\Commands\Concerns\ColorsStatus;

beforeEach(function () {
    $this->colorer = new class () {
        use ColorsStatus {
            colorStatus as public;
        }
    };
});

it('colors status codes by class', function (?int $status, string $expected) {
    expect($this->colorer->colorStatus($status))->toBe($expected);
})->with([
    'null'  => [null, '<fg=white>—</>'],
    '1xx'   => [100, '<fg=white>100</>'],
    '2xx'   => [200, '<fg=green>200</>'],
    '3xx'   => [301, '<fg=cyan>301</>'],
    '4xx'   => [404, '<fg=yellow>404</>'],
    '5xx'   => [500, '<fg=red>500</>'],
]);
