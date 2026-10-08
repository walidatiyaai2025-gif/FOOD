<?php

namespace Tests\Feature;

use App\Domain\Pricing\B2bPriceResolver;
use App\Services\RetailMerchantDashboardService;
use App\Services\RetailReorderIntelligenceService;
use App\Services\SuggestedWholesalePurchasePlanService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MerchantIntelligenceIntegratedGate1122Test extends TestCase
{
    public function test_w8b_function_screen_matrix_has_no_blocking_status_rows(): void
    {
        $matrix = file_get_contents(base_path('../docs/execution/FOODEX_FUNCTION_SCREEN_COVERAGE_MATRIX.md'));

        $this->assertIsString($matrix);
        $this->assertStringContainsString('Status: **PASS', $matrix);
        $this->assertStringContainsString('#1129 is therefore **PASS**', $matrix);
        $this->assertDoesNotMatchRegularExpression(
            '/\\|\\s*(?:PARTIAL|FAIL|UNKNOWN|UNOWNED)\\s*\\|/',
            $matrix,
        );
    }

    public function test_integrated_merchant_runtime_keeps_canonical_routes_and_services_registered(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('admin.b2c.dashboard'));
        $this->assertNotNull(Route::getRoutes()->getByName('admin.b2c.merchant-intelligence.cart'));

        $this->assertTrue(class_exists(RetailMerchantDashboardService::class));
        $this->assertTrue(class_exists(RetailReorderIntelligenceService::class));
        $this->assertTrue(class_exists(SuggestedWholesalePurchasePlanService::class));
        $this->assertTrue(class_exists(B2bPriceResolver::class));
    }

    public function test_cross_app_route_authority_still_covers_required_operational_surfaces(): void
    {
        $raw = file_get_contents(base_path('../docs/execution/UI_ROUTE_AUTHORITY.json'));
        $this->assertIsString($raw);
        $authority = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $customerFunctions = collect(data_get($authority, 'surfaces.customer.functions', []))
            ->pluck('id')
            ->all();
        foreach ([
            'customer.wholesale.orders',
            'customer.wholesale.checkout',
            'customer.wholesale.account',
        ] as $function) {
            $this->assertContains($function, $customerFunctions);
        }

        $driverFunctions = collect(data_get($authority, 'surfaces.driver.functions', []))
            ->pluck('id')
            ->all();
        $this->assertContains('driver.b2c.deliveries', $driverFunctions);
        $this->assertContains('driver.b2b.deliveries', $driverFunctions);

        $vanScreens = data_get($authority, 'surfaces.van.production_screens', []);
        foreach (['dashboard', 'routes', 'orders', 'wallet', 'remittance'] as $screen) {
            $this->assertContains($screen, $vanScreens);
        }
    }

    public function test_performance_contract_batches_product_dependent_dashboard_reads(): void
    {
        $reorder = file_get_contents(app_path('Services/RetailReorderIntelligenceService.php'));
        $pricing = file_get_contents(app_path('Domain/Pricing/B2bPriceResolver.php'));
        $plan = file_get_contents(app_path('Services/SuggestedWholesalePurchasePlanService.php'));

        $this->assertIsString($reorder);
        $this->assertIsString($pricing);
        $this->assertIsString($plan);

        $this->assertStringContainsString('MAX_LEAD_TIME_SAMPLES = 30', $reorder);
        $this->assertStringContainsString('resolveMany($customer, $targets->all())', $reorder);
        $this->assertStringNotContainsString('private function retailSellingPrice(', $reorder);
        $this->assertStringNotContainsString('private function wholesaleAvailable(', $reorder);
        $this->assertStringContainsString('public function resolveMany(', $pricing);
        $this->assertStringContainsString('$this->pricing->resolveMany($customer, $targets)', $plan);
        $this->assertStringContainsString('liveAvailableMany($storeId, $productIds)', $plan);
    }
}
