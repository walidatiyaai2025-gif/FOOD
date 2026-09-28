<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class DemoDataManager
{
    public const STORE_PREFIX = 'FOODEX-DEMO-B2C-';

    public const PRODUCT_PREFIX = 'FOODEX-DEMO-P';

    public const ORDER_PREFIX = 'FOODEX-DEMO-ORDER-';

    public const CUSTOMER_EMAIL_SUFFIX = '@demo.foodex.test';

    public const CATEGORY_PREFIX = 'foodex-demo-';

    public const UNIT_CODE = 'FOODEX-DEMO-PC';

    /** @return array<string, int> */
    public function summary(): array
    {
        $storeIds = DB::table('stores')
            ->where('code', 'like', self::STORE_PREFIX.'%')
            ->pluck('id');

        return [
            'stores' => $storeIds->count(),
            'customers' => DB::table('customers')->where('email', 'like', '%'.self::CUSTOMER_EMAIL_SUFFIX)->count(),
            'products' => DB::table('products')->where('sku', 'like', self::PRODUCT_PREFIX.'%')->count(),
            'orders' => DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX.'%')->count(),
            'promotions' => DB::table('promotions')->where('name', 'like', 'FOODEX Demo Promotion %')->count(),
            'banners' => $storeIds->isEmpty() ? 0 : DB::table('banners')->whereIn('store_id', $storeIds)->count(),
            'drivers' => DB::table('users')->where('email', 'like', 'driver%'.self::CUSTOMER_EMAIL_SUFFIX)->count(),
            'notifications' => DB::table('notifications')->where('type', 'demo_seed')->count(),
        ];
    }

    /** @return array<string, int> */
    public function clear(): array
    {
        $before = $this->summary();

        DB::transaction(function (): void {
            $storeIds = DB::table('stores')->where('code', 'like', self::STORE_PREFIX.'%')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $orderIds = DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX.'%')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $customerIds = DB::table('customers')->where('email', 'like', '%'.self::CUSTOMER_EMAIL_SUFFIX)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $productIds = DB::table('products')->where('sku', 'like', self::PRODUCT_PREFIX.'%')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $userIds = DB::table('users')->where('email', 'like', '%'.self::CUSTOMER_EMAIL_SUFFIX)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $warehouseIds = $storeIds === [] ? [] : DB::table('warehouses')->whereIn('store_id', $storeIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $inventoryIds = $warehouseIds === [] ? [] : DB::table('inventories')->whereIn('warehouse_id', $warehouseIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $assignmentIds = $orderIds === [] ? [] : DB::table('driver_assignments')->whereIn('order_id', $orderIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $promotionIds = $storeIds === [] ? [] : DB::table('promotions')->whereIn('store_id', $storeIds)->where('name', 'like', 'FOODEX Demo Promotion %')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $linkedB2bCustomerIds = $storeIds === [] ? [] : DB::table('retail_wholesale_accounts')->whereIn('retail_store_id', $storeIds)->pluck('b2b_customer_id')->map(fn ($id) => (int) $id)->all();
            $linkedLegacyCustomerIds = $linkedB2bCustomerIds === [] ? [] : DB::table('b2b_customers')->whereIn('id', $linkedB2bCustomerIds)->whereNotNull('legacy_customer_id')->pluck('legacy_customer_id')->map(fn ($id) => (int) $id)->all();
            $replenishmentIds = $storeIds === [] ? [] : DB::table('retail_replenishments')->whereIn('retail_store_id', $storeIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($replenishmentIds !== []) {
                DB::table('retail_replenishment_items')->whereIn('replenishment_id', $replenishmentIds)->delete();
                DB::table('retail_replenishments')->whereIn('id', $replenishmentIds)->delete();
            }
            if ($assignmentIds !== []) {
                DB::table('delivery_proofs')->whereIn('driver_assignment_id', $assignmentIds)->delete();
            }
            if ($orderIds !== []) {
                DB::table('driver_assignments')->whereIn('order_id', $orderIds)->delete();
                DB::table('payments')->whereIn('order_id', $orderIds)->delete();
                DB::table('invoices')->whereIn('order_id', $orderIds)->update(['order_id' => null]);
                DB::table('order_status_history')->whereIn('order_id', $orderIds)->delete();
                DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
                DB::table('orders')->whereIn('id', $orderIds)->delete();
            }
            if ($storeIds !== []) {
                $cartIds = DB::table('carts')->whereIn('store_id', $storeIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($cartIds !== []) {
                    DB::table('cart_items')->whereIn('cart_id', $cartIds)->delete();
                    DB::table('carts')->whereIn('id', $cartIds)->delete();
                }
                DB::table('banners')->whereIn('store_id', $storeIds)->delete();
                DB::table('settings')->whereIn('store_id', $storeIds)->delete();
            }
            if ($promotionIds !== []) {
                DB::table('coupons')->whereIn('promotion_id', $promotionIds)->delete();
                DB::table('promotions')->whereIn('id', $promotionIds)->delete();
            }
            if ($inventoryIds !== []) {
                DB::table('stock_movements')->whereIn('inventory_id', $inventoryIds)->delete();
            }
            if ($warehouseIds !== []) {
                DB::table('inventories')->whereIn('warehouse_id', $warehouseIds)->delete();
                DB::table('warehouses')->whereIn('id', $warehouseIds)->delete();
            }
            if ($storeIds !== []) {
                DB::table('store_products')->whereIn('store_id', $storeIds)->delete();
            }
            if ($productIds !== []) {
                DB::table('product_images')->whereIn('product_id', $productIds)->delete();
                DB::table('store_products')->whereIn('product_id', $productIds)->delete();
                DB::table('products')->whereIn('id', $productIds)->delete();
            }
            if ($customerIds !== []) {
                DB::table('addresses')->whereIn('customer_id', $customerIds)->delete();
                DB::table('customers')->whereIn('id', $customerIds)->delete();
            }
            if ($userIds !== []) {
                DB::table('notifications')->whereIn('user_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
            }
            DB::table('notifications')->where('type', 'demo_seed')->delete();
            DB::table('categories')->where('slug', 'like', self::CATEGORY_PREFIX.'%')->delete();
            if ($storeIds !== []) {
                DB::table('retail_wholesale_accounts')->whereIn('retail_store_id', $storeIds)->delete();
                DB::table('catalogs')->whereIn('store_id', $storeIds)->delete();
                DB::table('stores')->whereIn('id', $storeIds)->delete();
            }
            if ($linkedB2bCustomerIds !== []) {
                DB::table('b2b_accounts')->whereIn('b2b_customer_id', $linkedB2bCustomerIds)->delete();
                DB::table('b2b_customers')->whereIn('id', $linkedB2bCustomerIds)->delete();
            }
            if ($linkedLegacyCustomerIds !== []) {
                DB::table('customers')->whereIn('id', $linkedLegacyCustomerIds)->delete();
            }
            DB::table('units')->where('code', self::UNIT_CODE)->delete();
        });

        return $before;
    }
}
