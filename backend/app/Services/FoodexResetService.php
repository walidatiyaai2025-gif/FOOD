<?php

namespace App\Services;

use App\Domain\Updater\UpdateRuntime;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FoodexResetService
{
    public const CONFIRMATION = 'RESET FOODEX DATA';

    public const PRODUCTION_CONFIRMATION = 'RESET FOODEX PRODUCTION';

    /** Explicit allowlist: never discover additional deletion targets from foreign keys. */
    public const TABLES = [
        'cart_items', 'carts', 'order_van_execution_events', 'order_van_execution_states',
        'order_van_assignments', 'order_dispatch_states', 'driver_assignments', 'delivery_proofs',
        'order_status_history', 'b2b_van_cutover_snapshots', 'b2b_van_cutover_runs',
        'retail_replenishment_items', 'retail_replenishments', 'customer_account_ledger_entries',
        'collection_allocations', 'remittance_allocations', 'custody_ledger_entries',
        'remittances', 'collection_transactions', 'payments', 'invoice_items', 'invoices',
        'commercial_quota_reservations', 'commercial_quota_locks', 'marketing_coupon_redemptions',
        'flash_offer_events', 'flash_reservations', 'order_items', 'orders',
        'notification_reads', 'notification_campaign_popup_views', 'notification_campaign_runs',
        'push_delivery_logs', 'notifications', 'van_visits', 'routing_decision_traces',
        'sms_message_logs', 'driver_current_locations', 'fleet_current_locations',
        'customer_favorites', 'stock_movements', 'inventories',
        'retail_wholesale_product_mappings', 'flash_offer_products', 'flash_offers',
        'product_commercial_rules', 'product_commercial_policies', 'product_availability_windows',
        'product_selling_units', 'b2b_price_rules', 'store_products', 'product_images',
        'products', 'categories', 'brands', 'units', 'banners',
    ];

    public function __construct(
        private readonly LaunchCatalog $catalog,
        private readonly AuditLogger $audit,
        private readonly UpdateRuntime $runtime,
        private readonly ProductionDatasetSnapshot $snapshot,
    ) {}

    public function isProduction(): bool
    {
        return app()->environment('production') || config('app.env') === 'production';
    }

    public function preview(): array
    {
        $counts = [];
        $dataHash = hash_init('sha256');
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
                hash_update($dataHash, $table);
                foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                    hash_update($dataHash, json_encode($row, JSON_THROW_ON_ERROR));
                }
            }
        }

        $catalog = $this->catalog->preflight();
        $blockedReferences = $this->blockedReferences(array_keys($counts));
        $productionReady = ! $this->isProduction() || $this->productionReady();

        return [
            'counts' => $counts,
            'total' => array_sum($counts),
            'catalog_ready' => $catalog['ready'] && is_string($catalog['fingerprint']),
            'catalog_fingerprint' => $catalog['fingerprint'],
            'references_ready' => $blockedReferences === [],
            'production_ready' => $productionReady,
            'ready' => $catalog['ready'] && is_string($catalog['fingerprint']) && $blockedReferences === [] && $productionReady,
            'fingerprint' => hash('sha256', json_encode([$counts, hash_final($dataHash), $catalog, $blockedReferences], JSON_THROW_ON_ERROR)),
        ];
    }

    public function execute(array $preview, User $actor, Request $request): array
    {
        abort_unless($actor->is_active && $actor->hasRole('SUPER_ADMIN'), 403);

        // Shared deployment cache is required for multi-node installs. No cache flush here:
        // it would erase this lock and allow another destructive request concurrently.
        return Cache::lock('foodex:launch-reset', 900)->block(1, function () use ($preview, $actor, $request): array {
            $current = $this->preview();
            if (! $current['ready']) {
                throw ValidationException::withMessages(['reset' => __('foodex_reset.blocked')]);
            }
            if (! hash_equals($preview['fingerprint'], $current['fingerprint'])) {
                throw ValidationException::withMessages(['reset' => __('foodex_reset.stale')]);
            }

            // Production launch mutations are forbidden until the exact legacy dataset has a
            // verified, durable snapshot. Creation is idempotent and happens before first write.
            if ($this->isProduction()) {
                $this->snapshot->create($actor, $request);
                $this->snapshot->assertReady();
            }

            $result = DB::transaction(function () use ($current, $actor, $request): array {
                foreach ($this->deletionOrder(array_keys($current['counts'])) as $table) {
                    DB::table($table)->delete();
                }
                $this->catalog->reseed();
                $this->audit->record('system.launch_reset', $actor, null, $current['counts'], [
                    'catalog_fingerprint' => $current['catalog_fingerprint'],
                    'backup_created' => $this->isProduction(),
                    'operation_id' => $request->session()->get('launch_reset_operation'),
                ], $request);

                // Catalog reads are database-backed; external search/cache rebuild belongs
                // to the canonical seeder. Advance only this namespace after successful commit.
                DB::afterCommit(function (): void {
                    try {
                        Cache::forever('foodex:catalog-generation', (string) Str::uuid());
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });

                return ['removed' => $current['total']];
            });

            if ($this->isProduction()) {
                $this->snapshot->markLaunchActive($actor, $request);
            }

            return $result;
        });
    }

    private function productionReady(): bool
    {
        if (! config('foodex_reset.production_enabled') || ! app()->isDownForMaintenance()) {
            return false;
        }
        $path = (string) config('foodex_reset.preflight_path');
        if (! is_file($path)) {
            return false;
        }
        $evidence = json_decode((string) file_get_contents($path), true);

        return is_array($evidence)
            && ($evidence['ready'] ?? false) === true
            && ($evidence['database'] ?? null) === DB::connection()->getDatabaseName()
            && ($evidence['catalog_fingerprint'] ?? null) === $this->catalog->preflight()['fingerprint']
            && is_int($evidence['checked_at'] ?? null)
            && $evidence['checked_at'] <= now()->timestamp
            && $evidence['checked_at'] >= now()->subMinutes(5)->timestamp;
    }

    /** Refuse unowned, nonempty dependent tables even for cascading/nulling FKs. */
    private function blockedReferences(array $targets): array
    {
        $blocked = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (in_array($table, $targets, true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (in_array($foreignKey['foreign_table'], $targets, true)
                    && DB::table($table)->whereNotNull($foreignKey['columns'][0])->exists()) {
                    $blocked[] = $table;
                    break;
                }
            }
        }

        return $blocked;
    }

    /** Children first; never disable FK checks or truncate (which implicitly commits on MySQL). */
    private function deletionOrder(array $targets): array
    {
        $parents = [];
        foreach ($targets as $table) {
            $parents[$table] = [];
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $parent = $foreignKey['foreign_table'];
                if ($parent !== $table && in_array($parent, $targets, true)) {
                    $parents[$table][] = $parent;
                }
            }
        }
        $order = [];
        while ($targets !== []) {
            $referenced = array_merge([], ...array_values($parents));
            $leaves = array_values(array_diff($targets, $referenced));
            if ($leaves === []) {
                throw ValidationException::withMessages(['reset' => __('foodex_reset.blocked')]);
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
