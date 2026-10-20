<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyInvoiceSourceMaterializerService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    /**
     * Stable legacy transaction satellites that carry invoice/order-adjacent
     * business data not represented by invoices/invoice_items themselves.
     */
    private const SOURCE_TABLES = [
        'wh_TransHCoupon',
        'wh_TransHeader_Attachment',
        'wh_TransHeaderInventory',
        'wh_TransHeaderOrders',
        'wh_TransInstallments',
    ];

    private const HEADER_KEYS = [
        'HeaderId',
        'HeaderID',
        'TransHeaderId',
        'TransHeaderID',
        'InvHeaderId',
        'InvHeaderID',
        'InvoiceHeaderId',
        'InvoiceHeaderID',
    ];

    public function materialize(bool $apply): array
    {
        $this->assertTables();

        $sourceCounts = $this->archiveCounts();

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'source_tables' => self::SOURCE_TABLES,
                'source_counts' => $sourceCounts,
                'source_rows' => array_sum($sourceCounts),
                'existing_materialized_rows' => (int) DB::table('legacy_invoice_source_records')->count(),
            ];
        }

        $headerToInvoice = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_TransHeader')
            ->where('target_table', 'invoices')
            ->get(['source_key', 'target_id'])
            ->mapWithKeys(static fn ($row): array => [(int) $row->source_key => (int) $row->target_id])
            ->all();

        DB::transaction(function () use ($headerToInvoice): void {
            DB::table('legacy_invoice_source_records')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->whereIn('source_table', self::SOURCE_TABLES)
                ->delete();

            DB::table('legacy_source_archive_rows')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->whereIn('source_table', self::SOURCE_TABLES)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($headerToInvoice): void {
                    $insert = [];

                    foreach ($rows as $row) {
                        $payload = json_decode((string) $row->payload, true);
                        if (! is_array($payload)) {
                            throw new RuntimeException(
                                'Invalid archived JSON payload for '.$row->source_table.' row '.$row->source_row_ordinal
                            );
                        }

                        [$headerId, $headerKey] = $this->resolveHeaderId($payload);

                        $insert[] = [
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_schema' => (string) $row->source_schema,
                            'source_table' => (string) $row->source_table,
                            'source_row_ordinal' => (int) $row->source_row_ordinal,
                            'source_key' => $row->source_key,
                            'source_header_id' => $headerId,
                            'header_key_name' => $headerKey,
                            'invoice_id' => $headerId === null ? null : ($headerToInvoice[$headerId] ?? null),
                            'row_hash' => (string) $row->row_hash,
                            'payload' => (string) $row->payload,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if ($insert !== []) {
                        DB::table('legacy_invoice_source_records')->insert($insert);
                    }
                });
        }, 3);

        return [
            'mode' => 'apply',
            ...$this->verify(),
        ];
    }

    public function verify(): array
    {
        $this->assertTables();

        $sourceCounts = $this->archiveCounts();
        $targetCounts = [];
        $linkedCounts = [];
        $unresolvedHeaderCounts = [];

        foreach (self::SOURCE_TABLES as $table) {
            $targetCounts[$table] = (int) DB::table('legacy_invoice_source_records')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', $table)
                ->count();

            $linkedCounts[$table] = (int) DB::table('legacy_invoice_source_records')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', $table)
                ->whereNotNull('invoice_id')
                ->count();

            $unresolvedHeaderCounts[$table] = (int) DB::table('legacy_invoice_source_records')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', $table)
                ->whereNull('source_header_id')
                ->count();
        }

        $healthy = true;
        foreach (self::SOURCE_TABLES as $table) {
            if (($sourceCounts[$table] ?? 0) !== ($targetCounts[$table] ?? 0)) {
                $healthy = false;
                break;
            }
        }

        return [
            'healthy' => $healthy,
            'source_counts' => $sourceCounts,
            'materialized_counts' => $targetCounts,
            'linked_to_invoice_counts' => $linkedCounts,
            'rows_without_header_identifier' => $unresolvedHeaderCounts,
            'source_rows' => array_sum($sourceCounts),
            'materialized_rows' => array_sum($targetCounts),
            'linked_to_invoice_rows' => array_sum($linkedCounts),
        ];
    }

    /** @return array{0:?int,1:?string} */
    private function resolveHeaderId(array $payload): array
    {
        foreach (self::HEADER_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $id = (int) $value;

                return $id > 0 ? [$id, $key] : [null, $key];
            }
        }

        return [null, null];
    }

    /** @return array<string,int> */
    private function archiveCounts(): array
    {
        $counts = [];

        foreach (self::SOURCE_TABLES as $table) {
            $counts[$table] = (int) DB::table('legacy_source_archive_rows')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', $table)
                ->count();
        }

        return $counts;
    }

    private function assertTables(): void
    {
        foreach ([
            'legacy_source_archive_rows',
            'legacy_invoice_source_records',
            'legacy_import_mappings',
            'invoices',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}. Run php artisan migrate first.");
            }
        }
    }
}
