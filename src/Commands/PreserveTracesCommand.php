<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
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
        $connection = DB::connection(config('kolaybi.request-tracer.connection'));
        $schema = config('kolaybi.request-tracer.schema');

        $driver = $connection->getDriverName();
        $supportedDrivers = ['mysql', 'pgsql', 'sqlite'];

        if (!in_array($driver, $supportedDrivers, true)) {
            $this->warn("Unsupported database driver [{$driver}] for preserve command.");

            return self::SUCCESS;
        }

        $direction = (string) $this->option('direction');
        $directions = match ($direction) {
            'incoming' => ['incoming'],
            'outgoing' => ['outgoing'],
            'both', ''  => ['incoming', 'outgoing'],
            default    => null,
        };

        if (null === $directions) {
            $this->error("Invalid --direction value [{$direction}]. Expected: incoming|outgoing|both.");

            return self::FAILURE;
        }

        foreach ($directions as $dir) {
            $this->preserveDirection($connection, $schema, $dir);
        }

        return self::SUCCESS;
    }

    private function preserveDirection(Connection $connection, ?string $schema, string $direction): void
    {
        $patterns = $this->parsePatterns(config("kolaybi.request-tracer.{$direction}.persist", ''));
        $liveTable = config("kolaybi.request-tracer.{$direction}.table");
        $persistentTable = "{$liveTable}_persistent";

        if ([] === $patterns) {
            $this->line("Preserved 0 {$direction} row(s) (no patterns configured)");

            return;
        }

        if (!$this->tableExists($connection, $persistentTable, $schema)) {
            $this->warn("Persistent table [{$persistentTable}] does not exist — run migrations.");

            return;
        }

        $archives = $this->resolveArchives($connection, $liveTable, $schema);

        if ([] === $archives) {
            $this->warn("No archives found for [{$liveTable}].");

            return;
        }

        $matchExpression = 'incoming' === $direction
            ? 'path'
            : $this->outgoingMatchExpression($connection->getDriverName());

        foreach ($archives as $archive) {
            $inserted = $this->sweepArchive($connection, $archive, $persistentTable, $matchExpression, $patterns, $schema);
            $this->info("Preserved {$inserted} {$direction} row(s) from [{$archive}] → [{$persistentTable}]");
        }
    }

    private function resolveArchives(Connection $connection, string $liveTable, ?string $schema): array
    {
        $all = $this->discoverArchiveTables($connection, $liveTable, $schema);
        sort($all);

        if ($this->option('all')) {
            return $all;
        }

        $date = $this->option('date');

        if (null !== $date) {
            $target = "{$liveTable}_{$date}";

            return in_array($target, $all, true) ? [$target] : [];
        }

        return [] === $all ? [] : [end($all)];
    }

    private function sweepArchive(
        Connection $connection,
        string $archive,
        string $persistentTable,
        string $matchExpression,
        array $patterns,
        ?string $schema,
    ): int {
        $driver = $connection->getDriverName();
        $qualifiedArchive = $this->qualifiedTable($archive, $schema);
        $qualifiedPersistent = $this->qualifiedTable($persistentTable, $schema);

        $likes = array_fill(0, count($patterns), "{$matchExpression} LIKE ? ESCAPE '!'");
        $where = implode(' OR ', $likes);
        $bindings = array_map(fn(string $pattern) => $this->globToLike($pattern), $patterns);

        $sql = match ($driver) {
            'mysql'  => "INSERT IGNORE INTO {$qualifiedPersistent} SELECT * FROM {$qualifiedArchive} WHERE {$where}",
            'pgsql'  => "INSERT INTO {$qualifiedPersistent} SELECT * FROM {$qualifiedArchive} WHERE {$where} ON CONFLICT (id) DO NOTHING",
            'sqlite' => "INSERT OR IGNORE INTO {$qualifiedPersistent} SELECT * FROM {$qualifiedArchive} WHERE {$where}",
        };

        return $connection->affectingStatement($sql, $bindings);
    }

    private function outgoingMatchExpression(string $driver): string
    {
        return match ($driver) {
            'mysql'  => "TRIM(BOTH '/' FROM CONCAT(IFNULL(host, ''), IFNULL(path, '')))",
            'pgsql'  => "TRIM(BOTH '/' FROM (COALESCE(host, '') || COALESCE(path, '')))",
            'sqlite' => "TRIM((COALESCE(host, '') || COALESCE(path, '')), '/')",
        };
    }

    private function globToLike(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $char = $pattern[$i];
            $out .= match ($char) {
                '*'           => '%',
                '?'           => '_',
                '%', '_', '!' => '!' . $char,
                default       => $char,
            };
        }

        return $out;
    }

    private function parsePatterns(array|string $patterns): array
    {
        if (is_array($patterns)) {
            return array_filter($patterns);
        }

        return array_filter(array_map('trim', explode(',', $patterns)));
    }

    private function tableExists(Connection $connection, string $table, ?string $schema): bool
    {
        $driver = $connection->getDriverName();

        if ('mysql' === $driver) {
            $database = $schema ?? $connection->getDatabaseName();

            return (bool) $connection->selectOne(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
                [$database, $table],
            );
        }

        if ('pgsql' === $driver) {
            $pgSchema = $schema ?? 'public';

            return (bool) $connection->selectOne(
                'SELECT 1 FROM pg_tables WHERE schemaname = ? AND tablename = ? LIMIT 1',
                [$pgSchema, $table],
            );
        }

        return (bool) $connection->selectOne(
            'SELECT 1 FROM sqlite_master WHERE type = ? AND name = ? LIMIT 1',
            ['table', $table],
        );
    }

    private function qualifiedTable(string $table, ?string $schema): string
    {
        return $schema ? "{$schema}.{$table}" : $table;
    }
}
