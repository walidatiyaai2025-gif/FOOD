<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Wave0ExistingModuleCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wave 0 does not replace the canonical regression suites. This matrix makes
     * the exact existing authorities explicit while the Required CI backend job
     * executes the complete backend test suite on the candidate head.
     */
    public function test_wave0_regression_matrix_points_to_canonical_existing_feature_suites(): void
    {
        $matrix = [
            'sales_orders' => [
                'tests/Feature/OrderDomainTest.php',
                'tests/Feature/DashboardOrderManagementTest.php',
            ],
            'inventory' => [
                'tests/Feature/InventoryAdministrationTest.php',
                'tests/Feature/RetailInventoryIntelligenceTest.php',
            ],
            'crm_customer' => [
                'tests/Feature/CustomerProfileDomainTest.php',
                'tests/Feature/DashboardCustomer360Test.php',
            ],
            'whatsapp' => [
                'tests/Feature/SmartOrderingWhatsAppBridgeTest.php',
            ],
            'van' => [
                'tests/Feature/B2BVanFinalClosureGateTest.php',
                'tests/Feature/DriverB2BCutoverTest.php',
            ],
            'rbac_tenancy' => [
                'tests/Feature/TenantOperationalIsolationTest.php',
                'tests/Feature/ChannelTenantIsolationAuditTest.php',
                'tests/Feature/StoreScopingTest.php',
            ],
            'migration_upgrade' => [
                'tests/Feature/CoreSchemaTest.php',
                'tests/Feature/BuiltInRoleUpgradeReconciliationTest.php',
            ],
        ];

        foreach ($matrix as $area => $paths) {
            $this->assertNotEmpty($paths, "Regression area [{$area}] must name at least one canonical suite.");

            foreach ($paths as $path) {
                $this->assertFileExists(base_path($path), "Missing Wave 0 regression authority [{$area}]: {$path}");
            }
        }
    }

    public function test_sample_module_enable_disable_does_not_mutate_existing_business_tables(): void
    {
        $businessTables = ['orders', 'products', 'customers', 'stores'];
        $before = [];

        foreach ($businessTables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $registry = app(ModuleRegistry::class);
        $manager = app(ModuleLifecycleManager::class);

        $module = $registry->syncManifest('sample_optional');
        $this->assertSame(ModuleState::DISABLED, $module->state);

        $manager->transition('sample_optional', ModuleState::ENABLING);
        $manager->transition('sample_optional', ModuleState::ENABLED);
        $manager->transition('sample_optional', ModuleState::DISABLED);

        $this->assertFalse($registry->isEnabled('sample_optional'));

        foreach ($businessTables as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                "Wave 0 sample-module lifecycle mutated existing business table [{$table}].",
            );
        }
    }
}
