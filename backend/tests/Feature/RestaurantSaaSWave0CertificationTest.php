<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\Models\RestaurantSaasPlan;
use App\Domain\RestaurantSaaS\RestaurantBillingService;
use App\Domain\RestaurantSaaS\RestaurantControlPlaneService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Models\EnterpriseOrganization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RestaurantSaaSWave0CertificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('smart_ordering.enterprise_entitlement_bridge', false);
    }

    public function test_clean_tenant_billing_suspend_restore_and_smart_ordering_compatibility_are_coherent(): void
    {
        [$store, $admin] = $this->restaurant('RS-W0-CERT-A', false);

        $controlPlane = app(RestaurantControlPlaneService::class);
        $first = $controlPlane->provision($store, $admin);
        $second = $controlPlane->provision($store, $admin);

        $this->assertSame($first['organization']['id'], $second['organization']['id']);
        $this->assertSame($first['brand']['id'], $second['brand']['id']);
        $this->assertSame($store->id, $second['location']['id']);
        $this->assertDatabaseCount('restaurant_location_profiles', 1);
        $this->assertDatabaseCount('enterprise_store_scopes', 1);
        $this->assertDatabaseCount('smart_ordering_sites', 1);

        $organization = EnterpriseOrganization::query()->findOrFail($first['organization']['id']);
        $site = SmartOrderingSite::query()->where('store_id', $store->id)->firstOrFail();
        app(SmartOrderingSiteService::class)->setEntitlement(
            $admin,
            $site,
            Module::PUBLIC_MENU,
            true,
        );

        // Legacy child entitlements cannot bypass the explicit Retail Store master switch.
        $this->assertFalse(app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::PUBLIC_MENU));

        $plan = RestaurantSaasPlan::query()->create([
            'code' => 'W0-CERT-PRO',
            'name' => 'Wave 0 Certification Pro',
            'entitlements' => [
                SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE => [
                    Module::PUBLIC_MENU->value,
                ],
            ],
            'limits' => [],
            'billing_interval' => 'monthly',
            'price_minor' => 2500,
            'currency' => 'KWD',
            'effective_from' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        $billing = app(RestaurantBillingService::class);
        $subscription = $billing->subscribe($organization, $plan, $admin, [
            'state' => RestaurantBillingService::ACTIVE,
            'payment_method_reference' => 'pm_wave0_cert_private',
        ]);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            'scope_type' => EntitlementScope::ORGANIZATION,
            'scope_id' => $organization->id,
            'is_enabled' => true,
        ]);

        $subscription = $billing->transition(
            $subscription,
            RestaurantBillingService::PAST_DUE,
            $admin,
        );
        $subscription = $billing->transition(
            $subscription,
            RestaurantBillingService::GRACE,
            $admin,
            now()->addDays(3),
        );
        $subscription = $billing->transition(
            $subscription,
            RestaurantBillingService::SUSPENDED,
            $admin,
        );

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            'scope_type' => EntitlementScope::ORGANIZATION,
            'scope_id' => $organization->id,
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('stores', ['id' => $store->id]);
        $this->assertDatabaseHas('restaurant_location_profiles', ['store_id' => $store->id]);
        $this->assertDatabaseHas('smart_ordering_sites', ['id' => $site->id]);

        $billing->transition($subscription, RestaurantBillingService::ACTIVE, $admin);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            'scope_type' => EntitlementScope::ORGANIZATION,
            'scope_id' => $organization->id,
            'is_enabled' => true,
        ]);

        config()->set('smart_ordering.enterprise_entitlement_bridge', true);
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            ModuleState::ENABLING,
        );
        $lifecycle->transition(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            ModuleState::ENABLED,
        );

        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            [Module::PUBLIC_MENU->value],
        );

        $this->assertTrue(app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::PUBLIC_MENU));

        $entitlements->set(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            false,
        );

        $this->assertFalse(app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::PUBLIC_MENU));
    }

    public function test_tenant_authorization_and_operator_privacy_remain_fail_closed(): void
    {
        [$storeA, $adminA] = $this->restaurant('RS-W0-CERT-B-A');
        [$storeB, $adminB] = $this->restaurant('RS-W0-CERT-B-B');

        $controlPlane = app(RestaurantControlPlaneService::class);
        $controlPlane->provision($storeA, $adminA);
        $contextB = $controlPlane->provision($storeB, $adminB);

        Sanctum::actingAs($adminA);
        $this->getJson('/api/v1/admin/restaurant-saas/locations/'.$storeB->id)->assertForbidden();

        Sanctum::actingAs($adminB);
        $this->getJson('/api/v1/admin/restaurant-saas/locations/'.$storeB->id)
            ->assertOk()
            ->assertJsonPath('location.id', $storeB->id);

        $organizationB = EnterpriseOrganization::query()->findOrFail($contextB['organization']['id']);
        $plan = RestaurantSaasPlan::query()->create([
            'code' => 'W0-CERT-PRIVACY',
            'name' => 'Wave 0 Privacy Plan',
            'entitlements' => [
                SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE => [
                    Module::PUBLIC_MENU->value,
                ],
            ],
            'limits' => [],
            'billing_interval' => 'monthly',
            'price_minor' => 1000,
            'currency' => 'KWD',
            'effective_from' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        app(RestaurantBillingService::class)->subscribe($organizationB, $plan, $adminB, [
            'payment_method_reference' => 'pm_must_not_render_wave0',
        ]);

        $platformAdmin = $this->globalRoleUser('SUPER_ADMIN', 'restaurant-wave0-cert@example.test');

        $this->actingAs($platformAdmin)
            ->get(route('admin.inspector.index', ['restaurant_store_id' => $storeB->id]))
            ->assertOk()
            ->assertDontSee('pm_must_not_render_wave0')
            ->assertDontSee('payment_method_reference');

        $this->assertFalse(Schema::hasColumn('restaurant_saas_billing_records', 'card_number'));
        $this->assertFalse(Schema::hasColumn('restaurant_saas_billing_records', 'card_cvv'));
    }

    /** @return array{Store,User} */
    private function restaurant(string $code, bool $enableRestaurantSaas = true): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        if ($enableRestaurantSaas) {
            $this->enableRestaurantSaasForTestStore((int) $store->id);
        }

        $admin = User::query()->create([
            'name' => 'Restaurant Wave 0 Certification Admin',
            'email' => strtolower($code).'-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $store->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$store, $admin];
    }

    private function globalRoleUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
