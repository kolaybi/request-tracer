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
            return $this->withHeaders(['X-Trace-Channel' => $channel]);
        };
    }

    /**
     * @return Closure(?string): static
     */
    public function channel(): Closure
    {
        return function (?string $channel) {
            return $this->withHeaders(['X-Trace-Channel' => $channel]);
        };
    }

    /**
     * @return Closure(array|string|null): static
     */
    public function withTraceExtra(): Closure
    {
        return function (array|string|null $extra) {
            $value = is_array($extra) ? json_encode($extra, JSON_UNESCAPED_SLASHES) : $extra;

            return $this->withHeaders(['X-Trace-Extra' => $value]);
        };
    }
}
