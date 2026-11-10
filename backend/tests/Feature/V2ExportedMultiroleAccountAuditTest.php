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
 * V2 #2925: privacy-safe synthetic regression for an exported multi-role
 * account. Never embed the exported user's identity or exact role fingerprint.
 *
 * Live account state and finance/procurement maker-checker transactions still
 * require independent, explicitly authorized isolated acceptance evidence.
 */
final class V2ExportedMultiroleAccountAuditTest extends TestCase
{
    use RefreshDatabase;

    private const REPRESENTATIVE_ROLES = [
        'INVENTORY',
        'PROCUREMENT_MANAGER',
        'ACCOUNTING_MANAGER',
        'TREASURY_OFFICER',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_mixed_wholesale_roles_do_not_grant_platform_or_retail_navigation(): void
    {
        $wholesaleStore = $this->store('B2B', 'V2-2925-WHOLESALE');
        $retailStore = $this->store('B2C', 'V2-2925-RETAIL');
        $actor = $this->syntheticActor();

        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertFalse($actor->hasRole('PLATFORM_OWNER'));
        $this->assertFalse($actor->hasPermission('roles.manage'));
        $this->assertFalse($actor->hasPermission('users.delete'));

        $inherited = $actor->inheritedPermissionCodes();
        $this->assertCount(count(array_unique($inherited)), $inherited);
        $this->assertContains('inventory.view', $inherited);
        $this->assertNotContains('roles.manage', $inherited);
        $this->assertTrue($actor->hasPermission('inventory.view', $wholesaleStore));
        $this->assertFalse($actor->hasPermission('inventory.view', $retailStore));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $keys = $this->navigationKeys(app(AdminNavigation::class)->groupsFor($actor));
            $this->assertNotContains('sidebar_role_access', $keys);
            $this->assertNotContains('b2c_inventory', $keys);

            $this->actingAs($actor)->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();

            $retail = $this->actingAs($actor)->get(route(
                'admin.b2c.module',
                ['module' => 'inventory', 'store_id' => $retailStore],
            ));
            $this->assertContains($retail->getStatusCode(), [403, 404]);
        }
    }

    public function test_role_union_cannot_override_explicit_global_deny_or_inactive_account_scope(): void
    {
        $wholesaleStore = $this->store('B2B', 'V2-2925-DENIED');
        $actor = $this->syntheticActor();
        $scope = app(OperationalTenantScope::class);

        $this->assertContains(
            $wholesaleStore,
            $scope->allowedStoreIds($actor, 'inventory.view', 'b2b'),
        );

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', 'inventory.view')->firstOrFail()->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertFalse($actor->hasPermission('inventory.view', $wholesaleStore));
            $this->assertSame([], $scope->allowedStoreIds($actor, 'inventory.view', 'b2b'));

            $response = $this->actingAs($actor)->get(route('admin.b2b.module', [
                'module' => 'inventory',
            ]));
            $this->assertContains($response->getStatusCode(), [403, 404]);
        }

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->getKey(),
            'effect' => 'deny',
            'scope_key' => 'global',
        ]);

        $actor->forceFill(['is_active' => false])->save();
        $this->assertSame([], $scope->allowedStoreIds($actor->fresh(), 'inventory.view', 'b2b'));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor->fresh()));
    }

    private function syntheticActor(): User
    {
        $roles = Role::query()->whereIn('code', self::REPRESENTATIVE_ROLES)->get();
        $this->assertCount(count(self::REPRESENTATIVE_ROLES), $roles);

        foreach ($roles as $role) {
            $this->assertSame('global', $role->scope);
            $this->assertTrue((bool) $role->is_active);
        }

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->sync($roles->modelKeys());

        return $actor->fresh();
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

    /** @return list<string> */
    private function navigationKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ($group['children'] as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
