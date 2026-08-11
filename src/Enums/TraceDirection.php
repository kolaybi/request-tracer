<?php

namespace KolayBi\RequestTracer\Enums;

enum TraceDirection: string
{
    case Incoming = 'INCOMING';
    case Outgoing = 'OUTGOING';

    public static function tryFromInput(?string $value): ?self
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return self::tryFrom(strtoupper($value));
    }
}
