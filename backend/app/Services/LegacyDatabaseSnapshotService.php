<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LegacyDatabaseSnapshotService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    /** @var array<int,string> */
    private const BINARY_TYPES = ['binary', 'varbinary', 'image', 'timestamp', 'rowversion'];

    public function preview(): array
    {
        $db = $this->legacySource();
        $tables = [];
        $totalRows = 0;

        foreach ($this->sourceTables($db) as $table) {
            $rows = (int) $db->table($table['schema'].'.'.$table['table'])->count();
            $totalRows += $rows;
            $tables[] = [
                'schema' => $table['schema'],
                'table' => $table['table'],
                'rows' => $rows,
                'columns' => count($this->columns($db, $table['schema'], $table['table'])),
                'primary_key_columns' => $this->primaryKeyColumns($db, $table['schema'], $table['table']),
            ];
        }

        return [
            'mode' => 'dry-run',
            'source_database' => $this->sourceDatabaseName($db),
            'table_count' => count($tables),
            'source_row_count' => $totalRows,
            'tables' => $tables,
            'rules' => [
                'all_user_base_tables_included' => true,
                'binary_values_are_base64_encoded' => true,
                'chunks_are_gzip_compressed' => true,
                'chunk_payload_sha256_verified' => true,
                'source_database_is_read_only' => true,
            ],
        ];
    }

    public function snapshot(): array
    {
        $this->assertReady();
        $db = $this->legacySource();
        $snapshotId = (string) Str::uuid();
        $sourceDatabase = $this->sourceDatabaseName($db);
        $tables = $this->sourceTables($db);

        DB::table('legacy_database_snapshots')->insert([
            'id' => $snapshotId,
            'source_system' => self::SOURCE_SYSTEM,
            'source_database' => $sourceDatabase,
            'status' => 'running',
            'table_count' => count($tables),
            'source_row_count' => 0,
            'archived_row_count' => 0,
            'summary' => null,
            'error' => null,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sourceRows = 0;
        $archivedRows = 0;
        $tableSummaries = [];

        try {
            foreach ($tables as $table) {
                $summary = $this->snapshotTable(
                    $db,
                    $snapshotId,
                    $table['schema'],
                    $table['table'],
                );
                $sourceRows += $summary['source_rows'];
                $archivedRows += $summary['archived_rows'];
                $tableSummaries[] = $summary;
            }

            DB::table('legacy_database_snapshots')->where('id', $snapshotId)->update([
                'source_row_count' => $sourceRows,
                'archived_row_count' => $archivedRows,
                'updated_at' => now(),
            ]);

            $verification = $this->verify($snapshotId);
            if (! $verification['healthy']) {
                throw new RuntimeException('Legacy database snapshot verification failed: '.$this->json($verification));
            }

            $summary = [
                'source_database' => $sourceDatabase,
                'table_count' => count($tables),
                'source_row_count' => $sourceRows,
                'archived_row_count' => $archivedRows,
                'verification' => $verification,
                'tables' => $tableSummaries,
            ];

            DB::table('legacy_database_snapshots')->where('id', $snapshotId)->update([
                'status' => 'completed',
                'source_row_count' => $sourceRows,
                'archived_row_count' => $archivedRows,
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'mode' => 'apply',
                'status' => 'completed',
                'snapshot_id' => $snapshotId,
                ...$summary,
            ];
        } catch (Throwable $exception) {
            DB::table('legacy_database_snapshots')->where('id', $snapshotId)->update([
                'status' => 'failed',
                'source_row_count' => $sourceRows,
                'archived_row_count' => $archivedRows,
                'error' => mb_substr($exception->getMessage(), 0, 60000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function verify(?string $snapshotId = null): array
    {
        $this->assertReady();

        $snapshot = $snapshotId === null
            ? DB::table('legacy_database_snapshots')->orderByDesc('started_at')->first()
            : DB::table('legacy_database_snapshots')->where('id', $snapshotId)->first();

        if ($snapshot === null) {
            return [
                'healthy' => false,
                'snapshot_id' => $snapshotId,
                'error' => 'No legacy database snapshot exists.',
            ];
        }

        $manifests = DB::table('legacy_database_snapshot_tables')
            ->where('snapshot_id', (string) $snapshot->id)
            ->orderBy('source_schema')
            ->orderBy('source_table')
            ->get();

        $invalidTables = [];
        $invalidChunks = [];
        $archivedRows = 0;
        $chunkRows = 0;
        $chunkCount = 0;

        foreach ($manifests as $manifest) {
            $chunks = DB::table('legacy_database_snapshot_chunks')
                ->where('table_manifest_id', (int) $manifest->id)
                ->orderBy('chunk_index')
                ->get();

            $tableChunkRows = 0;
            foreach ($chunks as $chunk) {
                $chunkCount++;
                $compressed = base64_decode((string) $chunk->payload_gzip_base64, true);
                $raw = $compressed === false ? false : gzdecode($compressed);
                $valid = is_string($raw)
                    && hash_equals((string) $chunk->sha256, hash('sha256', $raw))
                    && strlen($raw) === (int) $chunk->raw_bytes
                    && strlen((string) $compressed) === (int) $chunk->compressed_bytes
                    && substr_count($raw, "\n") === (int) $chunk->row_count;

                if (! $valid) {
                    $invalidChunks[] = (int) $chunk->id;
                }

                $tableChunkRows += (int) $chunk->row_count;
            }

            $archived = (int) $manifest->archived_rows;
            $archivedRows += $archived;
            $chunkRows += $tableChunkRows;
            $tableHealthy = (string) $manifest->status === 'completed'
                && (int) $manifest->source_rows_start === (int) $manifest->source_rows_end
                && (int) $manifest->source_rows_start === $archived
                && $tableChunkRows === $archived
                && count($chunks) === (int) $manifest->chunk_count;

            if (! $tableHealthy) {
                $invalidTables[] = $manifest->source_schema.'.'.$manifest->source_table;
            }
        }

        $healthy = $invalidTables === []
            && $invalidChunks === []
            && count($manifests) === (int) $snapshot->table_count
            && $archivedRows === $chunkRows
            && $archivedRows === (int) $snapshot->archived_row_count
            && (int) $snapshot->source_row_count === (int) $snapshot->archived_row_count
            && in_array((string) $snapshot->status, ['running', 'completed'], true);

        return [
            'healthy' => $healthy,
            'snapshot_id' => (string) $snapshot->id,
            'snapshot_status' => (string) $snapshot->status,
            'table_count' => count($manifests),
            'expected_table_count' => (int) $snapshot->table_count,
            'source_row_count' => (int) $snapshot->source_row_count,
            'archived_row_count' => $archivedRows,
            'chunk_row_count' => $chunkRows,
            'chunk_count' => $chunkCount,
            'invalid_tables' => $invalidTables,
            'invalid_chunk_ids' => $invalidChunks,
        ];
    }

    /** @return array<int,array{schema:string,table:string}> */
    public function archivedTables(string $snapshotId): array
    {
        $this->assertReady();

        return DB::table('legacy_database_snapshot_tables')
            ->where('snapshot_id', $snapshotId)
            ->where('status', 'completed')
            ->orderBy('source_schema')
            ->orderBy('source_table')
            ->get(['source_schema', 'source_table'])
            ->map(static fn ($row): array => [
                'schema' => (string) $row->source_schema,
                'table' => (string) $row->source_table,
            ])
            ->all();
    }

    private function snapshotTable(
        ConnectionInterface $db,
        string $snapshotId,
        string $schema,
        string $table,
    ): array {
        $columns = $this->columns($db, $schema, $table);
        $primaryKeyColumns = $this->primaryKeyColumns($db, $schema, $table);
        $sourceRowsStart = (int) $db->table($schema.'.'.$table)->count();

        $manifestId = (int) DB::table('legacy_database_snapshot_tables')->insertGetId([
            'snapshot_id' => $snapshotId,
            'source_schema' => $schema,
            'source_table' => $table,
            'primary_key_columns' => $this->json($primaryKeyColumns),
            'column_metadata' => $this->json($columns),
            'source_rows_start' => $sourceRowsStart,
            'source_rows_end' => null,
            'archived_rows' => 0,
            'chunk_count' => 0,
            'status' => 'running',
            'error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $query = $db->table($schema.'.'.$table);
            foreach ($primaryKeyColumns as $column) {
                $query->orderBy($column);
            }

            $chunkSize = $this->chunkSize();
            $buffer = '';
            $bufferRows = 0;
            $rowOrdinal = 0;
            $chunkIndex = 0;
            $chunkFirstOrdinal = 1;

            foreach ($query->cursor() as $sourceRow) {
                $rowOrdinal++;
                $bufferRows++;
                $buffer .= $this->json($this->normalizeRow((array) $sourceRow, $columns))."\n";

                if ($bufferRows >= $chunkSize) {
                    $chunkIndex++;
                    $this->storeChunk(
                        $snapshotId,
                        $manifestId,
                        $chunkIndex,
                        $chunkFirstOrdinal,
                        $rowOrdinal,
                        $bufferRows,
                        $buffer,
                    );
                    $buffer = '';
                    $bufferRows = 0;
                    $chunkFirstOrdinal = $rowOrdinal + 1;
                }
            }

            if ($bufferRows > 0) {
                $chunkIndex++;
                $this->storeChunk(
                    $snapshotId,
                    $manifestId,
                    $chunkIndex,
                    $chunkFirstOrdinal,
                    $rowOrdinal,
                    $bufferRows,
                    $buffer,
                );
            }

            $sourceRowsEnd = (int) $db->table($schema.'.'.$table)->count();
            if ($sourceRowsStart !== $sourceRowsEnd || $sourceRowsStart !== $rowOrdinal) {
                throw new RuntimeException(
                    "Source table {$schema}.{$table} changed during snapshot or row count mismatched ".
                    "(start={$sourceRowsStart}, end={$sourceRowsEnd}, archived={$rowOrdinal})."
                );
            }

            DB::table('legacy_database_snapshot_tables')->where('id', $manifestId)->update([
                'source_rows_end' => $sourceRowsEnd,
                'archived_rows' => $rowOrdinal,
                'chunk_count' => $chunkIndex,
                'status' => 'completed',
                'updated_at' => now(),
            ]);

            return [
                'schema' => $schema,
                'table' => $table,
                'source_rows' => $sourceRowsStart,
                'archived_rows' => $rowOrdinal,
                'chunks' => $chunkIndex,
                'primary_key_columns' => $primaryKeyColumns,
            ];
        } catch (Throwable $exception) {
            DB::table('legacy_database_snapshot_tables')->where('id', $manifestId)->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 60000),
                'updated_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function storeChunk(
        string $snapshotId,
        int $manifestId,
        int $chunkIndex,
        int $firstOrdinal,
        int $lastOrdinal,
        int $rowCount,
        string $raw,
    ): void {
        $compressed = gzencode($raw, 6);
        if (! is_string($compressed)) {
            throw new RuntimeException('Unable to gzip legacy database snapshot chunk.');
        }

        DB::table('legacy_database_snapshot_chunks')->insert([
            'snapshot_id' => $snapshotId,
            'table_manifest_id' => $manifestId,
            'chunk_index' => $chunkIndex,
            'first_row_ordinal' => $firstOrdinal,
            'last_row_ordinal' => $lastOrdinal,
            'row_count' => $rowCount,
            'raw_bytes' => strlen($raw),
            'compressed_bytes' => strlen($compressed),
            'sha256' => hash('sha256', $raw),
            'payload_gzip_base64' => base64_encode($compressed),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int,array{schema:string,table:string}> */
    private function sourceTables(ConnectionInterface $db): array
    {
        return $db->table('INFORMATION_SCHEMA.TABLES')
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->orderBy('TABLE_SCHEMA')
            ->orderBy('TABLE_NAME')
            ->get(['TABLE_SCHEMA', 'TABLE_NAME'])
            ->map(static fn ($row): array => [
                'schema' => (string) $row->TABLE_SCHEMA,
                'table' => (string) $row->TABLE_NAME,
            ])
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function columns(ConnectionInterface $db, string $schema, string $table): array
    {
        return $db->table('INFORMATION_SCHEMA.COLUMNS')
            ->where('TABLE_SCHEMA', $schema)
            ->where('TABLE_NAME', $table)
            ->orderBy('ORDINAL_POSITION')
            ->get([
                'COLUMN_NAME',
                'ORDINAL_POSITION',
                'COLUMN_DEFAULT',
                'IS_NULLABLE',
                'DATA_TYPE',
                'CHARACTER_MAXIMUM_LENGTH',
                'NUMERIC_PRECISION',
                'NUMERIC_SCALE',
                'DATETIME_PRECISION',
            ])
            ->map(static fn ($row): array => [
                'name' => (string) $row->COLUMN_NAME,
                'ordinal' => (int) $row->ORDINAL_POSITION,
                'default' => $row->COLUMN_DEFAULT,
                'nullable' => strtoupper((string) $row->IS_NULLABLE) === 'YES',
                'data_type' => strtolower((string) $row->DATA_TYPE),
                'character_maximum_length' => $row->CHARACTER_MAXIMUM_LENGTH === null ? null : (int) $row->CHARACTER_MAXIMUM_LENGTH,
                'numeric_precision' => $row->NUMERIC_PRECISION === null ? null : (int) $row->NUMERIC_PRECISION,
                'numeric_scale' => $row->NUMERIC_SCALE === null ? null : (int) $row->NUMERIC_SCALE,
                'datetime_precision' => $row->DATETIME_PRECISION === null ? null : (int) $row->DATETIME_PRECISION,
            ])
            ->all();
    }

    /** @return array<int,string> */
    private function primaryKeyColumns(ConnectionInterface $db, string $schema, string $table): array
    {
        return $db->table('INFORMATION_SCHEMA.TABLE_CONSTRAINTS as tc')
            ->join('INFORMATION_SCHEMA.KEY_COLUMN_USAGE as ku', function ($join): void {
                $join->on('ku.CONSTRAINT_CATALOG', '=', 'tc.CONSTRAINT_CATALOG')
                    ->on('ku.CONSTRAINT_SCHEMA', '=', 'tc.CONSTRAINT_SCHEMA')
                    ->on('ku.CONSTRAINT_NAME', '=', 'tc.CONSTRAINT_NAME');
            })
            ->where('tc.CONSTRAINT_TYPE', 'PRIMARY KEY')
            ->where('tc.TABLE_SCHEMA', $schema)
            ->where('tc.TABLE_NAME', $table)
            ->orderBy('ku.ORDINAL_POSITION')
            ->pluck('ku.COLUMN_NAME')
            ->map(static fn ($column): string => (string) $column)
            ->all();
    }

    /** @param array<int,array<string,mixed>> $columns */
    private function normalizeRow(array $row, array $columns): array
    {
        $normalized = [];

        foreach ($columns as $column) {
            $name = (string) $column['name'];
            $normalized[$name] = $this->normalizeValue(
                $row[$name] ?? null,
                (string) $column['data_type'],
            );
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value, string $dataType): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        if (is_resource($value)) {
            $bytes = stream_get_contents($value);
            if (! is_string($bytes)) {
                throw new RuntimeException('Unable to read binary stream from legacy source.');
            }

            return ['__encoding' => 'base64', 'data' => base64_encode($bytes)];
        }

        $string = (string) $value;
        if (in_array(strtolower($dataType), self::BINARY_TYPES, true) || preg_match('//u', $string) !== 1) {
            return ['__encoding' => 'base64', 'data' => base64_encode($string)];
        }

        return $string;
    }

    private function sourceDatabaseName(ConnectionInterface $db): string
    {
        $row = $db->selectOne('SELECT DB_NAME() AS database_name');

        return (string) ($row->database_name ?? 'unknown');
    }

    private function chunkSize(): int
    {
        return max(50, min(2000, (int) config('database.connections.foodex_legacy.archive_chunk', 500)));
    }

    private function json(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode legacy database snapshot JSON.');
        }

        return $json;
    }

    private function assertReady(): void
    {
        foreach ([
            'legacy_database_snapshots',
            'legacy_database_snapshot_tables',
            'legacy_database_snapshot_chunks',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}. Run php artisan migrate --force first.");
            }
        }
    }

    private function legacySource(): ConnectionInterface
    {
        return DB::connection('foodex_legacy');
    }
}
