<?php

namespace KolayBi\RequestTracer\Support;

use Carbon\CarbonImmutable;

class Timestamp
{
    private const string FORMAT = 'Y-m-d H:i:s.u';

    public static function now(): string
    {
        return CarbonImmutable::now()->format(self::FORMAT);
    }
}
