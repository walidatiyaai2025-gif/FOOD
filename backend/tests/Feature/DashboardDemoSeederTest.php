<?php

namespace Tests\Feature;

use Database\Seeders\DashboardDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_deterministic_non_production_dashboard_fixture(): void
    {
        $this->seed(DashboardDemoSeeder::class);

        $storeId = (int) DB::table('stores')->where('code', 'FOODEX-DEMO-B2C')->value('id');

        $this->assertGreaterThan(0, $storeId);
        $this->assertSame(1248, DB::table('users')->where('is_active', true)->count());
        $this->assertSame(532, DB::table('orders')->where('store_id', $storeId)->where('order_number', 'like', 'FOODEX-DEMO-%')->whereDate('created_at', now('Asia/Kuwait')->utc()->toDateString())->count());

        $recognizedToday = (float) DB::table('orders')
            ->where('store_id', $storeId)
            ->where('order_number', 'like', 'FOODEX-DEMO-%')
            ->whereDate('created_at', now('Asia/Kuwait')->utc()->toDateString())
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->sum('grand_total');
        $this->assertSame(48532.0, $recognizedToday);

        $soldToday = (float) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)
            ->whereDate('orders.created_at', now('Asia/Kuwait')->utc()->toDateString())
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->sum('order_items.quantity');
        $this->assertSame(1892.0, $soldToday);

        $this->assertSame(
            [5.0, 8.0, 10.0, 12.0],
            DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('warehouses.store_id', $storeId)
                ->orderBy('inventories.quantity')
                ->limit(4)
                ->pluck('inventories.quantity')
                ->map(fn ($value): float => (float) $value)
                ->all(),
        );

        $this->assertDatabaseHas('mobile_app_settings', ['app' => 'customer', 'environment' => 'production']);
        $this->assertDatabaseHas('mobile_app_settings', ['app' => 'driver', 'environment' => 'production']);

        // Second run is a no-op instead of duplicating visual-QA records.
        $this->seed(DashboardDemoSeeder::class);
        $this->assertSame(1, DB::table('stores')->where('code', 'FOODEX-DEMO-B2C')->count());
    }
}
