<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyImportRehearsalResetService
{
    public const CONFIRMATION = 'RESET LEGACY IMPORT SCOPE';

    /**
     * Explicitly approved rehearsal reset scope.
     *
     * Settings, users, roles, permissions, stores, customers and integrations
     * are intentionally absent and must survive unchanged.
     */
    private const TABLES = [
        // Active carts / commerce rows that reference the old catalog.
        'cart_items',
        'carts',

        // Order execution / fulfillment dependents.
        'order_van_execution_events',
        'order_van_execution_states',
        'order_van_assignments',
        'order_dispatch_states',
        'driver_assignments',
        'delivery_proofs',
        'order_status_history',
        'b2b_van_cutover_snapshots',
        'b2b_van_cutover_runs',
        'retail_replenishment_items',
        'retail_replenishments',
        'commercial_quota_reservations',
        'commercial_quota_locks',
        'marketing_coupon_redemptions',
        'flash_offer_events',
        'flash_reservations',

        // Invoice / payment dependents that are safe to rebuild with imported sales history.
        'collection_allocations',

        // Sales transaction scope explicitly approved for reset.
        'payments',
        'invoice_items',
        'invoices',
        'order_items',
        'orders',

        // Catalog-dependent user/runtime rows.
        'customer_favorites',

        // Inventory / stock scope explicitly approved for reset.
        'stock_movements',
        'inventories',

        // Product/catalog dependents.
        'retail_wholesale_product_mappings',
        'flash_offer_products',
        'product_commercial_rules',
        'product_commercial_policies',
        'product_availability_windows',
        'product_attribute_values',
        'product_selling_unit_tier_prices',
        'product_selling_units',
        'b2b_price_rules',
        'store_products',
        'product_images',

        // Catalog roots explicitly approved for reset.
        'products',
        'categories',
        'brands',
    ];

    /**
     * These tables are evidence that the reset stayed inside the approved scope.
     * Counts are compared before and after the transaction.
     */
    private const PROTECTED_TABLES = [
        'settings',
        'users',
        'roles',
        'permissions',
        'stores',
        'catalogs',
        'customers',
        'platform_customers',
        'b2b_customers',
        'b2c_customers',
        'b2b_accounts',
        'addresses',
        'warehouses',
        'vans',
        'enterprise_suppliers',
        'enterprise_organizations',
        'enterprise_companies',
    ];

    public function preview(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        $trackingRows = Schema::hasTable('legacy_import_mappings')
            ? (int) DB::table('legacy_import_mappings')->count()
            : 0;

        $blockedReferences = $this->blockedReferences(array_keys($counts));

        return [
            'counts' => $counts,
            'total' => array_sum($counts),
            'protected_counts' => $this->protectedCounts(),
            'legacy_mapping_rows' => $trackingRows,
            'blocked_references' => $blockedReferences,
            'ready' => $trackingRows === 0 && $blockedReferences === [],
        ];
    }

    public function execute(string $confirmation): array
    {
        if (! hash_equals(self::CONFIRMATION, $confirmation)) {
            throw new RuntimeException(
                'Confirmation mismatch. Expected: '.self::CONFIRMATION
            );
        }

        if (app()->environment('production')) {
            throw new RuntimeException(
                'Legacy import rehearsal reset is disabled in production.'
            );
        }

        $preview = $this->preview();
        if (! $preview['ready']) {
            throw new RuntimeException(
                'Scoped reset blocked: '.
                json_encode([
                    'legacy_mapping_rows' => $preview['legacy_mapping_rows'],
                    'blocked_references' => $preview['blocked_references'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $beforeProtected = $preview['protected_counts'];

        return DB::transaction(function () use ($preview, $beforeProtected): array {
            foreach ($this->deletionOrder(array_keys($preview['counts'])) as $table) {
                DB::table($table)->delete();
            }

            $afterProtected = $this->protectedCounts();
            if ($afterProtected !== $beforeProtected) {
                throw new RuntimeException(
                    'Protected data changed during scoped reset. Transaction rolled back. '.
                    json_encode([
                        'before' => $beforeProtected,
                        'after' => $afterProtected,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }

            return [
                'removed' => $preview['total'],
                'removed_by_table' => $preview['counts'],
                'protected_counts_before' => $beforeProtected,
                'protected_counts_after' => $afterProtected,
            ];
        }, 3);
    }

    private function protectedCounts(): array
    {
        $counts = [];

        foreach (self::PROTECTED_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * Refuse to touch a target while an unapproved, non-empty table references it.
     * We never disable foreign-key checks and never discover extra delete targets.
     */
    private function blockedReferences(array $targets): array
    {
        $blocked = [];

        foreach (Schema::getTableListing(null, false) as $table) {
            if (in_array($table, $targets, true)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $parent = $foreignKey['foreign_table'] ?? null;
                $columns = $foreignKey['columns'] ?? [];

                if (
                    ! is_string($parent)
                    || ! in_array($parent, $targets, true)
                    || $columns === []
                ) {
                    continue;
                }

                $column = (string) $columns[0];
                if (DB::table($table)->whereNotNull($column)->exists()) {
                    $blocked[$table][] = [
                        'column' => $column,
                        'references' => $parent,
                    ];
                }
            }
        }

        ksort($blocked);

        return $blocked;
    }

    /** Delete children before parents without disabling FK checks or using TRUNCATE. */
    private function deletionOrder(array $targets): array
    {
        $parents = [];

        foreach ($targets as $table) {
            $parents[$table] = [];

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $parent = $foreignKey['foreign_table'] ?? null;

                if (
                    is_string($parent)
                    && $parent !== $table
                    && in_array($parent, $targets, true)
                ) {
                    $parents[$table][] = $parent;
                }
            }
        }

        $order = [];

        while ($targets !== []) {
            $referenced = $parents === []
                ? []
                : array_merge([], ...array_values($parents));
            $leaves = array_values(array_diff($targets, $referenced));

            if ($leaves === []) {
                throw new RuntimeException(
                    'Scoped reset dependency cycle detected; refusing to disable FK checks.'
                );
            }

            foreach ($leaves as $leaf) {
                $order[] = $leaf;
                unset($parents[$leaf]);
            }

            $targets = array_values(array_diff($targets, $leaves));
        }

        return $order;
    }
}
