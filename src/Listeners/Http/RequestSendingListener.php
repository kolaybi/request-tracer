<?php

namespace KolayBi\RequestTracer\Listeners\Http;

use Illuminate\Http\Client\Events\RequestSending;
use KolayBi\RequestTracer\Support\RequestTimingStore;
use KolayBi\RequestTracer\Support\Timestamp;

class RequestSendingListener
{
    public function handle(RequestSending $event): void
    {
        $request = $event->request;
        $attributes = $request->attributes();
        $trace = $attributes['request_tracer'] ?? [];

        if (!is_array($trace)) {
            $trace = [];
        }

        if (empty($trace['started_at'])) {
            $trace['started_at'] = Timestamp::now();
        }

        $attributes['request_tracer'] = $trace;
        $request->setRequestAttributes($attributes);

        RequestTimingStore::stamp($request->toPsrRequest(), $trace['started_at']);
    }
}
