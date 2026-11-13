<?php

namespace Tests\Feature;

use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 #2914: archived Table 5 data row 9 has no Dashboard RBAC role.
 *
 * Fixtures deliberately use invented identities. The archived export alone
 * does not determine whether the current account is a Customer/Driver/Van,
 * and no real account is inspected or modified here.
 */
final class V2ExportedAccountRow9AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_legitimate_roleless_retail_customer_profile_stays_accessible_without_dashboard_authority(): void
    {
        $store = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-ROW9-SYNTHETIC-RETAIL',
            'name' => 'Row9 Synthetic Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'name' => 'Synthetic Roleless Retail Customer',
            'email' => 'row9-customer@example.test',
            'is_platform_customer' => true,
            'is_active' => true,
            'locale' => 'en',
        ]);
        $legacy = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => $user->name,
            'email' => $user->email,
        ]);
        $domain = B2cCustomer::query()->create([
            'legacy_customer_id' => $legacy->id,
            'store_id' => $store,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);

        $this->assertSame(0, $user->roles()->count());
        $this->assertSame(0, $user->storeRoleAssignments()->count());
        $this->assertSame([], $user->inheritedPermissionCodes());
        $this->assertSame([], $user->effectivePermissionCodes());
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($user, 'orders.view', 'b2c'));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($user, 'orders.view', 'b2b'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $user = $user->fresh();

            $this->assertSame([], app(AdminNavigation::class)->groupsFor($user));
            $this->actingAs($user, 'web')->get(route('admin.security.index'))->assertForbidden();
            $this->actingAs($user, 'web')->get(route('admin.sidebar-access.roles'))->assertForbidden();
            $this->actingAs($user, 'web')->get(route('admin.b2b.orders.canonical', ['store_id' => app(WholesalePrincipal::class)->storeId()]))->assertForbidden();
            $this->actingAs($user, 'web')->get(route('admin.b2c.orders.canonical', ['store_id' => $store]))->assertForbidden();

            // A real Customer profile is not a Dashboard role. Do not break it
            // merely because the confidential export had no admin role.
            Sanctum::actingAs($user, ['app:customer']);
            $this->getJson('/api/v1/profile')
                ->assertOk()
                ->assertJsonPath('customer.id', $domain->id)
                ->assertJsonPath('customer.type', 'b2c')
                ->assertJsonPath('locale', $locale);
        }

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
        $this->assertDatabaseHas('b2c_customers', ['id' => $domain->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $user->id]);
    }

    public function test_unclassified_roleless_identity_is_not_automatically_given_a_customer_or_admin_role(): void
    {
        $user = User::factory()->create([
            'name' => 'Synthetic Row9 Unclassified Identity',
            'email' => 'row9-unclassified@example.test',
            'is_platform_customer' => false,
            'is_active' => true,
            'locale' => 'en',
        ]);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $user = $user->fresh();

            $this->assertSame([], app(AdminNavigation::class)->groupsFor($user));
            $this->actingAs($user, 'web')->get(route('admin.security.index'))->assertForbidden();
            $this->actingAs($user, 'web')->get(route('admin.sidebar-access.roles'))->assertForbidden();

            Sanctum::actingAs($user, ['app:customer']);
            $this->getJson('/api/v1/profile')
                ->assertOk()
                ->assertJsonPath('customer', null);
            $this->getJson('/api/v1/profile/addresses')->assertForbidden();
        }

        $this->assertSame([], $user->fresh()->inheritedPermissionCodes());
        $this->assertSame([], $user->fresh()->effectivePermissionCodes());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('user_store_roles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $user->id]);
    }
}
