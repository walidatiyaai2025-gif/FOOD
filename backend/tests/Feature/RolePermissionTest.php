<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_reference_roles_and_permissions_are_seeded(): void
    {
        foreach (array_keys((array) config('permissions.roles')) as $roleCode) {
            $this->assertDatabaseHas('roles', ['code' => $roleCode]);
        }

        foreach (array_keys((array) config('permissions.abilities')) as $permissionCode) {
            $this->assertDatabaseHas('permissions', ['code' => $permissionCode]);
        }
    }

    public function test_b2b_and_b2c_admin_permissions_remain_separate(): void
    {
        $b2bAdmin = $this->userWithGlobalRole('B2B_ADMIN');

        $this->assertTrue(Gate::forUser($b2bAdmin)->allows('b2b.accounts.manage'));
        $this->assertTrue(Gate::forUser($b2bAdmin)->allows('drivers.b2b.manage'));
        $this->assertFalse(Gate::forUser($b2bAdmin)->allows('drivers.b2c.manage'));

        $storeId = $this->createB2cStore();
        $b2cAdmin = User::query()->create([
            'name' => 'B2C Admin',
            'email' => 'b2c-admin@example.test',
            'password' => 'password',
        ]);

        $this->assignStoreRole($b2cAdmin, $storeId, 'B2C_STORE_ADMIN');

        $this->assertTrue(Gate::forUser($b2cAdmin)->allows('drivers.b2c.manage', [$storeId]));
        $this->assertFalse(Gate::forUser($b2cAdmin)->allows('drivers.b2c.manage'));
        $this->assertFalse(Gate::forUser($b2cAdmin)->allows('drivers.b2b.manage', [$storeId]));
        $this->assertFalse(Gate::forUser($b2cAdmin)->allows('b2b.pricing.manage', [$storeId]));
    }

    public function test_b2b_and_b2c_driver_execution_permissions_remain_separate(): void
    {
        $b2bDriver = $this->userWithGlobalRole('B2B_DRIVER');
        $b2cDriver = $this->userWithGlobalRole('B2C_DRIVER');

        $this->assertTrue(Gate::forUser($b2bDriver)->allows('deliveries.b2b.execute'));
        $this->assertFalse(Gate::forUser($b2bDriver)->allows('deliveries.b2c.execute'));

        $this->assertTrue(Gate::forUser($b2cDriver)->allows('deliveries.b2c.execute'));
        $this->assertFalse(Gate::forUser($b2cDriver)->allows('deliveries.b2b.execute'));
    }

    public function test_super_admin_has_every_declared_permission(): void
    {
        $superAdmin = $this->userWithGlobalRole('SUPER_ADMIN');

        foreach (array_keys((array) config('permissions.abilities')) as $ability) {
            $this->assertTrue(
                Gate::forUser($superAdmin)->allows($ability),
                "Super Admin must be allowed to perform {$ability}.",
            );
        }
    }

    public function test_role_without_permission_is_denied_server_side(): void
    {
        $finance = $this->userWithGlobalRole('FINANCE');

        $this->assertTrue(Gate::forUser($finance)->allows('finance.manage'));
        $this->assertTrue(Gate::forUser($finance)->allows('reports.view'));
        $this->assertFalse(Gate::forUser($finance)->allows('catalog.manage'));
        $this->assertFalse(Gate::forUser($finance)->allows('platform.manage'));
    }

    private function userWithGlobalRole(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'@example.test',
            'password' => 'password',
        ]);

        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }

    private function createB2cStore(): int
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'B2C-TEST',
            'name' => 'B2C Test Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignStoreRole(User $user, int $storeId, string $roleCode): void
    {
        $roleId = (int) Role::query()->where('code', $roleCode)->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
