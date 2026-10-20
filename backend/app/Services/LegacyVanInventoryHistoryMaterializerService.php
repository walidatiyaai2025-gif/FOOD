<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class LegacyVanInventoryHistoryMaterializerService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';
    private const HEADER_TABLE = 'wh_CarTransHeader';
    private const DETAIL_TABLE = 'wh_CarTransDetails';

    public function materialize(bool $apply): array
    {
        $this->assertTables();

        $source = [
            'headers' => $this->archiveCount(self::HEADER_TABLE),
            'details' => $this->archiveCount(self::DETAIL_TABLE),
        ];

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'source' => $source,
                'target' => [
                    'headers' => (int) DB::table('van_inventory_history_documents')->count(),
                    'details' => (int) DB::table('van_inventory_history_lines')->count(),
                ],
            ];
        }

        $vanMap = $this->mapping('wh_Cars', 'vans');
        $warehouseMap = $this->mapping('wh_Cars', 'warehouses');
        $productMap = $this->mapping('wh_Items', 'products');

        DB::transaction(function () use ($vanMap, $warehouseMap, $productMap): void {
            DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->whereIn('source_table', [self::HEADER_TABLE, self::DETAIL_TABLE])
                ->whereIn('target_table', [
                    'van_inventory_history_documents',
                    'van_inventory_history_lines',
                ])
                ->delete();

            DB::table('van_inventory_history_lines')->delete();
            DB::table('van_inventory_history_documents')->delete();

            $headerIds = [];

            DB::table('legacy_source_archive_rows')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', self::HEADER_TABLE)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($vanMap, $warehouseMap, &$headerIds): void {
                    foreach ($rows as $row) {
                        $payload = $this->decodePayload((string) $row->payload, self::HEADER_TABLE, (int) $row->source_row_ordinal);
                        $legacyHeaderId = $this->integer($payload, [
                            'HeaderId', 'HeaderID', 'TransHeaderId', 'TransHeaderID', 'ID', 'Id',
                        ]) ?? $this->integerFromSourceKey((string) ($row->source_key ?? ''), [
                            'HeaderId', 'HeaderID', 'ID', 'Id',
                        ]);
                        $legacyCarId = $this->integer($payload, [
                            'CarId', 'CarID', 'Car_Id', 'StoreId', 'StoreID',
                        ]);
                        $transactionType = $this->integer($payload, [
                            'TransType', 'TransactionType', 'TransTypeId', 'TransTypeID',
                        ]);
                        $documentNumber = $this->string($payload, [
                            'TransNo', 'TransactionNo', 'DocumentNo', 'DocNo', 'Code', 'Number',
                        ]);
                        $occurredAt = $this->date($payload, [
                            'TransDate', 'TransactionDate', 'DocDate', 'DocumentDate', 'Date', 'CreatedDate',
                        ]);
                        $status = $this->string($payload, ['Status', 'TransStatus', 'State']);

                        $targetId = (int) DB::table('van_inventory_history_documents')->insertGetId([
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_table' => self::HEADER_TABLE,
                            'source_row_ordinal' => (int) $row->source_row_ordinal,
                            'source_key' => $row->source_key,
                            'legacy_header_id' => $legacyHeaderId,
                            'van_id' => $legacyCarId === null ? null : ($vanMap[$legacyCarId] ?? null),
                            'warehouse_id' => $legacyCarId === null ? null : ($warehouseMap[$legacyCarId] ?? null),
                            'legacy_transaction_type' => $transactionType,
                            'document_number' => $documentNumber === null ? null : mb_substr($documentNumber, 0, 160),
                            'occurred_at' => $occurredAt,
                            'legacy_status' => $status === null ? null : mb_substr($status, 0, 80),
                            'source_hash' => (string) $row->row_hash,
                            'source_payload' => (string) $row->payload,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        if ($legacyHeaderId !== null) {
                            $headerIds[$legacyHeaderId] = $targetId;
                        }

                        $this->syncMapping(
                            self::HEADER_TABLE,
                            $this->stableSourceKey($row),
                            'van_inventory_history_documents',
                            $targetId,
                            (string) $row->row_hash,
                            [
                                'legacy_header_id' => $legacyHeaderId,
                                'legacy_car_id' => $legacyCarId,
                            ],
                        );
                    }
                });

            DB::table('legacy_source_archive_rows')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', self::DETAIL_TABLE)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($productMap, $headerIds): void {
                    foreach ($rows as $row) {
                        $payload = $this->decodePayload((string) $row->payload, self::DETAIL_TABLE, (int) $row->source_row_ordinal);
                        $legacyDetailId = $this->integer($payload, [
                            'DetailId', 'DetailID', 'TransDetailId', 'TransDetailID', 'ID', 'Id',
                        ]) ?? $this->integerFromSourceKey((string) ($row->source_key ?? ''), [
                            'DetailId', 'DetailID', 'ID', 'Id',
                        ]);
                        $legacyHeaderId = $this->integer($payload, [
                            'HeaderId', 'HeaderID', 'TransHeaderId', 'TransHeaderID',
                        ]);
                        $legacyItemId = $this->integer($payload, [
                            'ItemID', 'ItemId', 'ProductID', 'ProductId',
                        ]);
                        $quantity = $this->decimal($payload, [
                            'TransPkgQty1', 'PkgQty1', 'Quantity', 'Qty', 'ItemQty',
                        ]);
                        $unitPrice = $this->decimal($payload, [
                            'Price', 'UnitPrice', 'ItemPrice', 'NetPrice',
                        ]);
                        $lineTotal = $this->decimal($payload, [
                            'Total', 'LineTotal', 'NetTotal', 'Amount',
                        ]);

                        $targetId = (int) DB::table('van_inventory_history_lines')->insertGetId([
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_table' => self::DETAIL_TABLE,
                            'source_row_ordinal' => (int) $row->source_row_ordinal,
                            'source_key' => $row->source_key,
                            'document_id' => $legacyHeaderId === null ? null : ($headerIds[$legacyHeaderId] ?? null),
                            'legacy_detail_id' => $legacyDetailId,
                            'legacy_header_id' => $legacyHeaderId,
                            'product_id' => $legacyItemId === null ? null : ($productMap[$legacyItemId] ?? null),
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'line_total' => $lineTotal,
                            'source_hash' => (string) $row->row_hash,
                            'source_payload' => (string) $row->payload,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $this->syncMapping(
                            self::DETAIL_TABLE,
                            $this->stableSourceKey($row),
                            'van_inventory_history_lines',
                            $targetId,
                            (string) $row->row_hash,
                            [
                                'legacy_detail_id' => $legacyDetailId,
                                'legacy_header_id' => $legacyHeaderId,
                                'legacy_item_id' => $legacyItemId,
                            ],
                        );
                    }
                });

            if (Schema::hasTable('legacy_business_records')) {
                DB::table('legacy_business_records')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->whereIn('source_table', [self::HEADER_TABLE, self::DETAIL_TABLE])
                    ->delete();
            }

            if (Schema::hasTable('legacy_business_table_manifest')) {
                DB::table('legacy_business_table_manifest')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->whereIn('source_table', [self::HEADER_TABLE, self::DETAIL_TABLE])
                    ->delete();
            }
        }, 3);

        return [
            'mode' => 'apply',
            ...$this->verify(),
        ];
    }

    public function verify(): array
    {
        $sourceHeaders = $this->archiveCount(self::HEADER_TABLE);
        $sourceDetails = $this->archiveCount(self::DETAIL_TABLE);
        $targetHeaders = (int) DB::table('van_inventory_history_documents')->count();
        $targetDetails = (int) DB::table('van_inventory_history_lines')->count();

        $headerMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', self::HEADER_TABLE)
            ->where('target_table', 'van_inventory_history_documents')
            ->count();

        $detailMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', self::DETAIL_TABLE)
            ->where('target_table', 'van_inventory_history_lines')
            ->count();

        $unlinkedDetails = (int) DB::table('van_inventory_history_lines')
            ->whereNotNull('legacy_header_id')
            ->whereNull('document_id')
            ->count();

        $unmappedProducts = (int) DB::table('van_inventory_history_lines')
            ->whereRaw("JSON_EXTRACT(source_payload, '$.ItemID') IS NOT NULL")
            ->whereNull('product_id')
            ->count();

        $documentsWithVan = (int) DB::table('van_inventory_history_documents')
            ->whereNotNull('van_id')
            ->count();

        $healthy = $sourceHeaders === $targetHeaders
            && $sourceDetails === $targetDetails
            && $headerMappings === $sourceHeaders
            && $detailMappings === $sourceDetails
            && $unlinkedDetails === 0;

        return [
            'healthy' => $healthy,
            'source' => [
                'headers' => $sourceHeaders,
                'details' => $sourceDetails,
            ],
            'target' => [
                'headers' => $targetHeaders,
                'details' => $targetDetails,
                'header_mappings' => $headerMappings,
                'detail_mappings' => $detailMappings,
                'documents_linked_to_van' => $documentsWithVan,
                'unlinked_details_with_header_id' => $unlinkedDetails,
                'unmapped_product_lines' => $unmappedProducts,
            ],
            'authority' => [
                'stock_balance_source' => 'wh_TransHeader + wh_TransDetails',
                'car_transaction_role' => 'historical operational audit only',
                'mutates_inventory_balance' => false,
            ],
        ];
    }

    private function archiveCount(string $table): int
    {
        return (int) DB::table('legacy_source_archive_rows')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', $table)
            ->count();
    }

    /** @return array<int,int> */
    private function mapping(string $sourceTable, string $targetTable): array
    {
        return DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', $sourceTable)
            ->where('target_table', $targetTable)
            ->get(['source_key', 'target_id'])
            ->mapWithKeys(static fn ($row): array => [(int) $row->source_key => (int) $row->target_id])
            ->all();
    }

    private function syncMapping(
        string $sourceTable,
        string $sourceKey,
        string $targetTable,
        int $targetId,
        string $sourceHash,
        array $metadata,
    ): void {
        DB::table('legacy_import_mappings')->updateOrInsert(
            [
                'source_system' => self::SOURCE_SYSTEM,
                'source_table' => $sourceTable,
                'source_key' => $sourceKey,
                'target_table' => $targetTable,
            ],
            [
                'target_id' => $targetId,
                'source_hash' => $sourceHash,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_run_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function stableSourceKey(object $row): string
    {
        $key = trim((string) ($row->source_key ?? ''));

        return $key !== '' ? $key : '__ordinal:'.(int) $row->source_row_ordinal;
    }

    private function decodePayload(string $json, string $table, int $ordinal): array
    {
        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            throw new RuntimeException("Invalid archived JSON for {$table} row {$ordinal}.");
        }

        return $payload;
    }

    private function normalized(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key));
            $result[$normalized] = $this->unwrap($value);
        }

        return $result;
    }

    private function unwrap(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }

        return $value;
    }

    private function first(array $payload, array $aliases): mixed
    {
        $normalized = $this->normalized($payload);

        foreach ($aliases as $alias) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $alias));
            if (array_key_exists($key, $normalized)) {
                return $normalized[$key];
            }
        }

        return null;
    }

    private function integer(array $payload, array $aliases): ?int
    {
        $value = $this->first($payload, $aliases);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function decimal(array $payload, array $aliases): ?float
    {
        $value = $this->first($payload, $aliases);

        return is_numeric($value) ? (float) $value : null;
    }

    private function string(array $payload, array $aliases): ?string
    {
        $value = $this->first($payload, $aliases);
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(array $payload, array $aliases): ?string
    {
        $value = $this->string($payload, $aliases);
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function integerFromSourceKey(string $sourceKey, array $aliases): ?int
    {
        $decoded = json_decode($sourceKey, true);

        return is_array($decoded) ? $this->integer($decoded, $aliases) : null;
    }

    private function assertTables(): void
    {
        foreach ([
            'legacy_source_archive_rows',
            'legacy_import_mappings',
            'van_inventory_history_documents',
            'van_inventory_history_lines',
            'vans',
            'warehouses',
            'products',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}. Run migrations/canonical masters first.");
            }
        }
    }
}
