<?php

namespace Tests\Feature\Performance;

use Tests\TestCase;

final class RestaurantSaaSFinalPerformanceContractTest extends TestCase
{
    public function test_restaurant_high_growth_reads_remain_bounded_and_partner_api_is_rate_limited(): void
    {
        $overview = (string) file_get_contents(app_path('Domain/RestaurantSaaS/RestaurantOperationsOverviewService.php'));
        $growth = (string) file_get_contents(app_path('Domain/RestaurantSaaS/RestaurantGrowthScaleService.php'));
        $menuRead = (string) file_get_contents(app_path('Domain/SmartOrdering/SmartOrderingMenuReadService.php'));
        $apiRoutes = (string) file_get_contents(base_path('routes/api.php'));

        $this->assertStringContainsString("if (\$from->diffInDays(\$to) > 90)", $overview);
        $this->assertStringContainsString("\$from = \$to->subDays(90)->startOfDay();", $overview);
        $this->assertStringContainsString("abort_if(\$seconds > 366 * 86400, 422", $growth);
        $this->assertStringContainsString("min(50, \$perPage)", $menuRead);
        $this->assertStringContainsString("middleware('throttle:restaurant-integration-api')", $apiRoutes);
    }

    public function test_wave_7_manifest_requires_performance_and_real_deployment_acceptance(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('../docs/execution/FOODEX_RESTAURANT_SAAS_FINAL_CERTIFICATION.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertContains('performance', $manifest['required_repository_gates']);
        $this->assertContains('mysql_redis_deployment_acceptance', $manifest['required_repository_gates']);
        $this->assertContains(
            'backend/tests/Deployment/ProductionServicesTest.php',
            $manifest['evidence_tests'],
        );
    }
}
