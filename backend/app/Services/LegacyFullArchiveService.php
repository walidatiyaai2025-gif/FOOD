<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

final class LegacyFullArchiveService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    public function preview(): array
    {
        $tables = $this->sourceTableInventory();

        return [
            'source_tables' => count($tables),
            'estimated_source_rows' => array_sum(array_column($tables, 'estimated_rows')),
            'tables' => $tables,
            'rules' => [
                'all_user_tables_are_in_scope' => true,
                'all_rows_are_archived' => true,
                'source_is_read_only' => true,
                'archive_is_lossless_json_with_binary_base64' => true,
                'success_requires_source_archive_row_count_match' => true,
            ],
        ];
    }

    public function archiveAll(bool $apply, ?callable $progress = null): array
    {
        $this->assertArchiveTables();

        $inventory = $this->sourceTableInventory();

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'planned' => [
                    'source_tables' => count($inventory),
                    'estimated_source_rows' => array_sum(array_column($inventory, 'estimated_rows')),
                ],
                'tables' => $inventory,
            ];
        }

        $runId = (string) Str::uuid();
        $completedTables = 0;
        $archivedRows = 0;
        $tableResults = [];

        foreach ($inventory as $tableMeta) {
            $schemaName = (string) $tableMeta['schema'];
            $tableName = (string) $tableMeta['table'];
            $objectId = (int) $tableMeta['object_id'];
            $label = $schemaName.'.'.$tableName;

            if ($progress !== null) {
                $progress('archive_'.$label, 'start', null);
            }

            $startedAt = microtime(true);
            $result = $this->archiveOneTable(
                $runId,
                $schemaName,
                $tableName,
                $objectId,
            );

            $completedTables++;
            $archivedRows += (int) $result['archived_rows'];
            $tableResults[] = $result;

            if ($progress !== null) {
                $progress('archive_'.$label, 'done', round(microtime(true) - $startedAt, 3));
            }
        }

        return [
            'mode' => 'apply',
            'archive_run_id' => $runId,
            'source_tables' => count($inventory),
            'completed_tables' => $completedTables,
            'archived_rows' => $archivedRows,
            'tables' => $tableResults,
        ];
    }

    public function verify(?callable $progress = null): array
    {
        $this->assertArchiveTables();

        $inventory = $this->sourceTableInventory();
        $results = [];
        $healthy = true;
        $sourceTotal = 0;
        $archiveTotal = 0;

        foreach ($inventory as $tableMeta) {
            $schemaName = (string) $tableMeta['schema'];
            $tableName = (string) $tableMeta['table'];
            $label = $schemaName.'.'.$tableName;

            if ($progress !== null) {
                $progress('verify_'.$label, 'start', null);
            }

            $startedAt = microtime(true);
            $sourceCount = $this->exactSourceCount($schemaName, $tableName);
            $archivedCount = (int) DB::table('legacy_source_archive_rows')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_schema', $schemaName)
                ->where('source_table', $tableName)
                ->count();

            $manifest = DB::table('legacy_source_archive_tables')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_schema', $schemaName)
                ->where('source_table', $tableName)
                ->first(['status', 'source_row_count', 'archived_row_count', 'archive_run_id']);

            $rowHealthy = $manifest !== null
                && (string) $manifest->status === 'completed'
                && (int) $manifest->source_row_count === $sourceCount
                && (int) $manifest->archived_row_count === $archivedCount
                && $sourceCount === $archivedCount;

            if (! $rowHealthy) {
                $healthy = false;
            }

            $sourceTotal += $sourceCount;
            $archiveTotal += $archivedCount;

            $results[] = [
                'schema' => $schemaName,
                'table' => $tableName,
                'source_rows' => $sourceCount,
                'archived_rows' => $archivedCount,
                'manifest_status' => $manifest?->status,
                'archive_run_id' => $manifest?->archive_run_id,
                'healthy' => $rowHealthy,
            ];

            if ($progress !== null) {
                $progress('verify_'.$label, 'done', round(microtime(true) - $startedAt, 3));
            }
        }

        $sourceNames = collect($inventory)
            ->map(fn (array $row): string => $row['schema'].'.'.$row['table'])
            ->all();

        $extraArchiveTables = DB::table('legacy_source_archive_tables')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->get(['source_schema', 'source_table'])
            ->map(fn ($row): string => $row->source_schema.'.'.$row->source_table)
            ->reject(fn (string $name): bool => in_array($name, $sourceNames, true))
            ->values()
            ->all();

        return [
            'healthy' => $healthy && $sourceTotal === $archiveTotal,
            'source_tables' => count($inventory),
            'source_rows' => $sourceTotal,
            'archived_rows' => $archiveTotal,
            'extra_archive_tables' => $extraArchiveTables,
            'tables' => $results,
        ];
    }

    private function archiveOneTable(
        string $runId,
        string $schemaName,
        string $tableName,
        int $objectId,
    ): array {
        $primaryKeyColumns = $this->primaryKeyColumns($objectId);
        $columns = $this->sourceColumns($objectId);
        $maxAttempts = max(1, (int) config('database.connections.foodex_legacy.retry_attempts', 5));

        $this->upsertManifest(
            $runId,
            $schemaName,
            $tableName,
            0,
            0,
            $primaryKeyColumns,
            $columns,
            'running',
            null,
            now(),
            null,
        );

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                DB::table('legacy_source_archive_rows')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_schema', $schemaName)
                    ->where('source_table', $tableName)
                    ->delete();

                $sourceCountBefore = $this->exactSourceCount($schemaName, $tableName);
                $archived = 0;
                $ordinal = 0;
                $buffer = [];

                $source = $this->legacySource();
                $query = $source->table($schemaName.'.'.$tableName);

                foreach ($primaryKeyColumns as $column) {
                    $query->orderBy($column);
                }

                foreach ($query->cursor() as $row) {
                    $ordinal++;
                    $normalized = $this->normalizeRow((array) $row);
                    $payload = $this->json($normalized);
                    $sourceKey = $primaryKeyColumns === []
                        ? '__ordinal:'.$ordinal
                        : $this->json(
                            collect($primaryKeyColumns)
                                ->mapWithKeys(fn (string $column): array => [
                                    $column => $normalized[$column] ?? null,
                                ])
                                ->all()
                        );

                    $buffer[] = [
                        'source_system' => self::SOURCE_SYSTEM,
                        'source_schema' => $schemaName,
                        'source_table' => $tableName,
                        'archive_run_id' => $runId,
                        'source_row_ordinal' => $ordinal,
                        'source_key' => $sourceKey,
                        'row_hash' => hash('sha256', $payload),
                        'payload' => $payload,
                        'created_at' => now(),
                    ];

                    if (count($buffer) >= 250) {
                        DB::table('legacy_source_archive_rows')->insert($buffer);
                        $archived += count($buffer);
                        $buffer = [];
                    }
                }

                if ($buffer !== []) {
                    DB::table('legacy_source_archive_rows')->insert($buffer);
                    $archived += count($buffer);
                }

                $sourceCountAfter = $this->exactSourceCount($schemaName, $tableName);

                if (
                    $sourceCountBefore !== $sourceCountAfter
                    || $sourceCountAfter !== $archived
                ) {
                    throw new RuntimeException(
                        "Source/archive row count mismatch for {$schemaName}.{$tableName}: ".
                        "before={$sourceCountBefore}, after={$sourceCountAfter}, archived={$archived}"
                    );
                }

                $this->upsertManifest(
                    $runId,
                    $schemaName,
                    $tableName,
                    $sourceCountAfter,
                    $archived,
                    $primaryKeyColumns,
                    $columns,
                    'completed',
                    null,
                    null,
                    now(),
                );

                return [
                    'schema' => $schemaName,
                    'table' => $tableName,
                    'source_rows' => $sourceCountAfter,
                    'archived_rows' => $archived,
                    'primary_key_columns' => $primaryKeyColumns,
                    'status' => 'completed',
                ];
            } catch (Throwable $exception) {
                $this->upsertManifest(
                    $runId,
                    $schemaName,
                    $tableName,
                    0,
                    (int) DB::table('legacy_source_archive_rows')
                        ->where('source_system', self::SOURCE_SYSTEM)
                        ->where('source_schema', $schemaName)
                        ->where('source_table', $tableName)
                        ->count(),
                    $primaryKeyColumns,
                    $columns,
                    'failed',
                    mb_substr($exception->getMessage(), 0, 65535),
                    null,
                    now(),
                );

                if (! $this->isTransientSqlServerError($exception) || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                DB::purge('foodex_legacy');
                usleep(min(5 * $attempt, 20) * 1000000);
            }
        }

        throw new RuntimeException("Archive failed unexpectedly for {$schemaName}.{$tableName}.");
    }

    private function sourceTableInventory(): array
    {
        $source = $this->legacySource();

        $tables = $source->table('sys.tables as t')
            ->join('sys.schemas as s', 's.schema_id', '=', 't.schema_id')
            ->where('t.is_ms_shipped', 0)
            ->orderBy('s.name')
            ->orderBy('t.name')
            ->get([
                't.object_id',
                's.name as schema_name',
                't.name as table_name',
            ]);

        $inventory = [];

        foreach ($tables as $table) {
            $objectId = (int) $table->object_id;

            $estimated = (int) $source->table('sys.partitions')
                ->where('object_id', $objectId)
                ->whereIn('index_id', [0, 1])
                ->sum('rows');

            $inventory[] = [
                'object_id' => $objectId,
                'schema' => (string) $table->schema_name,
                'table' => (string) $table->table_name,
                'estimated_rows' => $estimated,
            ];
        }

        return $inventory;
    }

    private function exactSourceCount(string $schemaName, string $tableName): int
    {
        return (int) $this->legacySource()
            ->table($schemaName.'.'.$tableName)
            ->count();
    }

    private function sourceColumns(int $objectId): array
    {
        return $this->legacySource()
            ->table('sys.columns as c')
            ->join('sys.types as ty', 'ty.user_type_id', '=', 'c.user_type_id')
            ->where('c.object_id', $objectId)
            ->orderBy('c.column_id')
            ->get([
                'c.name',
                'ty.name as type_name',
                'c.max_length',
                'c.precision',
                'c.scale',
                'c.is_nullable',
            ])
            ->map(static fn ($row): array => [
                'name' => (string) $row->name,
                'type' => (string) $row->type_name,
                'max_length' => (int) $row->max_length,
                'precision' => (int) $row->precision,
                'scale' => (int) $row->scale,
                'nullable' => (bool) $row->is_nullable,
            ])
            ->all();
    }

    private function primaryKeyColumns(int $objectId): array
    {
        return $this->legacySource()
            ->table('sys.indexes as i')
            ->join('sys.index_columns as ic', function ($join): void {
                $join->on('ic.object_id', '=', 'i.object_id')
                    ->on('ic.index_id', '=', 'i.index_id');
            })
            ->join('sys.columns as c', function ($join): void {
                $join->on('c.object_id', '=', 'ic.object_id')
                    ->on('c.column_id', '=', 'ic.column_id');
            })
            ->where('i.object_id', $objectId)
            ->where('i.is_primary_key', 1)
            ->where('ic.key_ordinal', '>', 0)
            ->orderBy('ic.key_ordinal')
            ->pluck('c.name')
            ->map(static fn ($name): string => (string) $name)
            ->all();
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $column => $value) {
            $normalized[(string) $column] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return [
                '__legacy_type' => 'datetime',
                'value' => $value->format('Y-m-d H:i:s.uP'),
            ];
        }

        if (is_resource($value)) {
            $bytes = stream_get_contents($value);

            return [
                '__legacy_type' => 'base64',
                'value' => base64_encode($bytes === false ? '' : $bytes),
            ];
        }

        if (is_string($value)) {
            if (mb_check_encoding($value, 'UTF-8')) {
                return $value;
            }

            return [
                '__legacy_type' => 'base64',
                'value' => base64_encode($value),
            ];
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $value);
        }

        if (is_object($value)) {
            return $this->normalizeRow((array) $value);
        }

        return (string) $value;
    }

    private function upsertManifest(
        string $runId,
        string $schemaName,
        string $tableName,
        int $sourceRows,
        int $archivedRows,
        array $primaryKeyColumns,
        array $columns,
        string $status,
        ?string $error,
        mixed $startedAt,
        mixed $finishedAt,
    ): void {
        $values = [
            'archive_run_id' => $runId,
            'source_row_count' => $sourceRows,
            'archived_row_count' => $archivedRows,
            'primary_key_columns' => $this->json($primaryKeyColumns),
            'source_columns' => $this->json($columns),
            'status' => $status,
            'error' => $error,
            'updated_at' => now(),
        ];

        if ($startedAt !== null) {
            $values['started_at'] = $startedAt;
        }

        if ($finishedAt !== null) {
            $values['finished_at'] = $finishedAt;
        }

        DB::table('legacy_source_archive_tables')->updateOrInsert(
            [
                'source_system' => self::SOURCE_SYSTEM,
                'source_schema' => $schemaName,
                'source_table' => $tableName,
            ],
            [
                ...$values,
                'created_at' => now(),
            ],
        );
    }

    private function legacySource(): Connection
    {
        $connection = DB::connection('foodex_legacy');
        $pdo = $connection->getPdo();

        if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $pdo->setAttribute(
                \PDO::SQLSRV_ATTR_QUERY_TIMEOUT,
                max(0, (int) config('database.connections.foodex_legacy.query_timeout', 300)),
            );
        }

        return $connection;
    }

    private function isTransientSqlServerError(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'sqlstate[08001]')
            || str_contains($message, 'sqlstate[08s01]')
            || str_contains($message, 'sqlstate[hyt00]')
            || str_contains($message, 'wait operation timed out')
            || str_contains($message, 'communication link failure')
            || str_contains($message, 'transport-level error')
            || str_contains($message, 'connection is busy');
    }

    private function assertArchiveTables(): void
    {
        foreach (['legacy_source_archive_tables', 'legacy_source_archive_rows'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}. Run php artisan migrate first.");
            }
        }
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Legacy archive JSON encoding failed: '.$exception->getMessage(), 0, $exception);
        }
    }
}
