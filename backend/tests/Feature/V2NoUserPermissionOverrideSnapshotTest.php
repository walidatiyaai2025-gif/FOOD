<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 T015R08: an export with zero per-user overrides is not evidence of
 * unrestricted, absent, or uniformly administrative RBAC permissions.
 */
final class V2NoUserPermissionOverrideSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_zero_user_overrides_uses_only_inherited_wholesale_roles_and_denies_privileged_and_roleless_routes(): void
    {
        $wholesale = $this->globalActor('B2B_ADMIN');
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach ([$wholesale, $roleless] as $actor) {
            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $actor->getKey()]);
            $this->assertSame([], $actor->permissionOverrideEffects());
            $this->assertSame($actor->inheritedPermissionCodes(), $actor->effectivePermissionCodes());
        }

        $this->assertTrue($wholesale->hasPermission('catalog.view'));
        $this->assertFalse($wholesale->hasPermission('roles.manage'));
        $this->assertFalse($roleless->hasPermission('catalog.view'));
        $this->assertSame([], $roleless->effectivePermissionCodes());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $wholesale->forceFill(['locale' => $locale])->save();
            $roleless->forceFill(['locale' => $locale])->save();

            $this->assertContains('catalog_management', $this->visibleKeys($wholesale));
            $this->assertNotContains('catalog_management', $this->visibleKeys($roleless));

            $this->actingAs($wholesale)
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($wholesale)
                ->get(route('admin.security.index', [], false))
                ->assertForbidden();

            $this->actingAs($roleless)
                ->get(route('admin.catalog.index', [], false))
                ->assertForbidden();
        }
    }

    public function test_zero_overrides_preserves_exact_retail_store_and_channel_boundaries(): void
    {
        $ownStore = $this->retailStore('V2-T015R08-OWN');
        $foreignStore = $this->retailStore('V2-T015R08-OTHER');
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'store_id' => $ownStore,
            'role_id' => $role->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $retail->getKey()]);
        $this->assertSame([], $retail->permissionOverrideEffects($ownStore));
        $this->assertSame($retail->inheritedPermissionCodes($ownStore), $retail->effectivePermissionCodes($ownStore));
        $this->assertTrue($retail->hasPermission('inventory.view', $ownStore));
        $this->assertFalse($retail->hasPermission('inventory.view', $foreignStore));
        $this->assertFalse($retail->hasPermission('inventory.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();

            $this->assertContains('b2c_inventory', $this->visibleKeys($retail));
            $this->assertNotContains('b2b_inventory', $this->visibleKeys($retail));

            $this->actingAs($retail)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $ownStore,
            ], false))->assertOk()->assertSee('dir="'.$direction.'"', false);

            $foreignResponse = $this->actingAs($retail)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $foreignStore,
            ], false));
            $this->assertContains($foreignResponse->getStatusCode(), [403, 404]);

            $this->actingAs($retail)->get(route('admin.b2b.module', [
                'module' => 'inventory',
            ], false))->assertForbidden();
        }
    }

    public function test_real_managed_user_deny_after_empty_snapshot_changes_only_the_target_and_is_audited(): void
    {
        $admin = $this->globalActor('SUPER_ADMIN');
        $target = $this->globalActor('B2B_ADMIN');
        $peer = $this->globalActor('B2B_ADMIN');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $grantsBefore = $role->permissions()->pluck('permissions.id')->all();

        foreach ([$target, $peer] as $actor) {
            $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $actor->getKey()]);
            $this->assertSame($actor->inheritedPermissionCodes(), $actor->effectivePermissionCodes());
            $this->assertTrue($actor->hasPermission('catalog.view'));
        }

        $allowedIds = Permission::query()
            ->whereIn('code', $target->inheritedPermissionCodes())
            ->where('code', '!=', 'catalog.view')
            ->pluck('id')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        app(RbacManager::class)->replaceUserPermissions(
            $admin,
            $target,
            $allowedIds,
            'global',
            null,
            Request::create('/admin/security/users/'.$target->getKey().'/permissions', 'PUT'),
        );

        $target = $target->fresh();
        $peer = $peer->fresh();
        $catalogPermission = Permission::query()->where('code', 'catalog.view')->firstOrFail();

        $this->assertTrue((bool) $target->has_permission_overrides);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->getKey(),
            'permission_id' => $catalogPermission->getKey(),
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
        $this->assertFalse($target->hasPermission('catalog.view'));
        $this->assertNotContains('catalog.view', $target->effectivePermissionCodes());
        $this->assertNotContains('catalog_management', $this->visibleKeys($target));
        $this->assertTrue($peer->hasPermission('catalog.view'));
        $this->assertContains('catalog_management', $this->visibleKeys($peer));
        $this->assertFalse((bool) $peer->has_permission_overrides);
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $peer->getKey()]);

        $this->actingAs($target)->get(route('admin.catalog.index', [], false))->assertForbidden();
        $this->actingAs($peer)->get(route('admin.catalog.index', [], false))->assertOk();
        $this->assertEqualsCanonicalizing(
            $grantsBefore,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.permissions_changed',
            'user_id' => $admin->getKey(),
            'auditable_id' => $target->getKey(),
        ]);
    }

    private function globalActor(string $code): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor->fresh();
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function visibleKeys(User $actor): array
    {
        $keys = [];

        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
