<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\RestaurantBillingService;
use App\Domain\RestaurantSaaS\RestaurantControlPlaneService;
use App\Domain\RestaurantSaaS\RestaurantGrowthScaleService;
use App\Domain\RestaurantSaaS\RestaurantIntegrationService;
use App\Domain\RestaurantSaaS\RestaurantOnboardingService;
use App\Domain\RestaurantSaaS\RestaurantOperationsOverviewService;
use App\Domain\RestaurantSaaS\RestaurantOperatorService;
use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\RestaurantGuestService;
use App\Domain\SmartOrdering\RestaurantKitchenService;
use App\Domain\SmartOrdering\RestaurantPaymentService;
use App\Domain\SmartOrdering\RestaurantTableService;
use App\Domain\SmartOrdering\RestaurantWorkforceService;
use App\Domain\SmartOrdering\SmartOrderingMenuReadService;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Domain\SmartOrdering\SupplyIntelligenceService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RestaurantSaaSFinalCertificationTest extends TestCase
{
    public function test_final_manifest_records_complete_waves_and_keeps_production_activation_separate(): void
    {
        $path = base_path('../docs/execution/FOODEX_RESTAURANT_SAAS_FINAL_CERTIFICATION.json');
        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $manifest['schema_version']);
        $this->assertSame(1572, $manifest['program_issue']);
        $this->assertSame(2122, $manifest['certification_issue']);
        $this->assertSame('REPOSITORY_GA_READY_ON_GREEN_MERGE', $manifest['status']);
        $this->assertSame('NOT_PERFORMED', $manifest['production_activation']);
        $this->assertSame(0, $manifest['unresolved_repository_p0_p1']);

        foreach (['0_1', '2', '3', '4', '5', '6'] as $wave) {
            $this->assertSame('COMPLETE', $manifest['waves'][$wave]['status'], 'Wave '.$wave.' must be complete before Wave 7 certification.');
        }

        $this->assertSame('CERTIFICATION_GATE', $manifest['waves']['7']['status']);
        $this->assertSame(2122, $manifest['waves']['7']['issue']);

        $productionGate = $manifest['production_gate'];
        $this->assertTrue($productionGate['required_before_production_activation']);
        $this->assertSame('NOT_CLAIMED', $productionGate['pilot_canary']);
        $this->assertSame('NOT_CLAIMED', $productionGate['real_environment_evidence']);
        $this->assertTrue($productionGate['repository_merge_may_proceed_when_repository_gates_are_green']);
        $this->assertTrue($productionGate['production_activation_must_remain_blocked_without_real_environment_evidence']);
    }

    public function test_canonical_restaurant_saas_authorities_and_operator_routes_remain_composed(): void
    {
        foreach ([
            RestaurantControlPlaneService::class,
            RestaurantBillingService::class,
            RestaurantOnboardingService::class,
            RestaurantOperatorService::class,
            RestaurantSaasStoreAccess::class,
            RestaurantOperationsOverviewService::class,
            SmartOrderingRuntimePolicy::class,
            SmartOrderingMenuReadService::class,
            SmartOrderingMenuRevisionService::class,
            RestaurantPaymentService::class,
            RestaurantTableService::class,
            RestaurantGuestService::class,
            RestaurantKitchenService::class,
            RestaurantWorkforceService::class,
            SupplyIntelligenceService::class,
            RestaurantIntegrationService::class,
            RestaurantGrowthScaleService::class,
        ] as $authority) {
            $this->assertTrue(class_exists($authority), $authority.' must remain part of the Restaurant SaaS canonical composition.');
        }

        foreach ([
            'public.smart-ordering.storefront',
            'admin.smart-ordering.index',
            'admin.smart-ordering.menu.index',
            'admin.smart-ordering.operations.index',
            'admin.smart-ordering.analytics.index',
            'admin.smart-ordering.supply.index',
            'admin.smart-ordering.integrations.index',
            'admin.smart-ordering.growth.index',
            'admin.retail-stores.restaurant-saas',
        ] as $route) {
            $this->assertTrue(Route::has($route), $route.' must remain reachable through the canonical Restaurant SaaS route set.');
        }
    }

    public function test_final_certification_consumes_existing_cross_wave_and_recovery_evidence(): void
    {
        foreach ([
            'tests/Feature/RestaurantSaaSWave0CertificationTest.php',
            'tests/Feature/RestaurantSaaSGrowthScaleTest.php',
            'tests/Feature/RestaurantSaaSIntegrationHubTest.php',
            'tests/Feature/RestaurantOperationsDashboardConvergenceTest.php',
            'tests/Feature/SmartOrderingProgramCertificationTest.php',
            'tests/Deployment/ProductionServicesTest.php',
            'tests/Feature/Performance/RestaurantSaaSFinalPerformanceContractTest.php',
        ] as $relativePath) {
            $this->assertFileExists(base_path($relativePath), $relativePath.' is required final certification evidence.');
        }

        $this->assertFileExists(base_path('../docs/execution/FOODEX_RESTAURANT_SAAS_FINAL_CERTIFICATION.md'));
        $this->assertFileExists(base_path('../docs/execution/FOODEX_RESTAURANT_SAAS_MASTER_PLAN.md'));
    }
}
