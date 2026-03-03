<?php

namespace KolayBi\RequestTracer\Commands\Concerns;

use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

trait BuildsEndpoint
{
    private function buildEndpoint(IncomingRequestTrace|OutgoingRequestTrace $trace, string $type): string
    {
        $protocol = $trace->protocol ?? null;
        $host = $trace->host;
        $path = $trace->path;
        $query = $trace->query;
        $route = 'INCOMING' === $type ? $trace->route : null;

        $url = '';

        if ($protocol && $host) {
            $url = "{$protocol}://{$host}";
        } elseif ($host) {
            $url = $host;
        }

        if ($path) {
            $url = rtrim($url, '/') . '/' . ltrim($path, '/');
        }

        if ($query) {
            $url .= "?{$query}";
        }

        if ($route) {
            $url .= " ({$route})";
        }

        return $url ?: '—';
    }
}
