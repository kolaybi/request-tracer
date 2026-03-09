<?php

namespace KolayBi\RequestTracer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class StoreTraceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly array $attributes,
        public readonly string $modelClass,
    ) {}

    public function handle(): void
    {
        new $this->modelClass($this->attributes)->save();
    }
}
