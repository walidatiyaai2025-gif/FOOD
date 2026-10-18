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
        $this->assertSame('sell_out', $dashboard['metric_provenance']['flow']);
        $this->assertSame('b2c', $dashboard['metric_provenance']['channel']);

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
        $this->assertSame('sell_in', $dashboard['metric_provenance']['flow']);
        $this->assertSame('b2b', $dashboard['metric_provenance']['channel']);

        $view = file_get_contents(resource_path('views/admin/b2b-workspace.blade.php'));
        $this->assertIsString($view);
        $this->assertStringContainsString('name="from"', $view);
        $this->assertStringContainsString('name="to"', $view);
        $this->assertStringContainsString('$isAr?\'المبيعات\':\'Sales\'', $view);
        $this->assertStringContainsString('value="{{ $dashboard[\'selected_from\'] }}"', $view);
        $this->assertStringContainsString('value="{{ $dashboard[\'selected_to\'] }}"', $view);
        $this->assertStringContainsString('\'from\'=>$dashboard[\'selected_from\']', $view);
        $this->assertStringContainsString('\'to\'=>$dashboard[\'selected_to\']', $view);
        $this->assertStringContainsString('array_merge([\'module\'=>\'reports\'],$dashboardPeriodQuery)', $view);
        $this->assertStringContainsString('array_merge([\'module\'=>\'orders\'],$dashboardPeriodQuery)', $view);
        $this->assertStringContainsString('Reset period', $view);
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

    public function test_b2b_drillthrough_destinations_consume_the_selected_dashboard_period(): void
    {
        $this->seed(DashboardDemoSeeder::class);

        $user = User::query()->where('email', 'admin.demo@foodex.test')->firstOrFail();
        $user->forceFill(['locale' => 'en'])->save();
        $b2bAdminRoleId = (int) DB::table('roles')->where('code', 'B2B_ADMIN')->value('id');
        DB::table('role_user')->insert([
            'role_id' => $b2bAdminRoleId,
            'user_id' => (int) $user->getKey(),
        ]);

        $to = now('Asia/Kuwait')->startOfDay();
        $from = $to->copy()->subDays(4);
        $period = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];

        $dashboard = $this->actingAs($user)
            ->get(route('admin.b2b.module', array_merge(['module' => 'dashboard'], $period)));

        $dashboard->assertOk();
        $dashboard->assertSee(
            route('admin.b2b.module', array_merge(['module' => 'reports'], $period)),
        );
        $dashboard->assertSee(
            route('admin.b2b.module', array_merge(['module' => 'orders'], $period)),
        );
        $dashboard->assertSee('Reset period');

        $reports = $this->actingAs($user)
            ->get(route('admin.b2b.module', array_merge(['module' => 'reports'], $period)));

        $reports->assertOk()->assertViewHas('moduleData', function (array $moduleData) use ($period): bool {
            return data_get($moduleData, 'filters.from') === $period['from']
                && data_get($moduleData, 'filters.to') === $period['to'];
        });

        $orders = $this->actingAs($user)
            ->get(route('admin.b2b.module', array_merge(['module' => 'orders'], $period)));

        $orders->assertOk()
            ->assertViewIs('admin.order-operations')
            ->assertSee('name="from" value="'.$period['from'].'"', false)
            ->assertSee('name="to" value="'.$period['to'].'"', false);
    }
}
