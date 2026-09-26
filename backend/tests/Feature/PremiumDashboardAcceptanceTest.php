<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\B2cDashboardService;
use Database\Seeders\DashboardDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PremiumDashboardAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_premium_dashboard_reconciles_metrics_brand_direction_and_responsive_contract(): void
    {
        $this->seed(DashboardDemoSeeder::class);

        $storeId = (int) DB::table('stores')
            ->where('code', 'FOODEX-DEMO-B2C')
            ->value('id');
        $user = User::query()
            ->where('email', 'admin.demo@foodex.test')
            ->firstOrFail();

        $dashboard = app(B2cDashboardService::class)->build(
            $user,
            [$storeId],
            now('Asia/Kuwait')->toDateString(),
        );

        $this->assertSame(532, $dashboard['kpis']['orders']['value']);
        $this->assertSame(48532.0, $dashboard['kpis']['revenue']['value']);
        $this->assertSame(1892.0, $dashboard['kpis']['products_sold']['value']);
        $this->assertSame(
            [
                'processing' => 149,
                'out_for_delivery' => 223,
                'delivered' => 138,
                'cancelled' => 22,
            ],
            $dashboard['distribution'],
        );
        $this->assertSame(
            [5.0, 8.0, 10.0, 12.0],
            collect($dashboard['low_stock'])
                ->pluck('available')
                ->take(4)
                ->map(fn ($value): float => (float) $value)
                ->all(),
        );

        $this->actingAs($user)
            ->get('/admin/b2c/dashboard')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('--foodex-green:#158A3A', false)
            ->assertSee('--foodex-orange:#EE731C', false)
            ->assertSee('@media(max-width:1180px)', false)
            ->assertSee('@media(max-width:860px)', false)
            ->assertSee('@media(max-width:620px)', false)
            ->assertSee('FOODEX-DEMO-1245');

        $user->forceFill(['locale' => 'en'])->save();

        $this->actingAs($user->fresh())
            ->get('/admin/b2c/dashboard')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('FOODEX', false);
    }
}
