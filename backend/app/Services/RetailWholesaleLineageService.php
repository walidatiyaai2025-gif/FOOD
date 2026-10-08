<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class RetailWholesaleLineageService
{
    /**
     * @return list<array{
     *   id:int,
     *   retail_store_id:int,
     *   source_wholesale_product_id:int,
     *   source_sku:string,
     *   source_name:string,
     *   retail_product_id:int,
     *   retail_sku:string,
     *   retail_name:string,
     *   quantity_conversion_factor:float,
     *   current_unit_cost:?float,
     *   received_quantity_total:float,
     *   last_received_at:?string,
     *   status:string,
     *   issues:list<string>,
     *   updated_at:mixed
     * }>
     */
    public function mappingsForStore(int $retailStoreId): array
    {
        $this->assertRetailStore($retailStoreId);

        $receipts = DB::table('retail_replenishment_items as receipt_items')
            ->join(
                'retail_replenishments as receipts',
                'receipts.id',
                '=',
                'receipt_items.replenishment_id',
            )
            ->where('receipts.retail_store_id', $retailStoreId)
            ->groupBy('receipt_items.source_product_id', 'receipt_items.retail_product_id')
            ->select([
                'receipt_items.source_product_id',
                'receipt_items.retail_product_id',
            ])
            ->selectRaw('SUM(receipt_items.quantity) as received_quantity_total')
            ->selectRaw('MAX(receipts.received_at) as last_received_at');

        return DB::table('retail_wholesale_product_mappings as mappings')
            ->join('products as source_products', 'source_products.id', '=', 'mappings.source_wholesale_product_id')
            ->join('catalogs as source_catalogs', 'source_catalogs.id', '=', 'source_products.catalog_id')
            ->join('stores as source_stores', 'source_stores.id', '=', 'source_catalogs.store_id')
            ->join('store_types as source_store_types', 'source_store_types.id', '=', 'source_stores.store_type_id')
            ->join('products as retail_products', 'retail_products.id', '=', 'mappings.retail_product_id')
            ->join('catalogs as retail_catalogs', 'retail_catalogs.id', '=', 'retail_products.catalog_id')
            ->leftJoin('store_products as retail_store_products', function ($join): void {
                $join->on('retail_store_products.product_id', '=', 'retail_products.id')
                    ->on('retail_store_products.store_id', '=', 'mappings.retail_store_id');
            })
            ->leftJoinSub($receipts, 'receipt_totals', function ($join): void {
                $join->on(
                    'receipt_totals.source_product_id',
                    '=',
                    'mappings.source_wholesale_product_id',
                )->on(
                    'receipt_totals.retail_product_id',
                    '=',
                    'mappings.retail_product_id',
                );
            })
            ->where('mappings.retail_store_id', $retailStoreId)
            ->orderBy('source_products.name')
            ->get([
                'mappings.id',
                'mappings.retail_store_id',
                'mappings.source_wholesale_product_id',
                'source_products.sku as source_sku',
                'source_products.name as source_name',
                'source_catalogs.channel as source_channel',
                'source_store_types.code as source_store_type',
                'mappings.retail_product_id',
                'retail_products.sku as retail_sku',
                'retail_products.name as retail_name',
                'retail_catalogs.store_id as retail_catalog_store_id',
                'retail_catalogs.channel as retail_channel',
                'mappings.quantity_conversion_factor',
                'retail_store_products.cost_price as current_unit_cost',
                'receipt_totals.received_quantity_total',
                'receipt_totals.last_received_at',
                'mappings.updated_at',
            ])
            ->map(function (object $row) use ($retailStoreId): array {
                $issues = [];
                $factor = (float) $row->quantity_conversion_factor;

                if ($factor <= 0) {
                    $issues[] = 'invalid_conversion_factor';
                }
                if (
                    strtolower((string) $row->source_channel) !== 'b2b'
                    || strtoupper((string) $row->source_store_type) !== 'B2B'
                ) {
                    $issues[] = 'source_not_wholesale';
                }
                if (
                    (int) $row->retail_catalog_store_id !== $retailStoreId
                    || strtolower((string) $row->retail_channel) !== 'b2c'
                ) {
                    $issues[] = 'target_outside_retail_store';
                }

                return [
                    'id' => (int) $row->id,
                    'retail_store_id' => (int) $row->retail_store_id,
                    'source_wholesale_product_id' => (int) $row->source_wholesale_product_id,
                    'source_sku' => (string) $row->source_sku,
                    'source_name' => (string) $row->source_name,
                    'retail_product_id' => (int) $row->retail_product_id,
                    'retail_sku' => (string) $row->retail_sku,
                    'retail_name' => (string) $row->retail_name,
                    'quantity_conversion_factor' => $factor,
                    'current_unit_cost' => $row->current_unit_cost === null
                        ? null
                        : round((float) $row->current_unit_cost, 3),
                    'received_quantity_total' => round(
                        (float) ($row->received_quantity_total ?? 0),
                        3,
                    ),
                    'last_received_at' => $row->last_received_at === null
                        ? null
                        : (string) $row->last_received_at,
                    'status' => $issues === [] ? 'valid' : 'invalid',
                    'issues' => $issues,
                    'updated_at' => $row->updated_at,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *   replenishment_id:int,
     *   source_order_id:int,
     *   source_order_number:string,
     *   source_wholesale_store_id:int,
     *   source_wholesale_store_name:string,
     *   source_product_id:int,
     *   source_sku:string,
     *   source_name:string,
     *   retail_product_id:int,
     *   retail_sku:string,
     *   retail_name:string,
     *   source_quantity:float,
     *   received_quantity:float,
     *   quantity_conversion_factor:float,
     *   source_unit_price:float,
     *   retail_unit_cost:float,
     *   line_total:float,
     *   received_at:string
     * }>
     */
    public function receivedItemsForStore(int $retailStoreId, ?int $retailProductId = null): array
    {
        $this->assertRetailStore($retailStoreId);

        return DB::table('retail_replenishment_items as receipt_items')
            ->join(
                'retail_replenishments as receipts',
                'receipts.id',
                '=',
                'receipt_items.replenishment_id',
            )
            ->join('orders as source_orders', 'source_orders.id', '=', 'receipts.source_order_id')
            ->join(
                'order_items as source_order_items',
                'source_order_items.id',
                '=',
                'receipt_items.source_order_item_id',
            )
            ->join('stores as source_stores', 'source_stores.id', '=', 'receipts.source_wholesale_store_id')
            ->join('products as source_products', 'source_products.id', '=', 'receipt_items.source_product_id')
            ->join('products as retail_products', 'retail_products.id', '=', 'receipt_items.retail_product_id')
            ->join('catalogs as retail_catalogs', 'retail_catalogs.id', '=', 'retail_products.catalog_id')
            ->where('receipts.retail_store_id', $retailStoreId)
            ->where('retail_catalogs.store_id', $retailStoreId)
            ->where('retail_catalogs.channel', 'b2c')
            ->when(
                $retailProductId,
                fn ($query, int $productId) => $query->where(
                    'receipt_items.retail_product_id',
                    $productId,
                ),
            )
            ->orderByDesc('receipts.received_at')
            ->orderByDesc('receipt_items.id')
            ->get([
                'receipt_items.replenishment_id',
                'receipts.source_order_id',
                'source_orders.order_number as source_order_number',
                'receipts.source_wholesale_store_id',
                'source_stores.name as source_wholesale_store_name',
                'receipt_items.source_product_id',
                'source_products.sku as source_sku',
                'source_products.name as source_name',
                'receipt_items.retail_product_id',
                'retail_products.sku as retail_sku',
                'retail_products.name as retail_name',
                'source_order_items.quantity as source_quantity',
                'receipt_items.quantity as received_quantity',
                'receipt_items.quantity_conversion_factor',
                'source_order_items.unit_price as source_unit_price',
                'receipt_items.unit_cost as retail_unit_cost',
                'receipt_items.line_total',
                'receipts.received_at',
            ])
            ->map(static fn (object $row): array => [
                'replenishment_id' => (int) $row->replenishment_id,
                'source_order_id' => (int) $row->source_order_id,
                'source_order_number' => (string) $row->source_order_number,
                'source_wholesale_store_id' => (int) $row->source_wholesale_store_id,
                'source_wholesale_store_name' => (string) $row->source_wholesale_store_name,
                'source_product_id' => (int) $row->source_product_id,
                'source_sku' => (string) $row->source_sku,
                'source_name' => (string) $row->source_name,
                'retail_product_id' => (int) $row->retail_product_id,
                'retail_sku' => (string) $row->retail_sku,
                'retail_name' => (string) $row->retail_name,
                'source_quantity' => round((float) $row->source_quantity, 3),
                'received_quantity' => round((float) $row->received_quantity, 3),
                'quantity_conversion_factor' => round(
                    (float) $row->quantity_conversion_factor,
                    3,
                ),
                'source_unit_price' => round((float) $row->source_unit_price, 3),
                'retail_unit_cost' => round((float) $row->retail_unit_cost, 3),
                'line_total' => round((float) $row->line_total, 3),
                'received_at' => (string) $row->received_at,
            ])
            ->values()
            ->all();
    }

    private function assertRetailStore(int $storeId): void
    {
        $exists = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('store_types.code', 'B2C')
            ->exists();

        abort_unless($exists, 404);
    }
}
