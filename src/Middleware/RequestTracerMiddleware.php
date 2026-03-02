<?php

namespace KolayBi\RequestTracer\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use KolayBi\RequestTracer\Support\IncomingTraceRecorder;
use KolayBi\RequestTracer\Support\Timestamp;
use Symfony\Component\HttpFoundation\Response;

class RequestTracerMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        Context::add('trace_id', (string) Str::ulid());

        $incomingEnabled = config('kolaybi.request-tracer.incoming.enabled', false);
        $start = $incomingEnabled ? Timestamp::now() : null;

        $response = $next($request);

        if (null !== $start && $response instanceof Response) {
            new IncomingTraceRecorder()->record($request, $response, $start, Timestamp::now());
        }

        return $response;
    }
}
