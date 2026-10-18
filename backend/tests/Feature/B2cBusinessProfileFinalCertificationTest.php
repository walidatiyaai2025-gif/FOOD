<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use Tests\TestCase;

class B2cBusinessProfileFinalCertificationTest extends TestCase
{
    public function test_authoritative_profile_backfill_provisioning_and_rbac_contracts_are_present(): void
    {
        $this->assertSame(
            ['RETAIL_STORE', 'RESTAURANT'],
            array_map(
                static fn (StoreBusinessProfile $profile): string => $profile->value,
                StoreBusinessProfile::cases(),
            ),
        );

        $migration = file_get_contents(database_path('migrations/2026_10_18_114500_add_b2c_business_profile_to_stores.php'));
        $profileService = file_get_contents(app_path('Services/StoreBusinessProfileService.php'));
        $provisioning = file_get_contents(app_path('Http/Controllers/Admin/RetailStoreProvisioningController.php'));
        $roles = file_get_contents(config_path('job_roles.php'));
        $catalog = file_get_contents(app_path('Services/BuiltInJobRoleCatalog.php'));

        $this->assertStringContainsString('store_business_profile_backfills', $migration);
        $this->assertStringContainsString('restaurant_saas_master_enabled', $migration);
        $this->assertStringContainsString('legacy_b2c_default_retail', $migration);

        $this->assertStringContainsString("channel !== 'B2C'", $profileService);
        $this->assertStringContainsString('StoreBusinessProfile::tryFrom', $profileService);

        $this->assertStringContainsString("'business_profile' => ['required'", $provisioning);
        $this->assertStringContainsString("PROFILE_RETAIL = 'RETAIL_STORE'", $provisioning);
        $this->assertStringContainsString("PROFILE_RESTAURANT = 'RESTAURANT'", $provisioning);

        $this->assertStringContainsString("'business_profiles'=>['RETAIL_STORE']", $roles);
        $this->assertStringContainsString("'business_profiles'=>['RESTAURANT']", $roles);
        $this->assertStringContainsString("definition['business_profiles']", $catalog);
    }

    public function test_workspace_login_and_wholesale_separation_contracts_are_present(): void
    {
        $workspace = file_get_contents(app_path('Http/Controllers/Admin/B2cWorkspaceController.php'));
        $restaurantAccess = file_get_contents(app_path('Domain/RestaurantSaaS/RestaurantSaasStoreAccess.php'));
        $landing = file_get_contents(app_path('Support/B2cWorkspaceLandingResolver.php'));
        $launcher = file_get_contents(resource_path('views/admin/_restaurant-management-launcher.blade.php'));
        $inventory = file_get_contents(base_path('../docs/execution/DASHBOARD_NAVIGATION_CAPABILITY_INVENTORY.json'));

        $this->assertStringContainsString('PROFILE_MODULES', $workspace);
        $this->assertStringContainsString('moduleAllowedForProfile', $workspace);
        $this->assertStringContainsString('isRestaurant', $restaurantAccess);
        $this->assertStringContainsString('StoreBusinessProfile::RETAIL_STORE', $landing);
        $this->assertStringContainsString('StoreBusinessProfile::RESTAURANT', $landing);

        $this->assertStringContainsString('data-restaurant-task-first-nav', $launcher);
        $this->assertStringContainsString('admin.restaurant.wholesale-purchases.index', $launcher);
        $this->assertStringContainsString('admin.restaurant.wholesale-purchases.index', $inventory);

        foreach (['1280', '1024', '768', '430', '390', '360'] as $width) {
            $this->assertStringContainsString('max-width:'.$width.'px', $launcher);
        }
    }

    public function test_all_child_certification_suites_and_final_docs_are_present(): void
    {
        foreach ([
            'tests/Feature/StoreBusinessProfileTest.php',
            'tests/Feature/RetailStoreProvisioningTest.php',
            'tests/Feature/BuiltInJobRoleCatalogTest.php',
            'tests/Feature/B2cProfileWorkspaceIsolationTest.php',
            'tests/Feature/RestaurantWholesalePurchasesTest.php',
            'tests/Feature/B2cProfileLoginLandingTest.php',
            'tests/Feature/RestaurantTaskDensityContractTest.php',
        ] as $relative) {
            $this->assertFileExists(base_path($relative), $relative.' is required final B2C profile certification evidence.');
        }

        $this->assertFileExists(base_path('../docs/architecture/B2C_BUSINESS_PROFILE_WORKSPACES.md'));
        $this->assertFileExists(base_path('../docs/testing/B2C_PROFILE_CONVERGENCE_2175.md'));
    }
}
