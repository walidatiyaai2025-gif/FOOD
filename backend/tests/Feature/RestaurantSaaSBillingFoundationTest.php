<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\Models\RestaurantSaasPlan;
use App\Domain\RestaurantSaaS\RestaurantBillingService;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseOrganization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

final class RestaurantSaaSBillingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_subscription_lifecycle_reconciles_entitlements_and_preserves_business_data(): void
    {
        [$organization, $admin, $storeId] = $this->organization('RS-BILL-LIFECYCLE');
        $plan = $this->plan('STARTER');
        $service = app(RestaurantBillingService::class);

        $subscription = $service->subscribe($organization, $plan, $admin, [
            'state' => RestaurantBillingService::TRIAL,
            'trial_ends_at' => now()->addDays(14),
            'billing_contact' => ['email' => 'billing@example.test'],
            'tax_profile' => ['country_code' => 'KW', 'tax_number' => 'KW-100'],
            'payment_method_reference' => 'pm_ref_only_100',
        ]);

        $this->assertSame(RestaurantBillingService::TRIAL, $subscription->state);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'is_enabled' => true,
        ]);

        $subscription = $service->transition(
            $subscription,
            RestaurantBillingService::PAST_DUE,
            $admin,
        );
        $subscription = $service->transition(
            $subscription,
            RestaurantBillingService::GRACE,
            $admin,
            now()->addDays(3),
        );

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_id' => $organization->id,
            'is_enabled' => true,
        ]);

        $subscription = $service->transition(
            $subscription,
            RestaurantBillingService::SUSPENDED,
            $admin,
        );

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_id' => $organization->id,
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('restaurant_saas_subscriptions', [
            'id' => $subscription->id,
            'organization_id' => $organization->id,
            'state' => RestaurantBillingService::SUSPENDED,
        ]);
        $this->assertDatabaseHas('stores', ['id' => $storeId]);
        $this->assertDatabaseHas('restaurant_saas_plans', ['id' => $plan->id]);

        $subscription = $service->transition(
            $subscription,
            RestaurantBillingService::ACTIVE,
            $admin,
        );

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_id' => $organization->id,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'restaurant_saas.subscription.state_changed',
            'user_id' => $admin->id,
        ]);
    }

    public function test_plan_change_rolls_back_if_entitlement_reconciliation_fails(): void
    {
        [$organization, $admin] = $this->organization('RS-BILL-ATOMIC');
        $service = app(RestaurantBillingService::class);
        $valid = $this->plan('VALID');
        $subscription = $service->subscribe($organization, $valid, $admin);

        $invalid = RestaurantSaasPlan::query()->create([
            'code' => 'INVALID-MODULE',
            'name' => 'Invalid Module Plan',
            'entitlements' => ['module_that_does_not_exist' => null],
            'limits' => [],
            'billing_interval' => 'monthly',
            'price_minor' => 1000,
            'currency' => 'KWD',
            'is_active' => true,
        ]);

        try {
            $service->subscribe($organization, $invalid, $admin);
            $this->fail('Unknown module should fail entitlement reconciliation.');
        } catch (Throwable) {
            // Transaction failure must leave the prior subscription and entitlement intact.
        }

        $this->assertDatabaseHas('restaurant_saas_subscriptions', [
            'id' => $subscription->id,
            'plan_id' => $valid->id,
            'state' => RestaurantBillingService::ACTIVE,
        ]);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_id' => $organization->id,
            'is_enabled' => true,
        ]);
    }

    public function test_usage_meter_is_idempotent_tenant_scoped_and_rejects_key_reuse_with_different_data(): void
    {
        [$organization, $admin] = $this->organization('RS-BILL-METER');
        $service = app(RestaurantBillingService::class);
        $occurredAt = now();

        $first = $service->recordUsage(
            $organization,
            'completed_online_orders',
            7,
            'meter-order-batch-100',
            $occurredAt,
            'orders:100-106',
            $admin,
        );
        $second = $service->recordUsage(
            $organization,
            'completed_online_orders',
            7,
            'meter-order-batch-100',
            $occurredAt,
            'orders:100-106',
            $admin,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('restaurant_saas_usage_meters', 1);

        try {
            $service->recordUsage(
                $organization,
                'completed_online_orders',
                8,
                'meter-order-batch-100',
                $occurredAt,
                'orders:100-107',
                $admin,
            );
            $this->fail('Idempotency key reuse with different payload must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('restaurant_saas_usage_meters', 1);
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'restaurant_saas.usage.recorded',
            'user_id' => $admin->id,
        ]);
    }

    public function test_provider_state_reconciliation_is_idempotent_and_stores_references_not_card_data(): void
    {
        [$organization, $admin] = $this->organization('RS-BILL-PROVIDER');
        $service = app(RestaurantBillingService::class);
        $subscription = $service->subscribe($organization, $this->plan('PRO'), $admin);

        $record = $service->reconcileBillingRecord(
            $subscription,
            'invoice',
            'open',
            'test_provider',
            'inv_100',
            12500,
            'KWD',
            now(),
            'pm_reference_100',
            ['invoice_number' => 'INV-100'],
            $admin,
        );
        $same = $service->reconcileBillingRecord(
            $subscription,
            'invoice',
            'open',
            'test_provider',
            'inv_100',
            12500,
            'KWD',
            $record->occurred_at,
            'pm_reference_100',
            ['invoice_number' => 'INV-100'],
            $admin,
        );
        $paid = $service->reconcileBillingRecord(
            $subscription,
            'invoice',
            'paid',
            'test_provider',
            'inv_100',
            12500,
            'KWD',
            $record->occurred_at,
            'pm_reference_100',
            ['invoice_number' => 'INV-100'],
            $admin,
        );

        $this->assertSame($record->id, $same->id);
        $this->assertSame($record->id, $paid->id);
        $this->assertDatabaseCount('restaurant_saas_billing_records', 1);
        $this->assertDatabaseHas('restaurant_saas_billing_records', [
            'id' => $record->id,
            'status' => 'paid',
            'provider_reference' => 'inv_100',
            'payment_method_reference' => 'pm_reference_100',
        ]);
        $this->assertFalse(Schema::hasColumn('restaurant_saas_billing_records', 'card_number'));
        $this->assertFalse(Schema::hasColumn('restaurant_saas_billing_records', 'card_cvv'));
    }

    public function test_comp_plan_and_addon_are_audited_without_duplicate_rows(): void
    {
        [$organization, $admin] = $this->organization('RS-BILL-COMP');
        $service = app(RestaurantBillingService::class);
        $subscription = $service->subscribe($organization, $this->plan('COMP'), $admin, [
            'is_complimentary' => true,
            'complimentary_reason' => 'Pilot customer',
        ]);

        $service->setAddon($subscription, 'extra_location', 2, now(), null, ['source' => 'manual'], $admin);
        $service->setAddon($subscription, 'extra_location', 2, now()->startOfSecond(), null, ['source' => 'manual'], $admin);

        $this->assertDatabaseHas('restaurant_saas_subscriptions', [
            'id' => $subscription->id,
            'is_complimentary' => true,
            'complimentary_reason' => 'Pilot customer',
        ]);
        $this->assertDatabaseCount('restaurant_saas_subscription_addons', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'restaurant_saas.subscription.addon_changed',
            'user_id' => $admin->id,
        ]);
    }

    private function plan(string $code): RestaurantSaasPlan
    {
        return RestaurantSaasPlan::query()->create([
            'code' => $code,
            'name' => $code.' Plan',
            'entitlements' => ['smart_ordering' => ['public_menu']],
            'limits' => ['active_locations' => 1, 'staff_seats' => 5],
            'billing_interval' => 'monthly',
            'price_minor' => 1500,
            'currency' => 'KWD',
            'effective_from' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0:EnterpriseOrganization,1:User,2:int}
     */
    private function organization(string $storeCode): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $storeCode,
            'name' => $storeCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = User::query()->create([
            'name' => 'Restaurant Billing Admin',
            'email' => strtolower($storeCode).'-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore(
            Store::query()->findOrFail($storeId),
            $admin,
        );
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($storeId);

        $this->assertNotNull($scope);

        return [
            EnterpriseOrganization::query()->findOrFail($scope['organization_id']),
            $admin,
            $storeId,
        ];
    }
}
