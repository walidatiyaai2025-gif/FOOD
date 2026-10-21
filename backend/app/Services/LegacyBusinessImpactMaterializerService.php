<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyBusinessImpactMaterializerService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    private const CANONICAL_INPUT_TABLES = [
        'fw_Cities',
        'fw_Regions',
        'fw_Districts',
        'wh_Stores',
        'wh_Cars',
        'wh_ItemGroups',
        'wh_ItemSubGroups',
        'wh_Items',
        'wh_ItemBarCodes',
        'wh_Customers',
        'wh_PriceTypes',
        'wh_Suppliers',
        'wh_SalesReps',
        'wh_TransHeader',
        'wh_TransDetails',
        'wh_PrTrans',
        'wh_PrTransTypes',
        'wh_InOutBank',
        'wh_ItemStockOnHand',
    ];

    public function preview(): array
    {
        $this->assertTables();

        $represented = $this->representedTables();
        $rows = [];
        $summary = $this->emptySummary();

        foreach ($this->archiveTables() as $archive) {
            $classification = $this->classify(
                (string) $archive->source_table,
                (int) $archive->source_row_count,
                $represented,
            );

            $rows[] = [
                'schema' => (string) $archive->source_schema,
                'table' => (string) $archive->source_table,
                'source_rows' => (int) $archive->source_row_count,
                ...$classification,
            ];

            $this->accumulate($summary, $classification, (int) $archive->source_row_count);
        }

        return [
            'summary' => $summary,
            'tables' => $rows,
        ];
    }

    public function materialize(bool $apply, ?callable $progress = null): array
    {
        $this->assertTables();

        $preview = $this->preview();

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                ...$preview,
            ];
        }

        foreach ($preview['tables'] as $table) {
            $schema = (string) $table['schema'];
            $name = (string) $table['table'];
            $sourceRows = (int) $table['source_rows'];

            if ($progress !== null) {
                $progress('business_'.$schema.'.'.$name, 'start', null);
            }

            $startedAt = microtime(true);

            if (! $table['materialize']) {
                $this->upsertManifest(
                    $schema,
                    $name,
                    (string) $table['domain'],
                    (string) $table['classification'],
                    (bool) $table['business_impact'],
                    false,
                    $sourceRows,
                    0,
                    'skipped',
                    (string) $table['reason'],
                );

                if ($progress !== null) {
                    $progress('business_'.$schema.'.'.$name, 'done', round(microtime(true) - $startedAt, 3));
                }

                continue;
            }

            DB::transaction(function () use ($schema, $name, $sourceRows, $table): void {
                DB::table('legacy_business_records')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_schema', $schema)
                    ->where('source_table', $name)
                    ->delete();

                $inserted = 0;

                DB::table('legacy_source_archive_rows')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_schema', $schema)
                    ->where('source_table', $name)
                    ->orderBy('id')
                    ->chunkById(500, function ($rows) use ($schema, $name, $table, &$inserted): void {
                        $batch = [];

                        foreach ($rows as $row) {
                            $batch[] = [
                                'source_system' => self::SOURCE_SYSTEM,
                                'source_schema' => $schema,
                                'source_table' => $name,
                                'domain' => (string) $table['domain'],
                                'source_row_ordinal' => (int) $row->source_row_ordinal,
                                'source_key' => $row->source_key,
                                'row_hash' => (string) $row->row_hash,
                                'payload' => (string) $row->payload,
                                'created_at' => now(),
                            ];
                        }

                        if ($batch !== []) {
                            DB::table('legacy_business_records')->insert($batch);
                            $inserted += count($batch);
                        }
                    });

                if ($inserted !== $sourceRows) {
                    throw new RuntimeException(
                        "Business materialization row mismatch for {$schema}.{$name}: source={$sourceRows}, materialized={$inserted}"
                    );
                }

                $this->upsertManifest(
                    $schema,
                    $name,
                    (string) $table['domain'],
                    (string) $table['classification'],
                    (bool) $table['business_impact'],
                    true,
                    $sourceRows,
                    $inserted,
                    'completed',
                    (string) $table['reason'],
                );
            }, 3);

            if ($progress !== null) {
                $progress('business_'.$schema.'.'.$name, 'done', round(microtime(true) - $startedAt, 3));
            }
        }

        return [
            'mode' => 'apply',
            ...$this->verify(),
        ];
    }

    public function verify(): array
    {
        $this->assertTables();

        $represented = $this->representedTables();
        $summary = $this->emptySummary();
        $rows = [];
        $businessFailures = [];
        $materializationFailures = [];

        foreach ($this->archiveTables() as $archive) {
            $schema = (string) $archive->source_schema;
            $table = (string) $archive->source_table;
            $sourceRows = (int) $archive->source_row_count;

            $classification = $this->classify($table, $sourceRows, $represented);
            $materialized = (int) DB::table('legacy_business_records')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_schema', $schema)
                ->where('source_table', $table)
                ->count();

            $this->accumulate($summary, $classification, $sourceRows);

            $row = [
                'schema' => $schema,
                'table' => $table,
                'source_rows' => $sourceRows,
                'materialized_rows' => $materialized,
                ...$classification,
            ];

            if (
                $classification['materialize']
                && $materialized !== $sourceRows
            ) {
                $materializationFailures[] = $row;
            }

            if (
                $classification['business_impact']
                && ! $classification['represented']
                && (
                    ! $classification['materialize']
                    || $materialized !== $sourceRows
                )
            ) {
                $businessFailures[] = $row;
            }

            $rows[] = $row;
        }

        $domainCounts = DB::table('legacy_business_records')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->selectRaw('domain, COUNT(*) as row_count')
            ->groupBy('domain')
            ->orderBy('domain')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->domain => (int) $row->row_count,
            ])
            ->all();

        return [
            'healthy' => $businessFailures === [] && $materializationFailures === [],
            'summary' => $summary,
            'domain_rows' => $domainCounts,
            'business_failures' => $businessFailures,
            'materialization_failures' => $materializationFailures,
            'tables' => $rows,
        ];
    }

    private function classify(string $table, int $sourceRows, array $represented): array
    {
        if ($sourceRows === 0) {
            return [
                'domain' => 'empty',
                'classification' => 'empty_source',
                'business_impact' => false,
                'materialize' => false,
                'represented' => true,
                'reason' => 'Source table is empty.',
            ];
        }

        if (isset($represented[$table])) {
            return [
                'domain' => (string) $represented[$table]['domain'],
                'classification' => (string) $represented[$table]['classification'],
                'business_impact' => (bool) $represented[$table]['business_impact'],
                'materialize' => false,
                'represented' => true,
                'reason' => (string) $represented[$table]['reason'],
            ];
        }

        if ($this->isTransientTable($table)) {
            return [
                'domain' => 'technical_staging',
                'classification' => 'technical_staging',
                'business_impact' => false,
                'materialize' => false,
                'represented' => false,
                'reason' => 'Transient/temp/staging table; preserved losslessly in full archive but excluded from canonical business state.',
            ];
        }

        [$domain, $impact, $reason] = $this->domain($table);

        return [
            'domain' => $domain,
            'classification' => 'business_extension',
            'business_impact' => $impact,
            'materialize' => true,
            'represented' => false,
            'reason' => $reason,
        ];
    }

    private function representedTables(): array
    {
        $represented = [];

        foreach (self::CANONICAL_INPUT_TABLES as $table) {
            $represented[$table] = [
                'domain' => $this->domain($table)[0],
                'classification' => 'canonical_input',
                'business_impact' => true,
                'reason' => 'Consumed directly by canonical importer.',
            ];
        }

        if (Schema::hasTable('legacy_import_mappings')) {
            foreach (
                DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->distinct()
                    ->pluck('source_table') as $table
            ) {
                $name = (string) $table;
                $represented[$name] = [
                    'domain' => $this->domain($name)[0],
                    'classification' => 'canonical_mapped',
                    'business_impact' => true,
                    'reason' => 'Has canonical legacy_import_mappings provenance.',
                ];
            }
        }

        if (Schema::hasTable('legacy_import_reference_records')) {
            foreach (
                DB::table('legacy_import_reference_records')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->distinct()
                    ->pluck('source_table') as $table
            ) {
                $name = (string) $table;
                $represented[$name] = [
                    'domain' => $this->domain($name)[0],
                    'classification' => 'reference_preserved',
                    'business_impact' => true,
                    'reason' => 'Preserved as a canonical legacy reference record.',
                ];
            }
        }

        if (Schema::hasTable('legacy_invoice_source_records')) {
            foreach (
                DB::table('legacy_invoice_source_records')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->distinct()
                    ->pluck('source_table') as $table
            ) {
                $name = (string) $table;
                $represented[$name] = [
                    'domain' => 'sales_invoicing',
                    'classification' => 'invoice_extension',
                    'business_impact' => true,
                    'reason' => 'Materialized and linked to canonical invoices.',
                ];
            }
        }

        return $represented;
    }

    /** @return array{0:string,1:bool,2:string} */
    private function domain(string $table): array
    {
        $normalized = strtolower($table);

        if (str_starts_with($normalized, 'wh_')) {
            if (str_contains($normalized, 'car')) {
                return ['van_operations', true, 'Warehouse/van operational business data.'];
            }

            if (str_contains($normalized, 'supplier') || str_contains($normalized, 'prtrans')) {
                return ['procurement', true, 'Procurement/supplier business data.'];
            }

            if (str_contains($normalized, 'customer')) {
                return ['customers_commercial', true, 'Customer/commercial business data.'];
            }

            if (str_contains($normalized, 'item') || str_contains($normalized, 'stock') || str_contains($normalized, 'assembly') || str_contains($normalized, 'production')) {
                return ['inventory_production', true, 'Inventory/catalog/production business data.'];
            }

            return ['warehouse_sales', true, 'Warehouse/sales operational business data.'];
        }

        if (str_starts_with($normalized, 'gl_')) {
            if (str_contains($normalized, 'asset')) {
                return ['finance_assets', true, 'Fixed-asset/accounting business data.'];
            }

            return ['finance_accounting', true, 'General-ledger/accounting business data.'];
        }

        if (str_starts_with($normalized, 'hr_')) {
            return ['hr_payroll', true, 'HR/payroll/attendance business data.'];
        }

        if (str_starts_with($normalized, 'sh_')) {
            return ['logistics_shipping', true, 'Shipping/freight business data.'];
        }

        if (str_starts_with($normalized, 'cm_')) {
            return ['fleet_operations', true, 'Fleet/car operational business data.'];
        }

        if (str_starts_with($normalized, 'md_')) {
            return ['business_reference', true, 'Business master/reference data.'];
        }

        if (str_starts_with($normalized, 'sp_')) {
            return ['catalog_master', true, 'Catalog/business master data.'];
        }

        if (str_starts_with($normalized, 'jw_')) {
            return ['inventory_extension', true, 'Inventory/product extension business data.'];
        }

        if (str_starts_with($normalized, 'fw_')) {
            return ['legacy_platform_reference', true, 'Legacy platform/reference/configuration data retained because it may affect business operation.'];
        }

        if (str_starts_with($normalized, 'lg_')) {
            return ['legacy_security_reference', true, 'Legacy security/screen configuration retained for audit and migration reference.'];
        }

        return ['business_other', true, 'Unrecognized non-empty source table is treated as business-impact by default to prevent silent data loss.'];
    }

    private function isTransientTable(string $table): bool
    {
        $normalized = strtolower($table);

        return str_contains($normalized, 'temp')
            || str_contains($normalized, 'tmp');
    }

    private function archiveTables(): \Illuminate\Support\Collection
    {
        return DB::table('legacy_source_archive_tables')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->orderBy('source_schema')
            ->orderBy('source_table')
            ->get([
                'source_schema',
                'source_table',
                'source_row_count',
            ]);
    }

    private function upsertManifest(
        string $schema,
        string $table,
        string $domain,
        string $classification,
        bool $businessImpact,
        bool $materialize,
        int $sourceRows,
        int $materializedRows,
        string $status,
        string $reason,
    ): void {
        DB::table('legacy_business_table_manifest')->updateOrInsert(
            [
                'source_system' => self::SOURCE_SYSTEM,
                'source_schema' => $schema,
                'source_table' => $table,
            ],
            [
                'domain' => $domain,
                'classification' => $classification,
                'business_impact' => $businessImpact,
                'materialize' => $materialize,
                'source_row_count' => $sourceRows,
                'materialized_row_count' => $materializedRows,
                'status' => $status,
                'reason' => $reason,
                'verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function emptySummary(): array
    {
        return [
            'source_tables' => 0,
            'source_rows' => 0,
            'already_represented_tables' => 0,
            'already_represented_rows' => 0,
            'business_extension_tables' => 0,
            'business_extension_rows' => 0,
            'technical_staging_tables' => 0,
            'technical_staging_rows' => 0,
            'business_impact_tables' => 0,
            'business_impact_rows' => 0,
        ];
    }

    private function accumulate(array &$summary, array $classification, int $rows): void
    {
        $summary['source_tables']++;
        $summary['source_rows'] += $rows;

        if ($classification['represented']) {
            $summary['already_represented_tables']++;
            $summary['already_represented_rows'] += $rows;
        }

        if ($classification['classification'] === 'business_extension') {
            $summary['business_extension_tables']++;
            $summary['business_extension_rows'] += $rows;
        }

        if ($classification['classification'] === 'technical_staging') {
            $summary['technical_staging_tables']++;
            $summary['technical_staging_rows'] += $rows;
        }

        if ($classification['business_impact']) {
            $summary['business_impact_tables']++;
            $summary['business_impact_rows'] += $rows;
        }
    }

    private function assertTables(): void
    {
        foreach ([
            'legacy_source_archive_tables',
            'legacy_source_archive_rows',
            'legacy_business_table_manifest',
            'legacy_business_records',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}. Run php artisan migrate first.");
            }
        }
    }
}
