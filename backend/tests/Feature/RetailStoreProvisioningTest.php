<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RetailStoreProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_provision_store_and_manager_then_revocation_blocks_access(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'owner@example.test');

        $response = $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'code' => 'SHOP-A',
            'name' => 'Shop A',
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Shop A Manager',
            'manager_email' => 'manager-a@example.test',
            'manager_password' => 'password123',
        ]);

        $response->assertRedirect(route('admin.retail-stores.index'));
        $storeId = (int) DB::table('stores')->where('code', 'SHOP-A')->value('id');
        $manager = User::query()->where('email', 'manager-a@example.test')->firstOrFail();
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        $this->assertDatabaseHas('user_store_roles', ['user_id' => $manager->id, 'store_id' => $storeId, 'role_id' => $roleId]);
        $this->assertSame([$storeId], app(TenantContextResolver::class)->retailStoreIds($manager));

        $assignmentId = (int) DB::table('user_store_roles')->where('user_id', $manager->id)->where('store_id', $storeId)->value('id');
        $this->actingAs($admin)->delete(route('admin.retail-stores.roles.remove', [$storeId, $assignmentId]))->assertRedirect();
        $this->assertSame([], app(TenantContextResolver::class)->retailStoreIds($manager));
    }

    public function test_non_super_admin_cannot_provision_retail_store(): void
    {
        $b2b = $this->userWithRole('B2B_ADMIN', 'b2b@example.test');

        $this->actingAs($b2b)->get(route('admin.retail-stores.index'))->assertForbidden();
        $this->actingAs($b2b)->post(route('admin.retail-stores.store'), [
            'code' => 'FORBIDDEN',
            'name' => 'Forbidden',
            'manager_mode' => 'existing',
            'manager_user_id' => $b2b->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('stores', ['code' => 'FORBIDDEN']);
    }

    public function test_store_admin_cannot_access_another_store_and_deactivation_revokes_context(): void
    {
        $storeA = $this->retailStore('A');
        $storeB = $this->retailStore('B');
        $manager = $this->user('manager@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $manager->id, 'store_id' => $storeA, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resolver = app(TenantContextResolver::class);
        $this->assertSame($storeA, $resolver->retail($manager, $storeA)->storeId);

        try {
            $resolver->retail($manager, $storeB);
            $this->fail('Foreign store context must be denied.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        DB::table('stores')->where('id', $storeA)->update(['is_active' => false]);

        try {
            $resolver->retail($manager, $storeA);
            $this->fail('Deactivated store must not resolve.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_super_admin_inspect_entry_is_explicit_and_audited(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'support@example.test');
        $storeId = $this->retailStore('SUPPORT');

        $this->actingAs($admin)->post(route('admin.retail-stores.inspect', $storeId))
            ->assertRedirect(route('admin.b2c.dashboard', ['store_id' => $storeId, 'support_access' => 1]));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'tenant.support_access.entered',
            'auditable_id' => $storeId,
        ]);
    }

    private function userWithRole(string $role, string $email): User
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
            'code' => "TEST-{$code}",
            'name' => "Test {$code}",
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
