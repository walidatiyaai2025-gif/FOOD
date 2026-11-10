<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P081: permission state badges describe authoritative effective RBAC, not
 * presentation-only grants or alternative authorization decisions.
 */
final class V2PagePermissionStatesContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_global_role_inheritance_allow_deny_and_blocked_are_visible_in_both_locales(): void
    {
        $admin = $this->superAdmin();
        $permissionCodes = ['orders.view', 'orders.manage', 'orders.dispatch', 'notifications.view'];
        $permissions = Permission::query()->whereIn('code', $permissionCodes)->get()->keyBy('code');
        $this->assertCount(count($permissionCodes), $permissions);

        $role = Role::query()->create([
            'code' => 'V2_P081_REVIEWER',
            'name' => 'P081 role',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach([
            $permissions['orders.view']->id,
            $permissions['orders.manage']->id,
        ]);

        $target = User::factory()->create(['name' => 'P081 Global Target', 'is_active' => true, 'locale' => 'en']);
        $target->roles()->attach($role->id);
        $this->override($target, $permissions['orders.manage'], 'global', 'deny');
        $this->override($target, $permissions['orders.dispatch'], 'global', 'allow');

        $this->assertTrue($target->fresh()->hasPermission('orders.view'));
        $this->assertFalse($target->fresh()->hasPermission('orders.manage'));
        $this->assertTrue($target->fresh()->hasPermission('orders.dispatch'));
        $this->assertFalse($target->fresh()->hasPermission('notifications.view'));

        $expected = [
            'orders.view' => 'inherited',
            'orders.manage' => 'deny',
            'orders.dispatch' => 'allow',
            'notifications.view' => 'blocked',
        ];

        foreach (['en' => 'Inherited from role', 'ar' => 'موروثة من الدور'] as $locale => $translatedState) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', ['section' => 'users', 'q' => 'P081 Global Target']))
                ->assertOk()
                ->assertSee($translatedState);

            foreach ($expected as $code => $state) {
                $this->assertSame(
                    $state,
                    $this->stateForPermission((string) $response->getContent(), (int) $permissions[$code]->id),
                    $locale.': '.$code,
                );
            }
        }

        $this->assertSame(2, DB::table('user_permission_overrides')->where('user_id', $target->id)->count());
    }

    public function test_store_deny_does_not_change_another_store_and_global_deny_blocks_local_allow(): void
    {
        $admin = $this->superAdmin();
        $first = $this->retailStore('V2-P081-STORE-A');
        $second = $this->retailStore('V2-P081-STORE-B');
        $target = User::factory()->create(['name' => 'P081 Store Target', 'is_active' => true, 'locale' => 'en']);
        $roleId = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id;

        foreach ([$first, $second] as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $target->id,
                'store_id' => $storeId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permission = Permission::query()->where('code', 'inventory.view')->firstOrFail();
        $this->override($target, $permission, 'store:'.$first, 'deny', $first);

        $this->assertFalse($target->fresh()->hasPermission('inventory.view', $first));
        $this->assertTrue($target->fresh()->hasPermission('inventory.view', $second));

        $html = (string) $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users', 'q' => 'P081 Store Target']))
            ->assertOk()
            ->getContent();

        $this->assertSame('deny', $this->stateForPermission($this->storeForm($html, $first), (int) $permission->id));
        $this->assertSame('inherited', $this->stateForPermission($this->storeForm($html, $second), (int) $permission->id));

        $this->override($target, $permission, 'global', 'deny');
        $this->assertFalse($target->fresh()->hasPermission('inventory.view', $second));

        $html = (string) $this->actingAs($admin->fresh())
            ->get(route('admin.security.index', ['section' => 'users', 'q' => 'P081 Store Target']))
            ->assertOk()
            ->getContent();

        $this->assertSame('deny', $this->stateForPermission($this->storeForm($html, $second), (int) $permission->id));
        $this->assertSame(2, DB::table('user_permission_overrides')->where('user_id', $target->id)->count());
    }

    public function test_security_center_is_not_visible_to_an_unauthorized_actor(): void
    {
        $outsider = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($outsider)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertForbidden();
    }

    private function stateForPermission(string $html, int $permissionId): ?string
    {
        $pattern = '/<label class="permission-option"[^>]*data-permission-state="([^"]+)"[^>]*>\s*<input[^>]*value="'.$permissionId.'"/s';

        return preg_match($pattern, $html, $match) === 1 ? $match[1] : null;
    }

    private function storeForm(string $html, int $storeId): string
    {
        $pattern = '/<input type="hidden" name="store_id" value="'.$storeId.'">(.+?)<\/form>/s';
        $this->assertSame(1, preg_match($pattern, $html, $match), 'Store permission form is absent.');

        return $match[1];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        return $user;
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

    private function override(
        User $user,
        Permission $permission,
        string $scopeKey,
        string $effect,
        ?int $storeId = null,
    ): void {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => $effect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->forceFill(['has_permission_overrides' => true])->save();
    }
}
