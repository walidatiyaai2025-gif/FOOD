<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2cTenantWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_single_assigned_store_is_selected_automatically(): void
    {
        $storeId = $this->retailStore('AUTO');
        $manager = $this->storeManager('single@example.test', [$storeId]);

        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'products']))
            ->assertOk()
            ->assertViewHas('storeId', $storeId)
            ->assertViewHas('storeIds', [$storeId])
            ->assertViewHas('supportAccess', false);
    }

    public function test_multi_store_manager_can_switch_only_between_assigned_stores(): void
    {
        $storeA = $this->retailStore('A');
        $storeB = $this->retailStore('B');
        $foreignStore = $this->retailStore('FOREIGN');
        $manager = $this->storeManager('multi@example.test', [$storeA, $storeB]);

        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $storeB]))
            ->assertOk()
            ->assertViewHas('storeId', $storeB)
            ->assertViewHas('storeIds', [$storeB])
            ->assertViewHas('availableStores', function (array $stores) use ($storeA, $storeB): bool {
                $ids = collect($stores)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

                return $ids === collect([$storeA, $storeB])->sort()->values()->all();
            });

        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $foreignStore]))
            ->assertNotFound();
    }

    public function test_super_admin_retail_workspace_requires_explicit_support_entry(): void
    {
        $storeId = $this->retailStore('SUPPORT');
        $admin = $this->globalUser('SUPER_ADMIN', 'support@example.test');

        $this->actingAs($admin)
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $storeId]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.b2c.module', [
                'module' => 'products',
                'store_id' => $storeId,
                'support_access' => 1,
            ]))
            ->assertOk()
            ->assertViewHas('storeId', $storeId)
            ->assertViewHas('supportAccess', true);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'tenant.support_access.entered',
            'auditable_id' => $storeId,
        ]);
    }

    public function test_b2b_admin_cannot_enter_retail_workspace(): void
    {
        $storeId = $this->retailStore('RETAIL');
        $admin = $this->globalUser('B2B_ADMIN', 'b2b@example.test');

        $this->actingAs($admin)
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $storeId]))
            ->assertForbidden();
    }

    /** @param list<int> $storeIds */
    private function storeManager(string $email, array $storeIds): User
    {
        $user = $this->user($email);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $user->id,
                'store_id' => $storeId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function globalUser(string $role, string $email): User
    {
        $user = $this->user($email);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'MT07-'.$code,
            'name' => 'MT07 '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
