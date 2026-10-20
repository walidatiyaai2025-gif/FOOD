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
                            'CarId', 'CarID', 'Car_Id', 'CarNo', 'CarNumber', 'StoreId', 'StoreID',
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

                        $vanId = $legacyCarId === null ? null : ($vanMap[$legacyCarId] ?? null);
                        $warehouseId = $legacyCarId === null ? null : ($warehouseMap[$legacyCarId] ?? null);
                        $documentLinkStatus = $legacyCarId === null
                            ? 'missing_source_car_identifier'
                            : ($vanId !== null ? 'linked_van' : 'source_car_unmapped');
                        $documentLinkNote = match ($documentLinkStatus) {
                            'linked_van' => null,
                            'missing_source_car_identifier' => 'Source header does not expose a positive legacy car identifier.',
                            default => 'Source header references legacy car '.$legacyCarId.' but no wh_Cars -> vans mapping exists.',
                        };

                        $targetId = (int) DB::table('van_inventory_history_documents')->insertGetId([
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_table' => self::HEADER_TABLE,
                            'source_row_ordinal' => (int) $row->source_row_ordinal,
                            'source_key' => $row->source_key,
                            'legacy_header_id' => $legacyHeaderId,
                            'legacy_car_id' => $legacyCarId,
                            'van_id' => $vanId,
                            'warehouse_id' => $warehouseId,
                            'link_status' => $documentLinkStatus,
                            'link_note' => $documentLinkNote,
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
                            'CarTransHeaderId', 'CarTransHeaderID', 'CarTransHId', 'CarTransHID',
                            'TransHId', 'TransHID',
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

                        $documentId = $legacyHeaderId === null ? null : ($headerIds[$legacyHeaderId] ?? null);
                        $lineLinkStatus = $legacyHeaderId === null
                            ? 'missing_source_header_identifier'
                            : ($documentId !== null ? 'linked_header' : 'source_orphan_header');
                        $lineLinkNote = match ($lineLinkStatus) {
                            'linked_header' => null,
                            'missing_source_header_identifier' => 'Source detail does not expose a positive legacy header identifier.',
                            default => 'Source detail references legacy header '.$legacyHeaderId.' which is absent from wh_CarTransHeader archive.',
                        };

                        $targetId = (int) DB::table('van_inventory_history_lines')->insertGetId([
                            'source_system' => self::SOURCE_SYSTEM,
                            'source_table' => self::DETAIL_TABLE,
                            'source_row_ordinal' => (int) $row->source_row_ordinal,
                            'source_key' => $row->source_key,
                            'document_id' => $documentId,
                            'legacy_detail_id' => $legacyDetailId,
                            'legacy_header_id' => $legacyHeaderId,
                            'legacy_item_id' => $legacyItemId,
                            'product_id' => $legacyItemId === null ? null : ($productMap[$legacyItemId] ?? null),
                            'link_status' => $lineLinkStatus,
                            'link_note' => $lineLinkNote,
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

        $linkedDetails = (int) DB::table('van_inventory_history_lines')
            ->whereNotNull('document_id')
            ->count();

        $detailsWithResolvableHeader = (int) DB::table('van_inventory_history_lines as line')
            ->whereNotNull('line.legacy_header_id')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('van_inventory_history_documents as document')
                    ->whereColumn('document.legacy_header_id', 'line.legacy_header_id');
            })
            ->count();

        $sourceOrphanDetails = (int) DB::table('van_inventory_history_lines as line')
            ->whereNotNull('line.legacy_header_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('van_inventory_history_documents as document')
                    ->whereColumn('document.legacy_header_id', 'line.legacy_header_id');
            })
            ->count();

        $detailsWithoutHeaderIdentifier = (int) DB::table('van_inventory_history_lines')
            ->whereNull('legacy_header_id')
            ->count();

        $unexpectedUnlinkedDetails = (int) DB::table('van_inventory_history_lines as line')
            ->whereNull('line.document_id')
            ->whereNotNull('line.legacy_header_id')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('van_inventory_history_documents as document')
                    ->whereColumn('document.legacy_header_id', 'line.legacy_header_id');
            })
            ->count();

        $unmappedProducts = (int) DB::table('van_inventory_history_lines')
            ->whereNotNull('legacy_item_id')
            ->whereNull('product_id')
            ->count();

        $documentsWithVan = (int) DB::table('van_inventory_history_documents')
            ->whereNotNull('van_id')
            ->count();

        $documentsWithCarIdentifier = (int) DB::table('van_inventory_history_documents')
            ->whereNotNull('legacy_car_id')
            ->count();

        $sourceCarUnmappedDocuments = (int) DB::table('van_inventory_history_documents')
            ->whereNotNull('legacy_car_id')
            ->whereNull('van_id')
            ->count();

        $documentsWithoutCarIdentifier = (int) DB::table('van_inventory_history_documents')
            ->whereNull('legacy_car_id')
            ->count();

        $unclassifiedDocuments = (int) DB::table('van_inventory_history_documents')
            ->where('link_status', 'unclassified')
            ->count();

        $unclassifiedLines = (int) DB::table('van_inventory_history_lines')
            ->where('link_status', 'unclassified')
            ->count();

        $healthy = $sourceHeaders === $targetHeaders
            && $sourceDetails === $targetDetails
            && $headerMappings === $sourceHeaders
            && $detailMappings === $sourceDetails
            && $linkedDetails === $detailsWithResolvableHeader
            && $unexpectedUnlinkedDetails === 0
            && $unclassifiedDocuments === 0
            && $unclassifiedLines === 0;

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
                'documents_with_car_identifier' => $documentsWithCarIdentifier,
                'documents_linked_to_van' => $documentsWithVan,
                'source_car_unmapped_documents' => $sourceCarUnmappedDocuments,
                'documents_without_car_identifier' => $documentsWithoutCarIdentifier,
                'details_with_resolvable_header' => $detailsWithResolvableHeader,
                'linked_detail_rows' => $linkedDetails,
                'source_orphan_details' => $sourceOrphanDetails,
                'details_without_header_identifier' => $detailsWithoutHeaderIdentifier,
                'unexpected_unlinked_details' => $unexpectedUnlinkedDetails,
                'unmapped_product_lines' => $unmappedProducts,
                'unclassified_documents' => $unclassifiedDocuments,
                'unclassified_lines' => $unclassifiedLines,
            ],
            'authority' => [
                'stock_balance_source' => 'wh_TransHeader + wh_TransDetails',
                'car_transaction_role' => 'historical operational audit only',
                'source_orphans_policy' => 'preserve and classify; never fabricate missing headers',
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
