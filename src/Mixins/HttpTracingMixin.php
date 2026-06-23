<?php

namespace KolayBi\RequestTracer\Mixins;

use Closure;
use Illuminate\Support\Facades\Http;
use KolayBi\RequestTracer\Support\TraceHelper;
use UnitEnum;

/**
 * @mixin Http
 */
class HttpTracingMixin
{
    /**
     * @return Closure(string|UnitEnum|null): static
     */
    public function traceOf(): Closure
    {
        return function (string|UnitEnum|null $channel) {
            return $this->withAttributes(['request_tracer' => ['channel' => TraceHelper::normalizeChannel($channel)]]);
        };
    }

    /**
     * @return Closure(string|UnitEnum|null): static
     */
    public function channel(): Closure
    {
        return $this->traceOf();
    }

    /**
     * @return Closure(array|string|null): static
     */
    public function withTraceExtra(): Closure
    {
        return function (array|string|null $extra) {
            return $this->withAttributes(['request_tracer' => ['extra' => $extra]]);
        };
    }
}
