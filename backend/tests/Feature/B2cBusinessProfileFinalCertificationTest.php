<?php

namespace Tests\Feature;

use App\Models\Store;
use Tests\TestCase;

class B2cBusinessProfileFinalCertificationTest extends TestCase
{
    public function test_final_contract_has_one_authoritative_profile_source_and_no_creation_toggle(): void
    {
        $store = file_get_contents(app_path('Models/Store.php'));
        $provisioning = file_get_contents(resource_path('views/admin/retail-stores.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/RetailStoreProvisioningController.php'));

        $this->assertStringContainsString("BUSINESS_PROFILE_RETAIL_STORE = 'RETAIL_STORE'", $store);
        $this->assertStringContainsString("BUSINESS_PROFILE_RESTAURANT = 'RESTAURANT'", $store);
        $this->assertSame(
            [Store::BUSINESS_PROFILE_RETAIL_STORE, Store::BUSINESS_PROFILE_RESTAURANT],
            Store::businessProfiles(),
        );

        $this->assertStringContainsString('name="business_profile"', $provisioning);
        $this->assertStringContainsString('value="RETAIL_STORE"', $provisioning);
        $this->assertStringContainsString('value="RESTAURANT"', $provisioning);
        $this->assertStringNotContainsString('data-restaurant-saas-toggle', $provisioning);

        $this->assertStringContainsString('b2c.business_profile.compatibility_conversion', $controller);
        $this->assertStringContainsString('disabling the legacy feature never changes', $controller);
    }

    public function test_final_contract_covers_migration_rbac_route_login_wholesale_and_responsive_layers(): void
    {
        $catalog = file_get_contents(app_path('Services/BuiltInJobRoleCatalog.php'));
        $roles = file_get_contents(config_path('job_roles.php'));
        $workspace = file_get_contents(app_path('Http/Controllers/Admin/B2cWorkspaceController.php'));
        $restaurantAccess = file_get_contents(app_path('Domain/RestaurantSaaS/RestaurantSaasStoreAccess.php'));
        $landing = file_get_contents(app_path('Support/B2cWorkspaceLandingResolver.php'));
        $launcher = file_get_contents(resource_path('views/admin/_restaurant-management-launcher.blade.php'));
        $migration = file_get_contents(database_path('migrations/2026_10_18_114500_add_b2c_business_profile_to_stores.php'));

        $this->assertStringContainsString('B2cBusinessProfileBackfillService', $migration);
        $this->assertStringContainsString('business_profiles', $catalog);
        $this->assertStringContainsString("'business_profiles'=>['RETAIL_STORE']", $roles);
        $this->assertStringContainsString("'business_profiles'=>['RESTAURANT']", $roles);
        $this->assertStringContainsString('PROFILE_MODULES', $workspace);
        $this->assertStringNotContainsString("'wholesale_purchases' => 'orders.view'", $workspace);
        $this->assertStringContainsString('BUSINESS_PROFILE_RESTAURANT', $restaurantAccess);
        $this->assertStringContainsString('StoreBusinessProfile::RETAIL_STORE', $landing);
        $this->assertStringContainsString('StoreBusinessProfile::RESTAURANT', $landing);
        $this->assertStringContainsString('data-restaurant-task-first-nav', $launcher);
        $this->assertStringContainsString('admin.restaurant.wholesale-purchases.index', $launcher);

        foreach (['1200', '1024', '768', '430', '390', '360'] as $width) {
            $this->assertStringContainsString('max-width:'.$width.'px', $launcher);
        }
    }

    public function test_required_child_certification_suites_are_present(): void
    {
        foreach ([
            'tests/Feature/B2cBusinessProfileContractTest.php',
            'tests/Feature/B2cBusinessProfileProvisioningTest.php',
            'tests/Feature/B2cProfileAwareJobRoleTest.php',
            'tests/Feature/B2cProfileWorkspaceIsolationTest.php',
            'tests/Feature/RestaurantWholesalePurchasesTest.php',
            'tests/Feature/B2cProfileLoginLandingTest.php',
            'tests/Feature/RestaurantTaskDensityContractTest.php',
        ] as $relative) {
            $this->assertFileExists(base_path($relative), $relative.' is required B2C profile certification evidence.');
        }

        $this->assertFileExists(base_path('../docs/architecture/B2C_BUSINESS_PROFILE_WORKSPACES.md'));
    }
}
