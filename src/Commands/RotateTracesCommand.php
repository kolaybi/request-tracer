<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use KolayBi\RequestTracer\Commands\Concerns\QueriesArchiveTables;

class RotateTracesCommand extends Command
{
    use QueriesArchiveTables;

    protected $signature = 'request-tracer:rotate
        {--days= : Override retention days for dropping old archives}';

    protected $description = 'Rotate trace tables daily and drop old archives';

    public function handle(): int
    {
        $tables = array_filter([
            config('kolaybi.request-tracer.outgoing.table', 'outgoing_request_traces'),
            config('kolaybi.request-tracer.incoming.table', 'incoming_request_traces'),
        ]);

        $connection = DB::connection(config('kolaybi.request-tracer.connection'));
        $schema = config('kolaybi.request-tracer.schema');

        foreach ($tables as $table) {
            $this->rotateTable($connection, $table, $schema);
        }

        $retentionDays = (int) ($this->option('days') ?? config('kolaybi.request-tracer.retention_days', 0));

        if ($retentionDays > 0) {
            foreach ($tables as $table) {
                $this->dropOldArchives($connection, $table, $schema, $retentionDays);
            }
        }

        return self::SUCCESS;
    }

    private function rotateTable(Connection $connection, string $baseTable, ?string $schema): void
    {
        $driver = $connection->getDriverName();
        $supportedDrivers = ['mysql', 'pgsql', 'sqlite'];

        if (!in_array($driver, $supportedDrivers, true)) {
            $this->warn("Unsupported database driver [{$driver}] for rotate command.");

            return;
        }

        $dateSuffix = now()->format('Ymd');
        $archiveTable = "{$baseTable}_{$dateSuffix}";
        $tempTable = "{$baseTable}_temp";

        $qualifiedBase = $this->qualifiedTable($baseTable, $schema);
        $qualifiedArchive = $this->qualifiedTable($archiveTable, $schema);
        $qualifiedTemp = $this->qualifiedTable($tempTable, $schema);

        if ($this->tableExists($connection, $archiveTable, $schema)) {
            $this->warn("Archive table [{$archiveTable}] already exists — skipping [{$baseTable}].");

            return;
        }

        if (!$this->tableExists($connection, $baseTable, $schema)) {
            $this->warn("Table [{$baseTable}] does not exist — skipping.");

            return;
        }

        // Clean up temp table from a previously failed run
        $connection->statement("DROP TABLE IF EXISTS {$qualifiedTemp}");

        if ('mysql' === $driver) {
            $connection->statement("CREATE TABLE {$qualifiedTemp} LIKE {$qualifiedBase}");
            $connection->statement("RENAME TABLE {$qualifiedBase} TO {$qualifiedArchive}, {$qualifiedTemp} TO {$qualifiedBase}");
        } elseif ('pgsql' === $driver) {
            $connection->statement("CREATE TABLE {$qualifiedTemp} (LIKE {$qualifiedBase} INCLUDING ALL)");

            $connection->transaction(function () use ($connection, $qualifiedBase, $qualifiedTemp, $archiveTable, $baseTable) {
                $connection->statement("ALTER TABLE {$qualifiedBase} RENAME TO \"{$archiveTable}\"");
                $connection->statement("ALTER TABLE {$qualifiedTemp} RENAME TO \"{$baseTable}\"");
            });
        } elseif ('sqlite' === $driver) {
            $row = $connection->selectOne(
                'SELECT "sql" AS create_sql FROM sqlite_master WHERE type = ? AND name = ?',
                ['table', $baseTable],
            );
            $createSql = is_object($row) ? ($row->create_sql ?? null) : ($row['create_sql'] ?? null);

            if (!is_string($createSql) || '' === trim($createSql)) {
                $this->warn("Could not read CREATE TABLE SQL for [{$baseTable}] — skipping.");

                return;
            }

            $tempSql = preg_replace(
                '/CREATE TABLE\s+"?' . preg_quote($baseTable, '/') . '"?/',
                "CREATE TABLE \"{$tempTable}\"",
                $createSql,
            );
            $connection->statement($tempSql);

            $connection->transaction(function () use ($connection, $qualifiedBase, $qualifiedTemp, $qualifiedArchive) {
                $connection->statement("ALTER TABLE {$qualifiedBase} RENAME TO {$qualifiedArchive}");
                $connection->statement("ALTER TABLE {$qualifiedTemp} RENAME TO {$qualifiedBase}");
            });
        }

        $this->info("Rotated [{$baseTable}] → [{$archiveTable}]");
    }

    private function dropOldArchives(Connection $connection, string $baseTable, ?string $schema, int $retentionDays): void
    {
        $threshold = now()->subDays($retentionDays)->format('Ymd');
        $prefixLength = strlen($baseTable) + 1;

        $archiveTables = $this->discoverArchiveTables($connection, $baseTable, $schema);
        $dropped = 0;

        foreach ($archiveTables as $table) {
            $datePart = substr($table, $prefixLength);

            if ($datePart >= $threshold) {
                continue;
            }

            $qualified = $this->qualifiedTable($table, $schema);
            $connection->statement("DROP TABLE IF EXISTS {$qualified}");
            $dropped++;
        }

        if ($dropped > 0) {
            $this->info("Dropped {$dropped} old archive(s) for [{$baseTable}].");
        }
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

        if ('sqlite' === $driver) {
            return (bool) $connection->selectOne(
                'SELECT 1 FROM sqlite_master WHERE type = ? AND name = ? LIMIT 1',
                ['table', $table],
            );
        }

        return false;
    }

    private function qualifiedTable(string $table, ?string $schema): string
    {
        return $schema ? "{$schema}.{$table}" : $table;
    }
}
