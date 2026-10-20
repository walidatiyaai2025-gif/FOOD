<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacySupplierBrandMaterializerService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';
    private const BRAND_SCOPE = 'b2b';
    private const BRAND_SCOPE_KEY = 'b2b';
    private const SELECTION_RULE = 'max_net_purchase_value_then_qty_then_latest_then_supplier_id';

    public function materialize(bool $apply): array
    {
        $this->assertTables();

        $supplierMappings = $this->supplierMappings();
        $productMappings = $this->productMappings();
        $relations = $this->supplierProductRelations();

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'source' => [
                    'supplier_mappings' => count($supplierMappings),
                    'product_mappings' => count($productMappings),
                    'supplier_product_relations' => count($relations),
                    'products_with_supplier_history' => count(array_unique(array_column($relations, 'product_id'))),
                ],
                'target' => [
                    'derived_brands' => $this->derivedBrandCount(),
                    'supplier_brand_sources' => (int) DB::table('product_supplier_brand_sources')
                        ->where('source_system', self::SOURCE_SYSTEM)
                        ->count(),
                ],
            ];
        }

        DB::transaction(function () use ($supplierMappings, $productMappings, $relations): void {
            $brandMap = [];

            foreach ($supplierMappings as $sourceSupplierId => $supplierId) {
                $supplier = DB::table('enterprise_suppliers')
                    ->where('id', $supplierId)
                    ->first(['id', 'code', 'name_en', 'name_ar', 'status']);

                if ($supplier === null) {
                    throw new RuntimeException(
                        "Mapped enterprise supplier {$supplierId} is missing for legacy supplier {$sourceSupplierId}."
                    );
                }

                $slug = 'fdx-supplier-'.(int) $sourceSupplierId;
                $fallbackName = trim((string) ($supplier->name_en ?: $supplier->name_ar ?: $supplier->code));
                if ($fallbackName === '') {
                    $fallbackName = 'Legacy supplier '.(int) $sourceSupplierId;
                }

                $brandId = DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', 'wh_Suppliers')
                    ->where('source_key', (string) $sourceSupplierId)
                    ->where('target_table', 'brands')
                    ->value('target_id');

                $brandId = $brandId === null ? null : (int) $brandId;
                if ($brandId === null || ! DB::table('brands')->where('id', $brandId)->exists()) {
                    $brandId = DB::table('brands')
                        ->where('scope_key', self::BRAND_SCOPE_KEY)
                        ->where('slug', $slug)
                        ->value('id');
                    $brandId = $brandId === null ? null : (int) $brandId;
                }

                $payload = [
                    'store_id' => null,
                    'scope' => self::BRAND_SCOPE,
                    'scope_key' => self::BRAND_SCOPE_KEY,
                    'name' => mb_substr($fallbackName, 0, 255),
                    'name_ar' => $supplier->name_ar === null
                        ? null
                        : mb_substr((string) $supplier->name_ar, 0, 255),
                    'name_en' => mb_substr((string) ($supplier->name_en ?: $fallbackName), 0, 255),
                    'slug' => $slug,
                    'is_active' => strtolower((string) $supplier->status) === 'active',
                    'updated_at' => now(),
                ];

                if ($brandId !== null) {
                    DB::table('brands')->where('id', $brandId)->update($payload);
                } else {
                    $brandId = (int) DB::table('brands')->insertGetId([
                        ...$payload,
                        'created_at' => now(),
                    ]);
                }

                $brandMap[(int) $sourceSupplierId] = $brandId;

                DB::table('legacy_import_mappings')->updateOrInsert(
                    [
                        'source_system' => self::SOURCE_SYSTEM,
                        'source_table' => 'wh_Suppliers',
                        'source_key' => (string) $sourceSupplierId,
                        'target_table' => 'brands',
                    ],
                    [
                        'target_id' => $brandId,
                        'source_hash' => hash('sha256', json_encode([
                            'source_supplier_id' => (int) $sourceSupplierId,
                            'supplier_id' => (int) $supplierId,
                            'slug' => $slug,
                        ], JSON_THROW_ON_ERROR)),
                        'metadata' => json_encode([
                            'authority' => 'supplier-derived-brand',
                            'enterprise_supplier_id' => (int) $supplierId,
                            'scope' => self::BRAND_SCOPE,
                            'scope_key' => self::BRAND_SCOPE_KEY,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'last_run_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            DB::table('product_supplier_brand_sources')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->delete();

            $byProduct = [];
            foreach ($relations as $relation) {
                $productId = (int) $relation['product_id'];
                $sourceSupplierId = (int) $relation['source_supplier_id'];

                if (! isset($brandMap[$sourceSupplierId])) {
                    throw new RuntimeException(
                        "Supplier-derived brand missing for legacy supplier {$sourceSupplierId} / product {$productId}."
                    );
                }

                $byProduct[$productId][] = $relation;
            }

            foreach ($productMappings as $sourceItemId => $productId) {
                if (! isset($byProduct[$productId])) {
                    DB::table('products')->where('id', $productId)->update([
                        'brand_id' => null,
                        'updated_at' => now(),
                    ]);

                    continue;
                }

                $candidates = $byProduct[$productId];
                usort($candidates, static function (array $a, array $b): int {
                    $valueCompare = (float) $b['net_purchase_value'] <=> (float) $a['net_purchase_value'];
                    if ($valueCompare !== 0) {
                        return $valueCompare;
                    }

                    $qtyCompare = (float) $b['net_purchase_quantity'] <=> (float) $a['net_purchase_quantity'];
                    if ($qtyCompare !== 0) {
                        return $qtyCompare;
                    }

                    $dateCompare = strcmp(
                        (string) ($b['last_document_date'] ?? ''),
                        (string) ($a['last_document_date'] ?? ''),
                    );
                    if ($dateCompare !== 0) {
                        return $dateCompare;
                    }

                    return (int) $a['source_supplier_id'] <=> (int) $b['source_supplier_id'];
                });

                $primarySupplierId = (int) $candidates[0]['source_supplier_id'];

                foreach ($candidates as $candidate) {
                    $sourceSupplierId = (int) $candidate['source_supplier_id'];
                    DB::table('product_supplier_brand_sources')->insert([
                        'source_system' => self::SOURCE_SYSTEM,
                        'product_id' => $productId,
                        'supplier_id' => $candidate['supplier_id'],
                        'brand_id' => $brandMap[$sourceSupplierId],
                        'source_supplier_id' => $sourceSupplierId,
                        'purchase_document_count' => (int) $candidate['purchase_document_count'],
                        'return_document_count' => (int) $candidate['return_document_count'],
                        'net_purchase_quantity' => round((float) $candidate['net_purchase_quantity'], 3),
                        'net_purchase_value' => round((float) $candidate['net_purchase_value'], 3),
                        'first_document_date' => $candidate['first_document_date'],
                        'last_document_date' => $candidate['last_document_date'],
                        'is_primary' => $sourceSupplierId === $primarySupplierId,
                        'selection_rule' => self::SELECTION_RULE,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('products')->where('id', $productId)->update([
                    'brand_id' => $brandMap[$primarySupplierId],
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('legacy_business_records')) {
                DB::table('legacy_business_records')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', 'wh_Suppliers')
                    ->delete();
            }

            if (Schema::hasTable('legacy_business_table_manifest')) {
                DB::table('legacy_business_table_manifest')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', 'wh_Suppliers')
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
        $supplierMappings = $this->supplierMappings();
        $productMappings = $this->productMappings();
        $relations = $this->supplierProductRelations();

        $brandMappings = (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Suppliers')
            ->where('target_table', 'brands')
            ->count();

        $derivedBrands = $this->derivedBrandCount();
        $sourceRows = (int) DB::table('product_supplier_brand_sources')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->count();

        $productsWithHistory = count(array_unique(array_column($relations, 'product_id')));
        $productsWithPrimary = (int) DB::table('product_supplier_brand_sources')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('is_primary', true)
            ->distinct()
            ->count('product_id');

        $duplicatePrimaryProducts = (int) DB::query()
            ->fromSub(
                DB::table('product_supplier_brand_sources')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('is_primary', true)
                    ->select('product_id')
                    ->groupBy('product_id')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_primary',
            )
            ->count();

        $primaryBrandMismatch = (int) DB::table('product_supplier_brand_sources as source')
            ->join('products', 'products.id', '=', 'source.product_id')
            ->where('source.source_system', self::SOURCE_SYSTEM)
            ->where('source.is_primary', true)
            ->where(function ($query): void {
                $query
                    ->whereNull('products.brand_id')
                    ->orWhereColumn('products.brand_id', '<>', 'source.brand_id');
            })
            ->count();

        $relationsMissingBrand = (int) DB::table('product_supplier_brand_sources as source')
            ->leftJoin('brands', 'brands.id', '=', 'source.brand_id')
            ->where('source.source_system', self::SOURCE_SYSTEM)
            ->whereNull('brands.id')
            ->count();

        $relationsMissingSupplier = (int) DB::table('product_supplier_brand_sources as source')
            ->leftJoin('enterprise_suppliers', 'enterprise_suppliers.id', '=', 'source.supplier_id')
            ->where('source.source_system', self::SOURCE_SYSTEM)
            ->where(function ($query): void {
                $query
                    ->whereNull('source.supplier_id')
                    ->orWhereNull('enterprise_suppliers.id');
            })
            ->count();

        $importedProductIds = array_values($productMappings);
        $productsWithoutSupplierHistory = $importedProductIds === []
            ? 0
            : (int) DB::table('products')
                ->whereIn('id', $importedProductIds)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('product_supplier_brand_sources as source')
                        ->whereColumn('source.product_id', 'products.id')
                        ->where('source.source_system', self::SOURCE_SYSTEM);
                })
                ->count();

        $sourceSupplierRows = (int) DB::table('legacy_source_archive_tables')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Suppliers')
            ->value('source_row_count');

        $healthy = count($supplierMappings) === $sourceSupplierRows
            && $brandMappings === count($supplierMappings)
            && $derivedBrands === count($supplierMappings)
            && $sourceRows === count($relations)
            && $productsWithPrimary === $productsWithHistory
            && $productsWithoutSupplierHistory === 0
            && $duplicatePrimaryProducts === 0
            && $primaryBrandMismatch === 0
            && $relationsMissingBrand === 0
            && $relationsMissingSupplier === 0;

        return [
            'healthy' => $healthy,
            'source' => [
                'legacy_supplier_rows' => $sourceSupplierRows,
                'legacy_supplier_mappings' => count($supplierMappings),
                'legacy_products' => count($productMappings),
                'supplier_product_relations' => count($relations),
                'products_with_supplier_history' => $productsWithHistory,
            ],
            'target' => [
                'supplier_brand_mappings' => $brandMappings,
                'derived_brands' => $derivedBrands,
                'supplier_brand_source_rows' => $sourceRows,
                'products_with_primary_brand' => $productsWithPrimary,
                'products_without_supplier_history' => $productsWithoutSupplierHistory,
                'duplicate_primary_products' => $duplicatePrimaryProducts,
                'primary_brand_mismatches' => $primaryBrandMismatch,
                'relations_missing_brand' => $relationsMissingBrand,
                'relations_missing_supplier' => $relationsMissingSupplier,
            ],
            'rule' => [
                'brand_authority' => 'wh_Suppliers',
                'product_supplier_authority' => 'legacy procurement history from wh_TransHeader.SupplierId + wh_TransDetails.ItemID',
                'primary_selection' => self::SELECTION_RULE,
                'preserve_secondary_suppliers' => true,
                'fabricate_brand_when_supplier_unknown' => false,
            ],
        ];
    }

    /** @return array<int,int> */
    private function supplierMappings(): array
    {
        return DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Suppliers')
            ->where('target_table', 'enterprise_suppliers')
            ->get(['source_key', 'target_id'])
            ->mapWithKeys(static fn ($row): array => [(int) $row->source_key => (int) $row->target_id])
            ->all();
    }

    /** @return array<int,int> */
    private function productMappings(): array
    {
        return DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', 'wh_Items')
            ->where('target_table', 'products')
            ->get(['source_key', 'target_id'])
            ->mapWithKeys(static fn ($row): array => [(int) $row->source_key => (int) $row->target_id])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function supplierProductRelations(): array
    {
        return DB::table('legacy_procurement_document_lines as line')
            ->join('legacy_procurement_documents as document', 'document.id', '=', 'line.document_id')
            ->where('document.source_system', self::SOURCE_SYSTEM)
            ->whereNotNull('line.product_id')
            ->where('document.source_supplier_id', '>', 0)
            ->selectRaw(
                "line.product_id, document.source_supplier_id, MAX(document.supplier_id) as supplier_id, ".
                "COUNT(DISTINCT CASE WHEN document.document_kind = 'purchase' THEN document.id END) as purchase_document_count, ".
                "COUNT(DISTINCT CASE WHEN document.document_kind = 'purchase_return' THEN document.id END) as return_document_count, ".
                "SUM(CASE WHEN document.document_kind = 'purchase_return' THEN -line.base_quantity ELSE line.base_quantity END) as net_purchase_quantity, ".
                "SUM(CASE WHEN document.document_kind = 'purchase_return' THEN -line.line_value ELSE line.line_value END) as net_purchase_value, ".
                "MIN(document.document_date) as first_document_date, ".
                "MAX(document.document_date) as last_document_date"
            )
            ->groupBy('line.product_id', 'document.source_supplier_id')
            ->orderBy('line.product_id')
            ->orderBy('document.source_supplier_id')
            ->get()
            ->map(static fn ($row): array => [
                'product_id' => (int) $row->product_id,
                'source_supplier_id' => (int) $row->source_supplier_id,
                'supplier_id' => $row->supplier_id === null ? null : (int) $row->supplier_id,
                'purchase_document_count' => (int) $row->purchase_document_count,
                'return_document_count' => (int) $row->return_document_count,
                'net_purchase_quantity' => round((float) $row->net_purchase_quantity, 3),
                'net_purchase_value' => round((float) $row->net_purchase_value, 3),
                'first_document_date' => $row->first_document_date,
                'last_document_date' => $row->last_document_date,
            ])
            ->all();
    }

    private function derivedBrandCount(): int
    {
        return (int) DB::table('brands')
            ->where('scope_key', self::BRAND_SCOPE_KEY)
            ->where('slug', 'like', 'fdx-supplier-%')
            ->count();
    }

    private function assertTables(): void
    {
        foreach ([
            'brands',
            'products',
            'enterprise_suppliers',
            'legacy_procurement_documents',
            'legacy_procurement_document_lines',
            'legacy_import_mappings',
            'product_supplier_brand_sources',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}; supplier-derived brands require completed masters and procurement import.");
            }
        }
    }
}
