<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            ! Schema::hasTable('driver_current_locations')
            || ! Schema::hasColumn('driver_current_locations', 'received_at')
        ) {
            return;
        }

        $this->normalizeLegacyReceivedAt();

        $indexes = array_column(Schema::getIndexes('driver_current_locations'), 'name');
        if (in_array('driver_current_locations_received_at_idx', $indexes, true)) {
            return;
        }

        Schema::table('driver_current_locations', function (Blueprint $table): void {
            $table->index('received_at', 'driver_current_locations_received_at_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('driver_current_locations')) {
            return;
        }

        $indexes = array_column(Schema::getIndexes('driver_current_locations'), 'name');
        if (! in_array('driver_current_locations_received_at_idx', $indexes, true)) {
            return;
        }

        Schema::table('driver_current_locations', function (Blueprint $table): void {
            $table->dropIndex('driver_current_locations_received_at_idx');
        });
    }

    /**
     * Older production installs may have received_at as a TIMESTAMP with the
     * legacy zero-date default. Under modern strict MySQL modes, any ALTER
     * TABLE that rebuilds the table then fails with error 1067 before the new
     * index can be created. Normalize only the drifted MySQL definition to the
     * canonical FOODEX schema: DATETIME NOT NULL with no implicit/default zero.
     */
    private function normalizeLegacyReceivedAt(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $column = DB::selectOne(
            <<<'SQL'
SELECT
    DATA_TYPE AS data_type,
    IS_NULLABLE AS is_nullable,
    COLUMN_DEFAULT AS column_default
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'driver_current_locations'
  AND COLUMN_NAME = 'received_at'
LIMIT 1
SQL
        );

        if (! is_object($column)) {
            return;
        }

        $dataType = strtolower((string) ($column->data_type ?? ''));
        $isNullable = strtoupper((string) ($column->is_nullable ?? 'YES'));
        $default = $column->column_default ?? null;
        $hasZeroDefault = is_string($default) && str_starts_with($default, '0000-00-00');

        if ($dataType === 'datetime' && $isNullable === 'NO' && $default === null) {
            return;
        }

        if ($dataType !== 'timestamp' && ! $hasZeroDefault && $dataType !== 'datetime') {
            return;
        }

        // Preserve meaningful production timestamps. Only null/zero legacy
        // values are repaired, preferring the device capture time, then the
        // row creation time, then the database server clock.
        DB::statement(
            <<<'SQL'
UPDATE `driver_current_locations`
SET `received_at` = CASE
    WHEN `captured_at` IS NOT NULL
         AND CAST(`captured_at` AS CHAR) NOT LIKE '0000-00-00%'
        THEN `captured_at`
    WHEN `created_at` IS NOT NULL
         AND CAST(`created_at` AS CHAR) NOT LIKE '0000-00-00%'
        THEN `created_at`
    ELSE NOW()
END
WHERE `received_at` IS NULL
   OR CAST(`received_at` AS CHAR) LIKE '0000-00-00%'
SQL
        );

        DB::statement(
            'ALTER TABLE `driver_current_locations` MODIFY `received_at` DATETIME NOT NULL'
        );
    }
};
