<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\Models\RestaurantSaasPlan;
use App\Domain\RestaurantSaaS\RestaurantBillingService;
use App\Domain\RestaurantSaaS\RestaurantControlPlaneService;
use App\Models\AppPreviewSession;
use App\Models\EnterpriseOrganization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RestaurantOperatorFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_operator_support_and_offboarding_are_bounded_audited_and_non_destructive(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'restaurant-operator@example.test');
        $store = $this->retailStore('RS-OP-A');

        app(RestaurantControlPlaneService::class)->provision($store, $admin);

        $this->actingAs($admin)
            ->post(route('admin.inspector.restaurant-support.grant', ['store' => $store]), [
                'case_reference' => 'SUP-2026-001',
                'reason_code' => 'technical_support',
                'ttl_minutes' => 30,
            ])
            ->assertRedirect(route('admin.inspector.index', [
                'restaurant_store_id' => $store->id,
            ]));

        $this->assertDatabaseHas('restaurant_support_access_grants', [
            'store_id' => $store->id,
            'case_reference' => 'SUP-2026-001',
            'reason_code' => 'technical_support',
            'granted_by_user_id' => $admin->id,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'restaurant_saas.support_access.granted',
            'store_id' => $store->id,
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.inspector.index', ['restaurant_store_id' => $store->id]))
            ->assertOk()
            ->assertSee('Tenant support & lifecycle')
            ->assertSee('SUP-2026-001')
            ->assertSee('Technical support')
            ->assertDontSee('token_hash');

        $session = AppPreviewSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'actor_user_id' => $admin->id,
            'target_user_id' => $admin->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $store->id,
            'mode' => 'read_only',
            'support_access' => true,
            'token_hash' => hash('sha256', 'restaurant-operator-preview'),
            'audit_correlation_id' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(20),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.inspector.restaurant-support.sessions.revoke', ['store' => $store]))
            ->assertRedirect();

        $this->assertDatabaseHas('app_preview_sessions', [
            'id' => $session->id,
            'revoked_reason' => 'restaurant_operator_revocation',
        ]);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'restaurant_saas.support_sessions.revoked',
            'store_id' => $store->id,
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.inspector.restaurant-lifecycle.update', ['store' => $store]), [
                'target_state' => 'CANCELED',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('restaurant_location_profiles', [
            'store_id' => $store->id,
            'lifecycle_state' => 'CANCELED',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.inspector.restaurant-lifecycle.update', ['store' => $store]), [
                'target_state' => 'OFFBOARDED',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('restaurant_location_profiles', [
            'store_id' => $store->id,
            'lifecycle_state' => 'OFFBOARDED',
        ]);
        $this->assertNotNull(
            DB::table('restaurant_support_access_grants')
                ->where('store_id', $store->id)
                ->value('revoked_at'),
        );
        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'code' => 'RS-OP-A',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('smart_ordering_sites', ['store_id' => $store->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'restaurant_saas.lifecycle.transitioned',
            'store_id' => $store->id,
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.inspector.index', ['restaurant_store_id' => $store->id]))
            ->assertOk()
            ->assertSee('Offboarded')
            ->assertSee('Retained · destructive deletion disabled');
    }


    public function test_operator_console_uses_authoritative_subscription_state_without_exposing_billing_references(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'restaurant-subscription-operator@example.test');
        $store = $this->retailStore('RS-OP-SUB');
        $context = app(RestaurantControlPlaneService::class)->provision($store, $admin);
        $organization = EnterpriseOrganization::query()->findOrFail($context['organization']['id']);

        $plan = RestaurantSaasPlan::query()->create([
            'code' => 'SUPPORT-PRO',
            'name' => 'Support Pro',
            'entitlements' => ['smart_ordering' => ['public_menu']],
            'limits' => [],
            'billing_interval' => 'monthly',
            'price_minor' => 2500,
            'currency' => 'KWD',
            'effective_from' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        app(RestaurantBillingService::class)->subscribe($organization, $plan, $admin, [
            'state' => RestaurantBillingService::ACTIVE,
            'payment_method_reference' => 'pm_private_operator_reference',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.inspector.index', ['restaurant_store_id' => $store->id]))
            ->assertOk()
            ->assertSee('Subscription state')
            ->assertSee('Support Pro')
            ->assertSee('Active')
            ->assertDontSee('pm_private_operator_reference')
            ->assertDontSee('payment_method_reference');
    }

    public function test_support_access_requires_platform_authority_and_a_provisioned_restaurant(): void
    {
        $store = $this->retailStore('RS-OP-B');
        $platformAdmin = $this->userWithRole('SUPER_ADMIN', 'restaurant-platform@example.test');
        $wholesaleAdmin = $this->userWithRole('B2B_ADMIN', 'restaurant-wholesale@example.test');

        $this->actingAs($wholesaleAdmin)
            ->post(route('admin.inspector.restaurant-support.grant', ['store' => $store]), [
                'case_reference' => 'SUP-2026-002',
                'reason_code' => 'technical_support',
                'ttl_minutes' => 15,
            ])
            ->assertForbidden();

        $this->actingAs($platformAdmin)
            ->post(route('admin.inspector.restaurant-support.grant', ['store' => $store]), [
                'case_reference' => 'SUP-2026-002',
                'reason_code' => 'technical_support',
                'ttl_minutes' => 15,
            ])
            ->assertStatus(409);

        app(RestaurantControlPlaneService::class)->provision($store, $platformAdmin);

        $this->actingAs($platformAdmin)
            ->from(route('admin.inspector.index', ['restaurant_store_id' => $store->id]))
            ->post(route('admin.inspector.restaurant-support.grant', ['store' => $store]), [
                'case_reference' => 'customer name and phone',
                'reason_code' => 'technical_support',
                'ttl_minutes' => 15,
            ])
            ->assertSessionHasErrors('case_reference');

        $this->assertDatabaseMissing('restaurant_support_access_grants', [
            'store_id' => $store->id,
            'case_reference' => 'customer name and phone',
        ]);
    }

    private function retailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function userWithRole(string $roleCode, string $email): User
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
