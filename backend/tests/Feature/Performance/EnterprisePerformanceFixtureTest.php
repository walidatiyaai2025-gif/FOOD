<?php

namespace Tests\Feature\Performance;

use Database\Seeders\EnterprisePerformanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnterprisePerformanceFixtureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('FOODEX_PERF_PROFILE');
        unset($_ENV['FOODEX_PERF_PROFILE'], $_SERVER['FOODEX_PERF_PROFILE']);

        File::delete(storage_path('app/private/enterprise-performance/fixture-manifest.json'));

        parent::tearDown();
    }

    public function test_heavy_profile_meets_enterprise_reference_scale_contract(): void
    {
        $heavy = EnterprisePerformanceSeeder::profiles()['heavy'];

        $this->assertGreaterThanOrEqual(100000, $heavy['orders']);
        $this->assertGreaterThanOrEqual(500000, $heavy['order_items']);
        $this->assertGreaterThanOrEqual(250000, $heavy['stock_movements']);
        $this->assertGreaterThanOrEqual(100000, $heavy['finance_records']);
        $this->assertGreaterThanOrEqual(250000, $heavy['audit_events_messages']);
        $this->assertGreaterThanOrEqual(3, $heavy['tenants']);
        $this->assertGreaterThan(1, $heavy['stores']);
        $this->assertGreaterThan(1, $heavy['warehouses']);
    }

    public function test_smoke_profile_seeds_exact_chunked_counts_and_manifest(): void
    {
        putenv('FOODEX_PERF_PROFILE=smoke');
        $_ENV['FOODEX_PERF_PROFILE'] = 'smoke';
        $_SERVER['FOODEX_PERF_PROFILE'] = 'smoke';

        $this->seed(EnterprisePerformanceSeeder::class);

        $this->assertSame(200, DB::table('orders')->where('order_number', 'like', 'PERF-SMOKE-%')->count());
        $this->assertSame(
            1000,
            DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.order_number', 'like', 'PERF-SMOKE-%')
                ->count()
        );
        $this->assertSame(500, DB::table('stock_movements')->where('reason', 'PERF-smoke')->count());
        $this->assertSame(
            200,
            DB::table('invoices')->where('invoice_number', 'like', 'PERF-SMOKE-%')->count()
                + DB::table('payments')->where('provider_reference', 'like', 'PERF-SMOKE-%')->count()
        );
        $this->assertSame(500, DB::table('audit_logs')->where('event', 'perf.fixture.smoke')->count());
        $this->assertSame(
            0,
            DB::table('orders')
                ->where('order_number', 'like', 'PERF-SMOKE-%')
                ->whereNull('b2c_customer_id')
                ->count()
        );

        $manifestPath = storage_path('app/private/enterprise-performance/fixture-manifest.json');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('logical_store_groups_pre_wave0', $manifest['tenant_scope']['mode']);
        $this->assertSame(3, $manifest['actual']['tenants']);
        $this->assertSame(3, $manifest['actual']['stores']);
        $this->assertSame(6, $manifest['actual']['warehouses']);
        $this->assertSame(200, $manifest['actual']['orders']);
        $this->assertSame(1000, $manifest['actual']['order_items']);
    }

    public function test_seeder_is_repeatable_for_the_same_profile(): void
    {
        putenv('FOODEX_PERF_PROFILE=smoke');

        $this->seed(EnterprisePerformanceSeeder::class);
        $firstOrderCount = DB::table('orders')->where('order_number', 'like', 'PERF-SMOKE-%')->count();

        $this->seed(EnterprisePerformanceSeeder::class);
        $secondOrderCount = DB::table('orders')->where('order_number', 'like', 'PERF-SMOKE-%')->count();

        $this->assertSame(200, $firstOrderCount);
        $this->assertSame($firstOrderCount, $secondOrderCount);
    }
}
