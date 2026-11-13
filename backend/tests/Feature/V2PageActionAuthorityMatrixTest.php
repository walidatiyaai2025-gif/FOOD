<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bPricingMode;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 P082: pricing-page View and Manage are separate server-side actions.
 * A visible page must not authorize writes or other business-domain pages.
 */
final class V2PageActionAuthorityMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);

        // These synthetic action-matrix roles simulate explicit Wholesale
        // channel authorization. A global permission alone is not a tenant
        // grant: the operational scope must also recognize the B2B actor.
        config()->set('admin.channels.B2B.global_roles', [
            ...config('admin.channels.B2B.global_roles', []),
            'V2_P082_PRICING_VIEW',
            'V2_P082_PRICING_MANAGE',
            'V2_P082_PRICING_DENY',
        ]);

        $storeId = app(WholesalePrincipal::class)->storeId();
        DB::table('settings')->updateOrInsert(
            ['store_id' => $storeId, 'key' => B2bPricingMode::SETTING_KEY],
            [
                'value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function test_view_only_actor_can_read_pricing_but_cannot_edit_or_enter_other_admin_domains(): void
    {
        $actor = $this->actor('V2_P082_PRICING_VIEW', 'global', ['b2b.pricing.view']);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            Sanctum::actingAs($actor->fresh(), ['*']);

            $this->getJson('/api/v1/admin/b2b/prices')
                ->assertOk()
                ->assertJsonPath('meta.total', 0);
            $this->putJson('/api/v1/admin/b2b/prices', [])->assertForbidden();
            $this->getJson('/api/v1/admin/inventory')->assertForbidden();
            $this->getJson('/api/v1/admin/reports/dashboard')->assertForbidden();
            $this->getJson('/api/v1/admin/security/roles')->assertForbidden();
        }

        $this->assertSame(0, DB::table('b2b_price_rules')->count());
        $this->assertFalse($actor->fresh()->hasPermission('b2b.pricing.manage'));
        $this->assertFalse($actor->fresh()->hasPermission('reports.export'));
        $this->assertFalse($actor->fresh()->hasPermission('orders.dispatch'));
    }

    public function test_manage_only_permission_does_not_implicitly_grant_view(): void
    {
        $actor = $this->actor('V2_P082_PRICING_MANAGE', 'global', ['b2b.pricing.manage']);
        Sanctum::actingAs($actor, ['*']);

        $this->getJson('/api/v1/admin/b2b/prices')->assertForbidden();

        // The request reaches input validation only when the distinct Manage
        // permission authorizes the write action. No rule is actually saved.
        $this->putJson('/api/v1/admin/b2b/prices', [])->assertUnprocessable();
        $this->assertSame(0, DB::table('b2b_price_rules')->count());
    }

    public function test_explicit_view_deny_beats_pricing_role_grant_without_removing_manage(): void
    {
        $actor = $this->actor('V2_P082_PRICING_DENY', 'global', [
            'b2b.pricing.view',
            'b2b.pricing.manage',
        ]);
        $viewPermission = Permission::query()->where('code', 'b2b.pricing.view')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $viewPermission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('b2b.pricing.view'));
        $this->assertTrue($actor->fresh()->hasPermission('b2b.pricing.manage'));

        Sanctum::actingAs($actor->fresh(), ['*']);
        $this->getJson('/api/v1/admin/b2b/prices')->assertForbidden();
        $this->putJson('/api/v1/admin/b2b/prices', [])->assertUnprocessable();
        $this->assertSame(0, DB::table('b2b_price_rules')->count());
    }

    public function test_unscoped_global_view_permission_is_not_wholesale_tenant_authority(): void
    {
        $actor = $this->actor('V2_P082_UNSCOPED_VIEW', 'global', ['b2b.pricing.view']);
        Sanctum::actingAs($actor, ['*']);

        // Permission-based route access cannot bypass store/channel scope.
        $this->getJson('/api/v1/admin/b2b/prices')->assertNotFound();
    }

    public function test_retail_store_permission_cannot_read_wholesale_pricing_globally(): void
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'V2-P082-RETAIL',
            'name' => 'V2 P082 retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = $this->role('V2_P082_RETAIL_VIEW', 'store', ['b2b.pricing.view']);
        $actor = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse($actor->fresh()->hasPermission('b2b.pricing.view'));
        Sanctum::actingAs($actor->fresh(), ['*']);
        $this->getJson('/api/v1/admin/b2b/prices')->assertForbidden();
        $this->putJson('/api/v1/admin/b2b/prices', [])->assertForbidden();
        $this->assertSame(0, DB::table('b2b_price_rules')->count());
    }

    /** @param list<string> $abilities */
    private function actor(string $code, string $scope, array $abilities): User
    {
        $role = $this->role($code, $scope, $abilities);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    /** @param list<string> $abilities */
    private function role(string $code, string $scope, array $abilities): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_system' => false,
            'is_active' => true,
        ]);
        $permissionIds = Permission::query()->whereIn('code', $abilities)->pluck('id');
        $this->assertCount(count($abilities), $permissionIds);
        $role->permissions()->attach($permissionIds->all());

        return $role;
    }
}
