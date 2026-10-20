<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyCanonicalCoverageService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    /**
     * Source tables consumed directly by canonical import logic even when a
     * row-for-row legacy_import_mappings record is not appropriate.
     */
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

    public function report(): array
    {
        if (! Schema::hasTable('legacy_source_archive_tables')) {
            throw new RuntimeException('Missing legacy_source_archive_tables.');
        }

        $mapped = Schema::hasTable('legacy_import_mappings')
            ? DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->distinct()
                ->pluck('source_table')
                ->map(static fn ($value): string => (string) $value)
                ->flip()
                ->all()
            : [];

        $references = Schema::hasTable('legacy_import_reference_records')
            ? DB::table('legacy_import_reference_records')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->distinct()
                ->pluck('source_table')
                ->map(static fn ($value): string => (string) $value)
                ->flip()
                ->all()
            : [];

        $invoiceExtensions = Schema::hasTable('legacy_invoice_source_records')
            ? DB::table('legacy_invoice_source_records')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->distinct()
                ->pluck('source_table')
                ->map(static fn ($value): string => (string) $value)
                ->flip()
                ->all()
            : [];

        $knownInputs = array_fill_keys(self::CANONICAL_INPUT_TABLES, true);

        $rows = [];
        $summary = [
            'empty_source_tables' => 0,
            'canonical_mapped_tables' => 0,
            'canonical_input_tables' => 0,
            'reference_preserved_tables' => 0,
            'invoice_extension_tables' => 0,
            'archive_only_tables' => 0,
            'archive_only_rows' => 0,
        ];

        foreach (
            DB::table('legacy_source_archive_tables')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->orderBy('source_schema')
                ->orderBy('source_table')
                ->get(['source_schema', 'source_table', 'source_row_count', 'archived_row_count', 'status']) as $row
        ) {
            $table = (string) $row->source_table;
            $sourceRows = (int) $row->source_row_count;

            if ($sourceRows === 0) {
                $classification = 'empty_source';
                $summary['empty_source_tables']++;
            } elseif (isset($mapped[$table])) {
                $classification = 'canonical_mapped';
                $summary['canonical_mapped_tables']++;
            } elseif (isset($invoiceExtensions[$table])) {
                $classification = 'invoice_extension';
                $summary['invoice_extension_tables']++;
            } elseif (isset($references[$table])) {
                $classification = 'reference_preserved';
                $summary['reference_preserved_tables']++;
            } elseif (isset($knownInputs[$table])) {
                $classification = 'canonical_input';
                $summary['canonical_input_tables']++;
            } else {
                $classification = 'archive_only';
                $summary['archive_only_tables']++;
                $summary['archive_only_rows'] += $sourceRows;
            }

            $rows[] = [
                'schema' => (string) $row->source_schema,
                'table' => $table,
                'source_rows' => $sourceRows,
                'archived_rows' => (int) $row->archived_row_count,
                'archive_status' => (string) $row->status,
                'classification' => $classification,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['classification'] === $b['classification']) {
                return $b['source_rows'] <=> $a['source_rows'];
            }

            if ($a['classification'] === 'archive_only') {
                return -1;
            }

            if ($b['classification'] === 'archive_only') {
                return 1;
            }

            return strcmp($a['table'], $b['table']);
        });

        return [
            'healthy_archive' => collect($rows)->every(
                static fn (array $row): bool => $row['source_rows'] === $row['archived_rows']
                    && $row['archive_status'] === 'completed'
            ),
            'summary' => $summary,
            'archive_only_nonempty' => array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['classification'] === 'archive_only' && $row['source_rows'] > 0
            )),
            'tables' => $rows,
        ];
    }
}
