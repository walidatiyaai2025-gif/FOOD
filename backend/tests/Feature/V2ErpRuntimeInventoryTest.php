<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class V2ErpRuntimeInventoryTest extends TestCase
{
    public function test_inventory_is_read_only_json_and_does_not_claim_runtime_authorization(): void
    {
        Route::middleware(['auth', 'can:erp.reports.view', 'tenant.context'])
            ->get('admin/erp/v2-inventory-proof', static fn (): string => 'test')
            ->name('admin.erp.v2.inventory.proof');

        $report = $this->inventory();
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame(2842, $report['issue']);
        $this->assertTrue($report['runtime_observed']);
        $this->assertFalse($report['production_deployment_verified']);
        $this->assertSame(PHP_VERSION, $report['installed_production_dependency_versions']['php']);
        $this->assertArrayHasKey('laravel/framework', $report['installed_production_dependency_versions']);
        $this->assertSame('not_attested', $report['acceptance']['environment_identity']);
        $this->assertSame('not_tested', $report['acceptance']['effective_role_permissions']);
        $this->assertSame('no_contract_change', $report['acceptance']['customer_driver_van_api_parity']);
        $this->assertIsString($report['route_inventory_sha256']);
        $this->assertCount(64, str_split($report['route_inventory_sha256']));

        $match = collect($report['erp_wholesale_related_routes'])
            ->firstWhere('name', 'admin.erp.v2.inventory.proof');
        $this->assertNotNull($match);
        $this->assertContains('auth', $match['middleware_signals']);
        $this->assertContains('tenant.context', $match['middleware_signals']);
        $this->assertContains('erp.reports.view', $match['permission_codes_declared']);
        $this->assertFalse($match['server_authorization_verified']);
        $this->assertFalse($match['tenant_scope_verified']);
        $this->assertFalse($match['module_entitlement_verified']);
        $this->assertFalse($match['operational_readiness_verified']);
    }

    public function test_unprotected_route_is_reported_as_unverified_not_authorized(): void
    {
        Route::get('api/v1/b2b/v2-inventory-unprotected-probe', static fn (): string => 'test')
            ->name('api.v1.b2b.v2.inventory.unprotected');

        $report = $this->inventory();
        $match = collect($report['erp_wholesale_related_routes'])
            ->firstWhere('name', 'api.v1.b2b.v2.inventory.unprotected');

        $this->assertNotNull($match);
        $this->assertSame('b2b_hint_only', $match['channel_hint']);
        $this->assertSame([], $match['permission_codes_declared']);
        $this->assertFalse($match['server_authorization_verified']);
    }

    public function test_report_never_dumps_process_secrets_or_configuration_values(): void
    {
        putenv('FOODEX_V2_PRIVATE_TOKEN=do-not-expose-in-inventory');
        try {
            $report = $this->inventory();
            $encoded = json_encode($report, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('do-not-expose-in-inventory', $encoded);
            $this->assertArrayNotHasKey('env', $report);
            $this->assertArrayNotHasKey('database', $report);
            $this->assertArrayNotHasKey('users', $report);
        } finally {
            putenv('FOODEX_V2_PRIVATE_TOKEN');
        }
    }

    /** @return array<string, mixed> */
    private function inventory(): array
    {
        $this->assertSame(0, Artisan::call('foodex:v2-erp-inventory'));

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);

        return $report;
    }
}
