<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2919 / historical Table 5 row 14.
 *
 * A zero-inherited-role export is not an account-type verdict. Stale direct
 * user permission rows cannot silently replace the required role/tenant
 * context. All identities in this test are synthetic, never live users.
 */
final class V2ExportedRolelessRow14OverrideBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_stale_global_and_store_allows_cannot_promote_roleless_identity_into_any_tenant(): void
    {
        $retailStore = $this->store('B2C', 'V2-2919-RETAIL');
        $wholesaleStore = $this->store('B2B', 'V2-2919-WHOLESALE');
        $target = User::factory()->create([
            'is_active' => true,
            'is_platform_customer' => false,
            'has_permission_overrides' => true,
        ]);

        // Simulate historic user overrides that survived a role removal.
        // Never write, identify or reclassify the actual exported account.
        $this->override($target, 'users.view', 'global');
        $this->override($target, 'inventory.view', 'global');
        $this->override($target, 'inventory.view', 'store:'.$retailStore, $retailStore);
        $target = $target->fresh();

        $this->assertSame(0, $target->roles()->count());
        $this->assertSame(0, $target->storeRoleAssignments()->count());
        $this->assertSame([], $target->inheritedPermissionCodes());
        $this->assertSame([], $target->effectivePermissionCodes());
        $this->assertSame([], $target->effectivePermissionCodes($retailStore));
        $this->assertFalse($target->hasPermission('users.view'));
        $this->assertFalse($target->hasPermission('inventory.view'));
        $this->assertFalse($target->hasPermission('inventory.view', $retailStore));
        $this->assertFalse($target->hasPermission('inventory.view', $wholesaleStore));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($target));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($target, 'inventory.view', 'b2b'));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($target, 'inventory.view', 'b2c'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $target->forceFill(['locale' => $locale])->save();

            $this->actingAs($target->fresh())
                ->get(route('admin.security.index', ['section' => 'users']))
                ->assertForbidden();
            $this->actingAs($target->fresh())
                ->get(route('admin.b2b.module', [
                    'module' => 'inventory',
                    'store_id' => $wholesaleStore,
                ]))
                ->assertForbidden();
            $this->assertContains(
                $this->actingAs($target->fresh())
                    ->get(route('admin.b2c.module', [
                        'module' => 'inventory',
                        'store_id' => $retailStore,
                    ]))->getStatusCode(),
                [403, 404],
            );
        }

        // A denied access test must never clean up or "repair" a real account.
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => Permission::query()->where('code', 'inventory.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$retailStore,
            'effect' => 'allow',
        ]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('user_store_roles', ['user_id' => $target->id]);
    }

    public function test_authorized_security_review_keeps_roleless_customer_classification_despite_legacy_sensitive_grants(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $target = User::factory()->create([
            'name' => 'V2 Row14 Synthetic Classification',
            'is_active' => true,
            'is_platform_customer' => true,
            'has_permission_overrides' => true,
        ]);
        $this->override($target, 'roles.manage', 'global');
        $this->override($target, 'users.delete', 'global');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', [
                    'section' => 'users',
                    'assignment' => 'roleless',
                    'q' => 'V2 Row14 Synthetic Classification',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-roleless-classification="customer"', false)
                ->assertViewHas('users', fn ($users): bool => $users->total() === 1);
        }

        $target = $target->fresh();
        $this->assertSame([], $target->inheritedPermissionCodes());
        $this->assertSame([], $target->effectivePermissionCodes());
        $this->assertFalse($target->hasPermission('roles.manage'));
        $this->assertFalse($target->hasPermission('users.delete'));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($target));
        $this->assertSame(0, $target->roles()->count());
        $this->assertSame(0, $target->storeRoleAssignments()->count());
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true, 'is_platform_customer' => true]);

        // A historic zero-role count never authorizes deletion or promotion.
        $this->actingAs($target)
            ->get(route('admin.sidebar-access.roles'))
            ->assertForbidden();
    }

    private function override(User $user, string $permissionCode, string $scopeKey, ?int $storeId = null): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => Permission::query()->where('code', $permissionCode)->firstOrFail()->id,
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
