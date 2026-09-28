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

        $this->ensureWholesalePrincipal();
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

    private function ensureWholesalePrincipal(): void
    {
        $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        if ($typeId === null) {
            $typeId = DB::table('store_types')->insertGetId([
                'code' => 'B2B',
                'name' => 'Wholesale',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('stores')->where('code', 'FOODEX-WHOLESALE')->exists()) {
            DB::table('stores')->where('code', 'FOODEX-WHOLESALE')->update([
                'store_type_id' => $typeId,
                'is_active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        $existing = DB::table('stores')
            ->where('store_type_id', $typeId)
            ->where('is_active', true)
            ->where('code', '!=', 'SYSTEM-LEGACY-QUARANTINE')
            ->orderBy('id')
            ->first(['id']);

        if ($existing !== null) {
            DB::table('stores')->where('id', $existing->id)->update([
                'code' => 'FOODEX-WHOLESALE',
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('stores')->insert([
            'store_type_id' => $typeId,
            'code' => 'FOODEX-WHOLESALE',
            'name' => 'FOODEX Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
