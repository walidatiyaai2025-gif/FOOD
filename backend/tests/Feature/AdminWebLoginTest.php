<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminWebLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_guest_can_open_bilingual_b2b_and_b2c_management_login_entries(): void
    {
        $this->get('/admin/b2b/login?locale=ar')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('إدارة الجملة B2B')
            ->assertSee('name="_token"', false)
            ->assertDontSee('إنشاء حساب');

        $this->get('/admin/b2c/login?locale=en')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('B2C Retail Management')
            ->assertSee('There is no public registration');
    }

    public function test_b2b_admin_login_uses_web_session_and_enters_only_wholesale_channel(): void
    {
        $user = $this->userWithGlobalRole('B2B_ADMIN', 'en');

        $this->post('/admin/b2b/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])->assertRedirect(route('admin.b2b.dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->get('/admin/b2b/dashboard')->assertOk();
        $this->get('/admin/b2c/dashboard')->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'management.login.succeeded',
        ]);
    }

    public function test_b2c_store_admin_login_enters_only_retail_channel(): void
    {
        $storeId = $this->createB2cStore();
        $user = User::query()->create([
            'name' => 'B2C Login Admin',
            'email' => 'b2c-login-admin@example.test',
            'password' => 'correct-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $this->assignStoreRole($user, $storeId, 'B2C_STORE_ADMIN');

        $this->post('/admin/b2c/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'locale' => 'ar',
        ])->assertRedirect(route('admin.b2c.dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->get('/admin/b2c/dashboard')->assertOk();
        $this->get('/admin/b2b/dashboard')->assertForbidden();
    }

    public function test_login_redirects_to_the_only_channel_the_identity_is_authorized_for(): void
    {
        $user = $this->userWithGlobalRole('B2B_ADMIN');

        $this->post('/admin/b2c/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.b2b.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_and_non_management_identities_are_denied_without_creating_session(): void
    {
        $inactive = $this->userWithGlobalRole('B2B_ADMIN');
        $inactive->update(['is_active' => false]);

        $this->post('/admin/b2b/login', [
            'email' => $inactive->email,
            'password' => 'correct-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $driver = $this->userWithGlobalRole('B2C_DRIVER');

        $this->post('/admin/b2c/login', [
            'email' => $driver->email,
            'password' => 'correct-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_validation_and_public_b2b_registration_boundary_are_preserved(): void
    {
        $this->post('/admin/b2b/login', [
            'email' => 'not-an-email',
            'password' => '',
        ])->assertSessionHasErrors(['email', 'password']);

        $this->post('/admin/b2b/register')->assertStatus(405);
        $this->post('/api/v1/b2b/register')->assertNotFound();
    }

    public function test_authenticated_management_user_can_logout_and_session_is_invalidated(): void
    {
        $user = $this->userWithGlobalRole('SUPER_ADMIN');

        $this->actingAs($user)
            ->post('/admin/logout')
            ->assertRedirect(route('admin.b2c.login'));

        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'management.logout',
        ]);
    }

    private function userWithGlobalRole(string $roleCode, string $locale = 'ar'): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-web-login-'.uniqid().'@example.test',
            'password' => 'correct-password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function createB2cStore(): int
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'B2C-WEB-LOGIN',
            'name' => 'B2C Web Login Store',
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
