<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use KolayBi\RequestTracer\Commands\Concerns\QueriesArchiveTables;

class PreserveTracesCommand extends Command
{
    use QueriesArchiveTables;

    protected $signature = 'request-tracer:preserve
        {--date= : Specific archive date (YYYYMMDD); defaults to most recent archive}
        {--all : Sweep every archive table found (for backfills)}
        {--direction=both : incoming|outgoing|both}';

    protected $description = 'Preserve matching trace rows from archive tables into permanent *_persistent tables';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
