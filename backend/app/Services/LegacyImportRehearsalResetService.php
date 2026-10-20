<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyImportRehearsalResetService
{
    public const CONFIRMATION = 'RESET LEGACY IMPORT SCOPE';

    public const VAN_CONFIRMATION = 'RESET LEGACY VAN SCOPE';

    private const SOURCE_SYSTEM = 'foodex_sqlserver';

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
        'van_visits',
        'van_assignments',
        'order_customer_service_assignments',
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
        'remittance_allocations',
        'custody_ledger_entries',
        'remittances',
        'collection_allocations',
        'collection_transactions',

        // Immutable outputs owned by the legacy Foodex importer.
        'legacy_finance_transactions',
        'legacy_bank_transactions',
        'legacy_operational_document_lines',
        'legacy_operational_documents',
        'legacy_procurement_document_lines',
        'legacy_procurement_documents',

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

        // Fleet registry explicitly approved for reset.
        'vans',

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
            ? (int) DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->count()
            : 0;

        $foreignTrackingRows = Schema::hasTable('legacy_import_mappings')
            ? (int) DB::table('legacy_import_mappings')
                ->where('source_system', '<>', self::SOURCE_SYSTEM)
                ->count()
            : 0;

        $referenceRows = Schema::hasTable('legacy_import_reference_records')
            ? (int) DB::table('legacy_import_reference_records')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->count()
            : 0;

        $quarantineRows = Schema::hasTable('legacy_import_quarantine')
            ? (int) DB::table('legacy_import_quarantine')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->count()
            : 0;

        $runRows = Schema::hasTable('legacy_import_runs')
            ? (int) DB::table('legacy_import_runs')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->count()
            : 0;

        $scopedLedgerRows = Schema::hasTable('customer_account_ledger_entries')
            ? (int) DB::table('customer_account_ledger_entries')
                ->where(function ($query): void {
                    $query
                        ->whereIn('source', ['legacy_foodex_import', 'legacy_foodex_finance'])
                        ->orWhereNotNull('order_id')
                        ->orWhereNotNull('invoice_id');
                })
                ->count()
            : 0;

        $driverLocationLinks = Schema::hasTable('driver_current_locations')
            && Schema::hasColumn('driver_current_locations', 'active_assignment_id')
            ? (int) DB::table('driver_current_locations')
                ->whereNotNull('active_assignment_id')
                ->count()
            : 0;

        $vanFleetLocationRows = Schema::hasTable('fleet_current_locations')
            ? (int) DB::table('fleet_current_locations')->where('actor_type', 'van')->count()
            : 0;

        $blockedReferences = $this->blockedReferences(array_keys($counts));

        return [
            'counts' => $counts,
            'scoped_customer_ledger_rows' => $scopedLedgerRows,
            'driver_location_assignment_links_to_clear' => $driverLocationLinks,
            'van_fleet_location_rows' => $vanFleetLocationRows,
            'total' => array_sum($counts) + $scopedLedgerRows + $vanFleetLocationRows
                + $trackingRows + $referenceRows + $quarantineRows + $runRows,
            'protected_counts' => $this->protectedCounts(),
            'legacy_mapping_rows' => $trackingRows,
            'foreign_legacy_mapping_rows_preserved' => $foreignTrackingRows,
            'legacy_reference_rows' => $referenceRows,
            'legacy_quarantine_rows' => $quarantineRows,
            'legacy_run_rows' => $runRows,
            'blocked_references' => $blockedReferences,
            'ready' => $blockedReferences === [],
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
            if (
                Schema::hasTable('fleet_current_locations')
                && (int) ($preview['van_fleet_location_rows'] ?? 0) > 0
            ) {
                DB::table('fleet_current_locations')
                    ->where('actor_type', 'van')
                    ->delete();
            }

            if (
                Schema::hasTable('driver_current_locations')
                && Schema::hasColumn('driver_current_locations', 'active_assignment_id')
                && (int) ($preview['driver_location_assignment_links_to_clear'] ?? 0) > 0
            ) {
                DB::table('driver_current_locations')
                    ->whereNotNull('active_assignment_id')
                    ->update([
                        'active_assignment_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            if (
                Schema::hasTable('customer_account_ledger_entries')
                && (int) ($preview['scoped_customer_ledger_rows'] ?? 0) > 0
            ) {
                DB::table('customer_account_ledger_entries')
                    ->where(function ($query): void {
                        $query
                            ->whereIn('source', ['legacy_foodex_import', 'legacy_foodex_finance'])
                            ->orWhereNotNull('order_id')
                            ->orWhereNotNull('invoice_id');
                    })
                    ->delete();
            }

            foreach ($this->deletionOrder(array_keys($preview['counts'])) as $table) {
                DB::table($table)->delete();
            }

            // Remove only Foodex-import provenance so a fresh canonical import can rebuild it.
            // Full source archive tables are intentionally untouched.
            if (Schema::hasTable('legacy_import_mappings')) {
                DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->delete();
            }

            if (Schema::hasTable('legacy_import_reference_records')) {
                DB::table('legacy_import_reference_records')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->delete();
            }

            if (Schema::hasTable('legacy_import_quarantine')) {
                DB::table('legacy_import_quarantine')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->delete();
            }

            if (Schema::hasTable('legacy_import_runs')) {
                DB::table('legacy_import_runs')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->delete();
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
                'removed_scoped_customer_ledger_rows' => $preview['scoped_customer_ledger_rows'] ?? 0,
                'removed_foodex_mapping_rows' => $preview['legacy_mapping_rows'] ?? 0,
                'removed_foodex_reference_rows' => $preview['legacy_reference_rows'] ?? 0,
                'removed_foodex_quarantine_rows' => $preview['legacy_quarantine_rows'] ?? 0,
                'removed_foodex_run_rows' => $preview['legacy_run_rows'] ?? 0,
                'preserved_foreign_mapping_rows' => $preview['foreign_legacy_mapping_rows_preserved'] ?? 0,
                'protected_counts_before' => $beforeProtected,
                'protected_counts_after' => $afterProtected,
            ];
        }, 3);
    }

    public function previewVanScope(): array
    {
        $tables = [
            'order_van_execution_events',
            'order_van_execution_states',
            'order_van_assignments',
            'driver_assignments',
            'delivery_proofs',
            'van_visits',
            'van_assignments',
            'vans',
        ];

        $counts = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        $driverLocationLinks = Schema::hasTable('driver_current_locations')
            && Schema::hasColumn('driver_current_locations', 'active_assignment_id')
            ? (int) DB::table('driver_current_locations')->whereNotNull('active_assignment_id')->count()
            : 0;

        $vanIds = Schema::hasTable('vans') ? DB::table('vans')->pluck('id') : collect();
        $assignmentIds = Schema::hasTable('van_assignments') ? DB::table('van_assignments')->pluck('id') : collect();

        $fleetRows = 0;
        if (Schema::hasTable('fleet_current_locations')) {
            $fleetQuery = DB::table('fleet_current_locations')->where('actor_type', 'van');

            if ($vanIds->isNotEmpty()) {
                $fleetQuery->orWhereIn('vehicle_id', $vanIds->all());
            }

            if ($assignmentIds->isNotEmpty()) {
                $fleetQuery->orWhereIn('assignment_id', $assignmentIds->all());
            }

            $fleetRows = (int) $fleetQuery->count();
        }

        $blocked = $this->blockedReferences(array_keys($counts));

        return [
            'counts' => $counts,
            'driver_location_assignment_links_to_clear' => $driverLocationLinks,
            'fleet_location_rows' => $fleetRows,
            'blocked_references' => $blocked,
            'protected_counts' => $this->protectedCounts(),
            'ready' => $blocked === [],
        ];
    }

    public function executeVanScope(string $confirmation): array
    {
        if (! hash_equals(self::VAN_CONFIRMATION, $confirmation)) {
            throw new RuntimeException('Confirmation mismatch. Expected: '.self::VAN_CONFIRMATION);
        }

        if (app()->environment('production')) {
            throw new RuntimeException('Legacy van rehearsal reset is disabled in production.');
        }

        $preview = $this->previewVanScope();
        if (! $preview['ready']) {
            throw new RuntimeException(
                'Van scoped reset blocked: '.
                json_encode($preview['blocked_references'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $beforeProtected = $preview['protected_counts'];

        return DB::transaction(function () use ($preview, $beforeProtected): array {
            $vanIds = Schema::hasTable('vans') ? DB::table('vans')->pluck('id') : collect();
            $assignmentIds = Schema::hasTable('van_assignments') ? DB::table('van_assignments')->pluck('id') : collect();

            if (
                Schema::hasTable('driver_current_locations')
                && Schema::hasColumn('driver_current_locations', 'active_assignment_id')
            ) {
                DB::table('driver_current_locations')
                    ->whereNotNull('active_assignment_id')
                    ->update([
                        'active_assignment_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            if (Schema::hasTable('fleet_current_locations')) {
                DB::table('fleet_current_locations')
                    ->where(function ($query) use ($vanIds, $assignmentIds): void {
                        $query->where('actor_type', 'van');

                        if ($vanIds->isNotEmpty()) {
                            $query->orWhereIn('vehicle_id', $vanIds->all());
                        }

                        if ($assignmentIds->isNotEmpty()) {
                            $query->orWhereIn('assignment_id', $assignmentIds->all());
                        }
                    })
                    ->delete();
            }

            foreach ($this->deletionOrder(array_keys($preview['counts'])) as $table) {
                DB::table($table)->delete();
            }

            $afterProtected = $this->protectedCounts();
            if ($afterProtected !== $beforeProtected) {
                throw new RuntimeException('Protected data changed during van reset. Transaction rolled back.');
            }

            return [
                'removed_by_table' => $preview['counts'],
                'fleet_location_rows_removed' => $preview['fleet_location_rows'],
                'driver_location_assignment_links_cleared' => $preview['driver_location_assignment_links_to_clear'],
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

            // Sales-linked ledger rows are deleted selectively before orders/invoices.
            if ($table === 'customer_account_ledger_entries') {
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

                // Keep live driver location rows; only clear their nullable assignment pointer.
                if (
                    $table === 'driver_current_locations'
                    && $column === 'active_assignment_id'
                    && $parent === 'driver_assignments'
                ) {
                    continue;
                }

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
