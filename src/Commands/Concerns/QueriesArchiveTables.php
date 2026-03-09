<?php

namespace KolayBi\RequestTracer\Commands\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait QueriesArchiveTables
{
    /**
     * Query a column across the current table and all archive tables for a model.
     *
     * @param class-string<Model> $modelClass
     */
    private function queryAcrossTables(string $modelClass, string $column, mixed $value): Collection
    {
        $model = new $modelClass();
        $baseTable = $this->resolveBaseTable($model);
        $connection = DB::connection($model->getConnectionName());
        $schema = config('kolaybi.request-tracer.schema');

        // Current table
        $results = $modelClass::where($column, $value)->get();

        // Archive tables
        foreach ($this->discoverArchiveTables($connection, $baseTable, $schema) as $table) {
            $qualifiedTable = $schema ? "{$schema}.{$table}" : $table;
            $rows = $connection->table($qualifiedTable)->where($column, $value)->get();
            $results = $results->merge($modelClass::hydrate($rows->map(fn($row) => (array) $row)->all()));
        }

        return $results;
    }

    /**
     * Find a single record by primary key across the current table and all archive tables.
     *
     * @param class-string<Model> $modelClass
     */
    private function findAcrossTables(string $modelClass, mixed $id): ?Model
    {
        $model = $modelClass::find($id);

        if ($model) {
            return $model;
        }

        $baseModel = new $modelClass();
        $baseTable = $this->resolveBaseTable($baseModel);
        $connection = DB::connection($baseModel->getConnectionName());
        $schema = config('kolaybi.request-tracer.schema');
        $keyName = $baseModel->getKeyName();

        foreach ($this->discoverArchiveTables($connection, $baseTable, $schema) as $table) {
            $qualifiedTable = $schema ? "{$schema}.{$table}" : $table;
            $row = $connection->table($qualifiedTable)->where($keyName, $id)->first();

            if ($row) {
                return $modelClass::hydrate([(array) $row])->first();
            }
        }

        return null;
    }

    private function resolveBaseTable(Model $model): string
    {
        $table = $model->getTable();
        $schema = config('kolaybi.request-tracer.schema');

        // Strip schema prefix if present
        if ($schema && str_starts_with($table, "{$schema}.")) {
            return substr($table, strlen($schema) + 1);
        }

        return $table;
    }

    private function discoverArchiveTables(Connection $connection, string $baseTable, ?string $schema): array
    {
        $pattern = $baseTable . '_%';
        $driver = $connection->getDriverName();

        if ('mysql' === $driver) {
            $database = $schema ?? $connection->getDatabaseName();

            $rows = $connection->select(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?',
                [$database, $pattern],
            );

            $tables = array_map(fn($row) => $row->table_name ?? $row->TABLE_NAME, $rows);
        } elseif ('pgsql' === $driver) {
            $pgSchema = $schema ?? 'public';

            $rows = $connection->select(
                'SELECT tablename FROM pg_tables WHERE schemaname = ? AND tablename LIKE ?',
                [$pgSchema, $pattern],
            );

            $tables = array_map(fn($row) => $row->tablename, $rows);
        } else {
            // SQLite
            $rows = $connection->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE ?",
                [$pattern],
            );

            $tables = array_map(fn($row) => $row->name, $rows);
        }

        // Only return tables matching the date suffix pattern
        $prefixLength = strlen($baseTable) + 1;

        return array_values(
            array_filter(
                $tables,
                fn(string $table) => preg_match('/^\d{8}$/', substr($table, $prefixLength)),
            ),
        );
    }
}
