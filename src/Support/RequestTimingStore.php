<?php

namespace KolayBi\RequestTracer\Support;

use Psr\Http\Message\RequestInterface;
use WeakMap;

class RequestTimingStore
{
    /** @var WeakMap<RequestInterface, string>|null */
    private static ?WeakMap $timestamps = null;

    public static function stamp(RequestInterface $psrRequest, string $timestamp): void
    {
        self::$timestamps ??= new WeakMap();
        self::$timestamps[$psrRequest] = $timestamp;
    }

    public static function pull(RequestInterface $psrRequest): ?string
    {
        $timestamp = (self::$timestamps ?? new WeakMap())[$psrRequest] ?? null;

        if (null !== $timestamp) {
            unset(self::$timestamps[$psrRequest]);
        }

        return $timestamp;
    }
}
