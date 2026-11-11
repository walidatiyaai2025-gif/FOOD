<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2924: privacy-safe regression for the historical exported row 19,
 * which reports no Dashboard RBAC role and zero inherited permissions.
 *
 * The synthetic actor is NOT the source account. Missing Dashboard roles
 * must never imply account deletion or automatic privilege assignment.
 */
final class V2ExportedRolelessAccountRow19AuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_roleless_identity_has_no_inherited_dashboard_or_tenant_access_in_arabic_and_english(): void
    {
        $wholesaleStore = $this->store('B2B', 'V2-2924-WHOLESALE');
        $retailStore = $this->store('B2C', 'V2-2924-RETAIL');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $scope = app(OperationalTenantScope::class);

        $this->assertSame(0, $actor->roles()->count());
        $this->assertSame(0, $actor->storeRoleAssignments()->count());
        $this->assertSame([], $actor->inheritedPermissionCodes());
        $this->assertSame([], $actor->inheritedPermissionCodes($wholesaleStore));
        $this->assertSame([], $actor->inheritedPermissionCodes($retailStore));
        $this->assertSame([], $actor->effectivePermissionCodes());

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
            $this->assertFalse($actor->hasRole('B2B_ADMIN'));
            $this->assertFalse($actor->hasPermission('roles.manage'));
            $this->assertFalse($actor->hasPermission('inventory.view', $wholesaleStore));
            $this->assertFalse($actor->hasPermission('inventory.view', $retailStore));
            $this->assertSame([], $scope->allowedStoreIds($actor, 'inventory.view', 'b2b'));
            $this->assertSame([], $scope->allowedStoreIds($actor, 'inventory.view', 'b2c'));
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));

            $this->actingAs($actor)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();

            $this->assertContains(
                $this->actingAs($actor)->get(route('admin.b2b.module', ['module' => 'inventory']))
                    ->getStatusCode(),
                [403, 404],
            );
            $this->assertContains(
                $this->actingAs($actor)->get(route('admin.b2c.module', [
                    'module' => 'inventory',
                    'store_id' => $retailStore,
                ]))->getStatusCode(),
                [403, 404],
            );
        }

        // An unassigned account is preserved for authorized classification:
        // it is not implicitly disabled, deleted or converted to an admin.
        $this->assertTrue((bool) $actor->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $actor->getKey()]);
    }

    public function test_orphaned_allow_overrides_cannot_upgrade_a_roleless_identity(): void
    {
        $wholesaleStore = $this->store('B2B', 'V2-2924-DENIED-B2B');
        $retailStore = $this->store('B2C', 'V2-2924-DENIED-B2C');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach ([
            ['roles.manage', 'global', null],
            ['inventory.view', 'global', null],
            ['inventory.view', 'store:'.$retailStore, $retailStore],
        ] as [$code, $scopeKey, $storeId]) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->getKey(),
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->getKey(),
                'scope_key' => $scopeKey,
                'store_id' => $storeId,
                'effect' => 'allow',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $this->assertSame([], $actor->inheritedPermissionCodes());
            $this->assertSame([], $actor->effectivePermissionCodes());
            $this->assertFalse($actor->hasPermission('roles.manage'));
            $this->assertFalse($actor->hasPermission('inventory.view'));
            $this->assertFalse($actor->hasPermission('inventory.view', $wholesaleStore));
            $this->assertFalse($actor->hasPermission('inventory.view', $retailStore));
            $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'inventory.view', 'b2b'));
            $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($actor, 'inventory.view', 'b2c'));
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));

            $this->actingAs($actor)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();
        }

        $this->assertSame(0, $actor->roles()->count());
        $this->assertSame(0, $actor->storeRoleAssignments()->count());
        $this->assertTrue((bool) $actor->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $actor->getKey()]);
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
