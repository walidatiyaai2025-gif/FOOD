<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\B2bDashboardService;
use App\Services\B2cDashboardService;
use Database\Seeders\DashboardDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2c_dashboard_uses_explicit_from_to_range(): void
    {
        $this->seed(DashboardDemoSeeder::class);

        $storeId = (int) DB::table('stores')->where('code', 'FOODEX-DEMO-B2C')->value('id');
        $user = User::query()->where('email', 'admin.demo@foodex.test')->firstOrFail();

        $to = now('Asia/Kuwait')->startOfDay();
        $from = $to->copy()->subDays(2);

        $dashboard = app(B2cDashboardService::class)->build(
            $user,
            [$storeId],
            $from->toDateString(),
            $to->toDateString(),
        );

        $this->assertSame($from->toDateString(), $dashboard['selected_from']);
        $this->assertSame($to->toDateString(), $dashboard['selected_to']);
        $this->assertSame(3, $dashboard['range_days']);
        $this->assertCount(3, $dashboard['series']);

        $this->actingAs($user)
            ->get(route('admin.b2c.dashboard', [
                'store_id' => $storeId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('name="from"', false)
            ->assertSee('name="to"', false)
            ->assertSee('foodex-filter-action', false);
    }

    public function test_b2b_dashboard_series_uses_selected_range(): void
    {
        $this->seed(DashboardDemoSeeder::class);

        $user = User::query()->where('email', 'admin.demo@foodex.test')->firstOrFail();
        $storeId = (int) DB::table('stores')->where('code', 'FOODEX-DEMO-B2C')->value('id');

        $to = now('Asia/Kuwait')->startOfDay();
        $from = $to->copy()->subDays(4);

        $dashboard = app(B2bDashboardService::class)->build(
            $user,
            [$storeId],
            $from->toDateString(),
            $to->toDateString(),
        );

        $this->assertSame($from->toDateString(), $dashboard['selected_from']);
        $this->assertSame($to->toDateString(), $dashboard['selected_to']);
        $this->assertSame(5, $dashboard['range_days']);
        $this->assertCount(5, $dashboard['series']);

        $view = file_get_contents(resource_path('views/admin/b2b-workspace.blade.php'));
        $this->assertIsString($view);
        $this->assertStringContainsString('name="from"', $view);
        $this->assertStringContainsString('name="to"', $view);
        $this->assertStringContainsString("\$isAr?'المبيعات':'Sales'", $view);
        $this->assertStringContainsString("value="{{ request('from') }}"", $view);
        $this->assertStringContainsString("value="{{ request('to') }}"", $view);
        $this->assertStringNotContainsString('المبيعات اليومية', $view);
        $this->assertStringNotContainsString('Daily sales', $view);
        $this->assertStringNotContainsString('آخر 7 أيام', $view);
        $this->assertStringNotContainsString('Last 7 days', $view);
    }


    public function test_b2b_dashboard_accepts_ranges_longer_than_31_days_and_from_only_is_open_ended(): void
    {
        $this->seed(DashboardDemoSeeder::class);

        $user = User::query()->where('email', 'admin.demo@foodex.test')->firstOrFail();
        $storeId = (int) DB::table('stores')->where('code', 'FOODEX-DEMO-B2C')->value('id');
        $today = now('Asia/Kuwait')->startOfDay();
        $from = $today->copy()->subDays(35);

        $dashboard = app(B2bDashboardService::class)->build(
            $user,
            [$storeId],
            $from->toDateString(),
            null,
        );

        $this->assertSame($from->toDateString(), $dashboard['selected_from']);
        $this->assertSame($today->toDateString(), $dashboard['selected_to']);
        $this->assertSame(36, $dashboard['range_days']);
        $this->assertCount(36, $dashboard['series']);
    }

    public function test_dashboard_rejects_ranges_longer_than_31_days(): void
    {
        $this->seed(DashboardDemoSeeder::class);

        $storeId = (int) DB::table('stores')->where('code', 'FOODEX-DEMO-B2C')->value('id');
        $user = User::query()->where('email', 'admin.demo@foodex.test')->firstOrFail();

        $to = now('Asia/Kuwait')->startOfDay();
        $from = $to->copy()->subDays(31);

        $this->actingAs($user)
            ->from(route('admin.b2c.dashboard', ['store_id' => $storeId]))
            ->get(route('admin.b2c.dashboard', [
                'store_id' => $storeId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('to');
    }
}
