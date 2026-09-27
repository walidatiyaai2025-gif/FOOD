<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operational_tenant_migration_issues', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('reason', 120);
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'reason'], 'operational_tenant_issue_unique');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('inventory_id')->constrained()->nullOnDelete();
            $table->index(['store_id', 'created_at'], 'stock_movements_store_created_index');
        });

        Schema::table('order_status_history', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->index(['store_id', 'created_at'], 'order_history_store_created_index');
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['store_id', 'driver_type', 'is_active'], 'drivers_store_type_active_index');
        });

        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->index(['store_id', 'status'], 'driver_assignments_store_status_index');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['store_id', 'status', 'published_at'], 'notifications_store_status_published_index');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['store_id', 'created_at'], 'audit_logs_store_created_index');
        });

        $this->backfillStoreOwnership();
        $this->validateOperationalOwnership();
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_store_created_index');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_store_status_published_index');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->dropIndex('driver_assignments_store_status_index');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropIndex('drivers_store_type_active_index');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('order_status_history', function (Blueprint $table): void {
            $table->dropIndex('order_history_store_created_index');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_store_created_index');
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::dropIfExists('operational_tenant_migration_issues');
    }

    private function backfillStoreOwnership(): void
    {
        DB::table('stock_movements')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $storeId = DB::table('inventories')
                    ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                    ->where('inventories.id', $row->inventory_id)
                    ->value('warehouses.store_id');

                if ($storeId !== null) {
                    DB::table('stock_movements')->where('id', $row->id)->update(['store_id' => $storeId]);
                }
            }
        });

        DB::table('order_status_history')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $storeId = DB::table('orders')->where('id', $row->order_id)->value('store_id');
                if ($storeId !== null) {
                    DB::table('order_status_history')->where('id', $row->id)->update(['store_id' => $storeId]);
                }
            }
        });

        DB::table('driver_assignments')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $storeId = DB::table('orders')->where('id', $row->order_id)->value('store_id');
                if ($storeId !== null) {
                    DB::table('driver_assignments')->where('id', $row->id)->update(['store_id' => $storeId]);
                }
            }
        });

        DB::table('drivers')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $storeIds = DB::table('driver_assignments')
                    ->where('driver_id', $row->id)
                    ->whereNotNull('store_id')
                    ->distinct()
                    ->pluck('store_id');

                if ($storeIds->count() === 1) {
                    DB::table('drivers')->where('id', $row->id)->update(['store_id' => $storeIds->first()]);
                } elseif ($storeIds->count() > 1) {
                    $this->recordIssue('driver', (int) $row->id, null, 'driver_has_multiple_store_histories', [
                        'store_ids' => $storeIds->map(static fn ($id): int => (int) $id)->all(),
                    ]);
                }
            }
        });

        DB::table('notifications')->whereNotNull('data')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $data = is_string($row->data) ? json_decode($row->data, true) : null;
                $storeId = is_array($data) ? ($data['store_id'] ?? null) : null;
                if (is_numeric($storeId) && DB::table('stores')->where('id', (int) $storeId)->exists()) {
                    DB::table('notifications')->where('id', $row->id)->update(['store_id' => (int) $storeId]);
                }
            }
        });
    }

    private function validateOperationalOwnership(): void
    {
        DB::table('warehouses')->orderBy('id')->each(function (object $warehouse): void {
            if ($warehouse->store_id === null) {
                $this->recordIssue('warehouse', (int) $warehouse->id, null, 'warehouse_store_unresolved');

                return;
            }

            DB::table('inventories')
                ->where('warehouse_id', $warehouse->id)
                ->orderBy('id')
                ->each(function (object $inventory) use ($warehouse): void {
                    $productStoreId = DB::table('products')
                        ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                        ->where('products.id', $inventory->product_id)
                        ->value('catalogs.store_id');

                    if ($productStoreId === null || (int) $productStoreId !== (int) $warehouse->store_id) {
                        $this->recordIssue(
                            'inventory',
                            (int) $inventory->id,
                            (int) $warehouse->store_id,
                            'inventory_product_store_mismatch',
                            ['product_id' => (int) $inventory->product_id, 'product_store_id' => $productStoreId],
                        );
                    }
                });
        });

        DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->select(['orders.id', 'orders.store_id', 'orders.channel', 'store_types.code as store_channel'])
            ->orderBy('orders.id')
            ->each(function (object $order): void {
                if (strtolower((string) $order->channel) !== strtolower((string) $order->store_channel)) {
                    $this->recordIssue(
                        'order',
                        (int) $order->id,
                        (int) $order->store_id,
                        'order_store_channel_mismatch',
                        ['order_channel' => $order->channel, 'store_channel' => $order->store_channel],
                    );
                }
            });
    }

    private function recordIssue(
        string $sourceType,
        ?int $sourceId,
        ?int $storeId,
        string $reason,
        array $details = [],
    ): void {
        DB::table('operational_tenant_migration_issues')->updateOrInsert(
            ['source_type' => $sourceType, 'source_id' => $sourceId, 'reason' => $reason],
            [
                'store_id' => $storeId,
                'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};
