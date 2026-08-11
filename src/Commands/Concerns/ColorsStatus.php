<?php

namespace KolayBi\RequestTracer\Commands\Concerns;

trait ColorsStatus
{
    private function colorStatus(?int $status): string
    {
        if (null === $status) {
            return '<fg=white>—</>';
        }

        $color = match (true) {
            $status >= 500 => 'red',
            $status >= 400 => 'yellow',
            $status >= 300 => 'cyan',
            $status >= 200 => 'green',
            default        => 'white',
        };

        return "<fg={$color}>{$status}</>";
    }
}
