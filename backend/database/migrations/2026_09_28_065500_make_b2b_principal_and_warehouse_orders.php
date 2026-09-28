<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('stores') && ! Schema::hasColumn('stores', 'logo_path')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->string('logo_path', 1024)->nullable()->after('name');
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'warehouse_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('warehouse_id')->nullable()->after('store_id')->constrained('warehouses')->nullOnDelete();
            });
        }

        // The Wholesale principal is created lazily on first B2B use so a fresh
        // install remains free of business/demo records until the operator enters Wholesale.
        $this->backfillUnambiguousWholesaleOrderWarehouses();
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'warehouse_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('warehouse_id');
            });
        }

        if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'logo_path')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->dropColumn('logo_path');
            });
        }
    }

    private function backfillUnambiguousWholesaleOrderWarehouses(): void
    {
        $orders = DB::table('orders')
            ->where('channel', 'b2b')
            ->whereNull('warehouse_id')
            ->get(['id', 'store_id']);

        foreach ($orders as $order) {
            $warehouseIds = DB::table('warehouses')
                ->where('store_id', $order->store_id)
                ->where('is_active', true)
                ->pluck('id');

            if ($warehouseIds->count() === 1) {
                DB::table('orders')->where('id', $order->id)->update([
                    'warehouse_id' => $warehouseIds->first(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
