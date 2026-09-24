<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_guest_cannot_open_management_dashboard(): void
    {
        $this->get('/admin')->assertUnauthorized();
    }

    public function test_b2b_admin_sees_only_wholesale_channel(): void
    {
        $user = $this->userWithGlobalRole('B2B_ADMIN');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('إدارة الجملة B2B')
            ->assertDontSee('إدارة التجزئة B2C');

        $this->actingAs($user)
            ->get('/admin/b2b/dashboard')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/b2c/dashboard')
            ->assertForbidden();
    }

    public function test_b2c_store_admin_sees_only_retail_channel(): void
    {
        $storeId = $this->createB2cStore();
        $user = User::query()->create([
            'name' => 'B2C Admin',
            'email' => 'b2c-admin-shell@example.test',
            'password' => 'password',
            'locale' => 'ar',
        ]);

        $this->assignStoreRole($user, $storeId, 'B2C_STORE_ADMIN');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('إدارة التجزئة B2C')
            ->assertDontSee('إدارة الجملة B2B');

        $this->actingAs($user)
            ->get('/admin/b2c/dashboard')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/b2b/dashboard')
            ->assertForbidden();
    }

    public function test_super_admin_uses_one_shell_for_both_channels(): void
    {
        $user = $this->userWithGlobalRole('SUPER_ADMIN');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('إدارة الجملة B2B')
            ->assertSee('إدارة التجزئة B2C')
            ->assertSee('href="'.route('admin.b2b.dashboard').'"', false)
            ->assertSee('href="'.route('admin.b2c.dashboard').'"', false);
    }

    public function test_driver_role_is_denied_management_dashboard_access(): void
    {
        $driver = $this->userWithGlobalRole('B2C_DRIVER');

        $this->actingAs($driver)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_user_locale_controls_shell_direction_and_copy(): void
    {
        $user = $this->userWithGlobalRole('B2B_ADMIN', 'en');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('FOODEX Management Dashboard');
    }

    private function userWithGlobalRole(string $roleCode, string $locale = 'ar'): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-shell@example.test',
            'password' => 'password',
            'locale' => $locale,
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
            'code' => 'B2C-SHELL',
            'name' => 'B2C Shell Store',
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
