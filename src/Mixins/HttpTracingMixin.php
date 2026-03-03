<?php

namespace KolayBi\RequestTracer\Mixins;

use Closure;
use Illuminate\Support\Facades\Http;

/**
 * @mixin Http
 */
class HttpTracingMixin
{
    /**
     * @return Closure(?string): static
     */
    public function traceOf(): Closure
    {
        return function (?string $channel) {
            return $this->withAttributes(['request_tracer' => ['channel' => $channel]]);
        };
    }

    /**
     * @return Closure(?string): static
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
