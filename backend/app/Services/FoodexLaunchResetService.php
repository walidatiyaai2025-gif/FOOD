<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class FoodexLaunchResetService
{
    private const NON_PRODUCTION_CONFIRMATION = 'RESET FOODEX';
    private const PRODUCTION_CONFIRMATION = 'RESET FOODEX PRODUCTION';

    /**
     * Ordered broad reset policy. Security, identities, permissions, settings,
     * migrations, audit history and release history are intentionally absent.
     *
     * @var list<string>
     */
    private const RESET_TABLES = [
        'notification_reads',
        'push_delivery_logs',
        'notification_campaign_runs',
        'notifications',
        'marketing_coupon_redemptions',
        'commercial_quota_reservations',
        'commercial_quota_locks',
        'order_van_execution_events',
        'order_van_execution_states',
        'order_van_assignments',
        'driver_assignments',
        'order_dispatch_states',
        'delivery_proofs',
        'order_status_history',
        'collection_allocations',
        'collection_transactions',
        'custody_ledger_entries',
        'remittances',
        'payments',
        'invoice_items',
        'invoices',
        'order_items',
        'orders',
        'cart_items',
        'carts',
        'customer_favorites',
        'stock_movements',
        'inventories',
        'retail_wholesale_product_mappings',
        'b2b_price_rules',
        'product_commercial_rules',
        'product_availability_windows',
        'product_commercial_policies',
        'product_selling_units',
        'product_images',
        'store_products',
        'storefront_revision_assets',
        'storefront_revisions',
        'storefront_sections',
        'banners',
        'products',
        'categories',
        'catalogs',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{
     *   environment:string,
     *   confirmation_phrase:string,
     *   requires_backup_reference:bool,
     *   tables:array<string,int>,
     *   total_rows:int,
     *   seed_contract_available:bool,
     *   catalog_readiness:array<string,mixed>|null,
     *   preserves:list<string>
     * }
     */
    public function impact(User $actor): array
    {
        $this->authorize($actor);

        $counts = [];
        foreach (self::RESET_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        $seeder = $this->catalogSeeder();
        $readiness = $seeder !== null && method_exists($seeder, 'readiness')
            ? $seeder->readiness()
            : null;

        return [
            'environment' => app()->environment(),
            'confirmation_phrase' => $this->expectedConfirmation(),
            'requires_backup_reference' => app()->environment('production'),
            'tables' => $counts,
            'total_rows' => array_sum($counts),
            'seed_contract_available' => $seeder !== null,
            'catalog_readiness' => is_array($readiness) ? $readiness : null,
            'preserves' => [
                'users and customer identities',
                'roles, permissions and security configuration',
                'platform/settings configuration',
                'audit logs',
                'migration history',
                'system/app version and release history',
                'stores and warehouses',
            ],
        ];
    }

    /**
     * @return array{deleted_rows:int,seeded:bool,catalog_readiness:array<string,mixed>|null}
     */
    public function execute(
        User $actor,
        string $confirmation,
        ?string $backupReference = null,
    ): array {
        $this->authorize($actor);

        if (! hash_equals($this->expectedConfirmation(), trim($confirmation))) {
            throw new RuntimeException('launch_reset_confirmation_mismatch');
        }

        $seeder = $this->catalogSeeder();
        if ($seeder === null) {
            throw new RuntimeException('launch_seed_contract_missing');
        }

        if (app()->environment('production')) {
            if (trim((string) $backupReference) === '') {
                throw new RuntimeException('launch_reset_backup_reference_required');
            }
            if (! method_exists($seeder, 'assertReadyForLaunch')) {
                throw new RuntimeException('launch_seed_readiness_contract_missing');
            }
            $seeder->assertReadyForLaunch();
        }

        $before = $this->impact($actor);
        $this->audit->record(
            'foodex.launch_reset.requested',
            $actor,
            null,
            null,
            [
                'environment' => $before['environment'],
                'rows_planned' => $before['total_rows'],
                'backup_reference' => $backupReference,
            ],
        );

        try {
            $result = DB::transaction(function () use ($actor, $seeder, $before): array {
                $deleted = 0;
                Schema::disableForeignKeyConstraints();

                try {
                    foreach (self::RESET_TABLES as $table) {
                        if (! Schema::hasTable($table)) {
                            continue;
                        }
                        $deleted += DB::table($table)->delete();
                    }

                    $seeder->run();
                } finally {
                    Schema::enableForeignKeyConstraints();
                }

                $readiness = method_exists($seeder, 'readiness') ? $seeder->readiness() : null;

                $this->audit->record(
                    'foodex.launch_reset.completed',
                    $actor,
                    null,
                    ['rows_planned' => $before['total_rows']],
                    [
                        'deleted_rows' => $deleted,
                        'catalog_ready_for_production' => is_array($readiness)
                            ? (bool) ($readiness['ready'] ?? false)
                            : false,
                    ],
                );

                return [
                    'deleted_rows' => $deleted,
                    'seeded' => true,
                    'catalog_readiness' => is_array($readiness) ? $readiness : null,
                ];
            });

            return $result;
        } catch (Throwable $error) {
            $this->audit->record(
                'foodex.launch_reset.failed',
                $actor,
                null,
                null,
                ['failure' => $error::class],
            );

            throw $error;
        }
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasRole('SUPER_ADMIN') || ! $actor->hasPermission('platform.manage')) {
            throw new AuthorizationException('FOODEX launch reset requires SUPER_ADMIN platform management access.');
        }
    }

    private function expectedConfirmation(): string
    {
        return app()->environment('production')
            ? self::PRODUCTION_CONFIRMATION
            : self::NON_PRODUCTION_CONFIRMATION;
    }

    private function catalogSeeder(): ?object
    {
        $class = 'Database\\Seeders\\FoodexLaunchCatalogSeeder';
        if (! class_exists($class)) {
            return null;
        }

        return app($class);
    }
}
