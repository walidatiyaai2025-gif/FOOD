<?php

namespace Tests\Feature;

use App\Models\AdminSecuritySession;
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

    public function test_home_always_redirects_to_management_login(): void
    {
        $this->get('/')
            ->assertRedirect(route('admin.b2c.login'));

        $user = $this->userWithGlobalRole('SUPER_ADMIN');
        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('admin.b2c.login'));
    }

    public function test_guest_opening_admin_is_redirected_to_management_login(): void
    {
        $this->get('/admin')
            ->assertRedirect(route('admin.b2c.login'));
    }

    public function test_guest_can_open_bilingual_b2b_and_b2c_management_login_entries(): void
    {
        $this->get('/admin/b2b/login?locale=ar')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('إدارة الجملة')
            ->assertSee('name="_token"', false)
            ->assertDontSee('إنشاء حساب');

        $this->get('/admin/b2c/login?locale=en')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Retail Management')
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

    public function test_dual_channel_non_super_user_prefers_the_requested_retail_login_channel(): void
    {
        $storeId = $this->createB2cStore();
        $user = $this->userWithGlobalRole('B2B_ADMIN', 'en');
        $this->assignStoreRole($user, $storeId, 'B2C_STORE_ADMIN');

        $this->post('/admin/b2c/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])->assertRedirect(route('admin.b2c.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_distinct_management_accounts_behind_one_ip_do_not_trigger_global_lockout_during_normal_admin_testing(): void
    {
        $user = $this->userWithGlobalRole('B2B_ADMIN', 'en');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60']);

        for ($attempt = 1; $attempt <= 25; $attempt++) {
            $this->post('/admin/b2b/login', [
                'email' => "unknown-{$attempt}@example.test",
                'password' => 'wrong-password',
                'locale' => 'en',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/admin/b2b/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])->assertRedirect(route('admin.b2b.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_per_identifier_management_login_throttle_remains_strict(): void
    {
        $user = $this->userWithGlobalRole('B2B_ADMIN', 'en');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.61']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/admin/b2b/login', [
                'email' => '  '.strtoupper($user->email).'  ',
                'password' => 'wrong-password',
                'locale' => 'en',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/admin/b2b/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])->assertStatus(429);

        $this->assertGuest();
    }

    public function test_failed_post_auth_session_completion_clears_the_authenticated_state(): void
    {
        $user = $this->userWithGlobalRole('B2B_ADMIN', 'en');
        $revoked = AdminSecuritySession::query()->create([
            'user_id' => $user->id,
            'session_hash' => hash('sha256', 'revoked-login-session'),
            'ip_address' => '203.0.113.62',
            'user_agent' => 'Feature test',
            'last_seen_at' => now(),
            'revoked_at' => now(),
            'revoked_by' => $user->id,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.62'])
            ->withSession(['admin_security_session_public_id' => $revoked->public_id])
            ->post('/admin/b2b/login', [
                'email' => $user->email,
                'password' => 'correct-password',
                'locale' => 'en',
            ])
            ->assertForbidden();

        $this->assertGuest();
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

    public function test_customer_service_manager_login_always_lands_on_b2b_dashboard(): void
    {
        $user = $this->userWithGlobalRole('CUSTOMER_SERVICE_MANAGER');

        $this->post('/admin/b2b/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.b2b.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_customer_service_agent_login_always_lands_on_b2b_dashboard(): void
    {
        $user = $this->userWithGlobalRole('CUSTOMER_SERVICE_AGENT');

        $this->post('/admin/b2b/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.b2b.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_customer_service_dashboard_roles_ignore_stale_intended_workspace_after_login(): void
    {
        foreach (['CUSTOMER_SERVICE_MANAGER', 'CUSTOMER_SERVICE_AGENT'] as $roleCode) {
            $user = $this->userWithGlobalRole($roleCode);
            $intended = route('admin.erp.customer-service');

            $this->withSession(['url.intended' => $intended])
                ->post('/admin/b2b/login', [
                    'email' => $user->email,
                    'password' => 'correct-password',
                ])
                ->assertRedirect(route('admin.b2b.dashboard'));

            $this->post('/admin/logout')->assertRedirect(route('admin.b2c.login'));
            $this->assertGuest();
        }
    }

    public function test_operations_login_lands_on_order_operations_workspace(): void
    {
        $user = $this->userWithGlobalRole('OPERATIONS');

        $this->post('/admin/b2b/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.operations.orders.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_retail_inventory_login_lands_on_inventory_workspace(): void
    {
        $storeId = $this->createB2cStore();
        $user = User::query()->create([
            'name' => 'Retail Inventory',
            'email' => 'retail-inventory-'.uniqid().'@example.test',
            'password' => 'correct-password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->assignStoreRole($user, $storeId, 'RETAIL_INVENTORY');

        $this->post('/admin/b2c/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.b2c.module', ['module' => 'inventory']));

        $this->assertAuthenticatedAs($user);
    }

    public function test_mfa_preserves_the_role_aware_workspace_as_the_post_challenge_target(): void
    {
        config()->set('security.mfa.enforce_privileged', true);

        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail();
        $permissionId = (int) DB::table('permissions')->where('code', 'platform.manage')->value('id');
        DB::table('permission_role')->insertOrIgnore([
            'permission_id' => $permissionId,
            'role_id' => $role->id,
        ]);

        $user = $this->userWithGlobalRole('CUSTOMER_SERVICE_MANAGER');

        $this->post('/admin/b2b/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])
            ->assertRedirect(route('admin.mfa.enroll'))
            ->assertSessionHas('mfa_intended', route('admin.b2b.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_super_admin_normal_login_defaults_to_b2b_dashboard(): void
    {
        $user = $this->userWithGlobalRole('SUPER_ADMIN');

        $this->post('/admin/b2c/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.b2b.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_admin_request_records_intended_url_for_post_login_return(): void
    {
        $intended = url('/admin/b2b/orders');

        $this->get('/admin/b2b/orders')
            ->assertRedirect(route('admin.b2c.login'))
            ->assertSessionHas('url.intended', $intended);
    }

    public function test_login_preserves_authorized_internal_intended_url(): void
    {
        $user = $this->userWithGlobalRole('B2B_ADMIN');
        $intended = route('admin.b2b.module', ['module' => 'orders']);

        $this->withSession(['url.intended' => $intended])
            ->post('/admin/b2c/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertRedirect($intended);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_cross_channel_or_external_intended_url(): void
    {
        $user = $this->userWithGlobalRole('B2B_ADMIN');

        $this->withSession(['url.intended' => route('admin.b2c.dashboard')])
            ->post('/admin/b2c/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertRedirect(route('admin.b2b.dashboard'));

        $this->post('/admin/logout')->assertRedirect(route('admin.b2c.login'));
        $this->assertGuest();

        $this->withSession(['url.intended' => 'https://example.invalid/admin/b2b/dashboard'])
            ->post('/admin/b2b/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertRedirect(route('admin.b2b.dashboard'));
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

    public function test_login_validation_errors_render_field_associations_and_preserve_entered_email(): void
    {
        $response = $this->followingRedirects()
            ->from('/admin/b2b/login?locale=en')
            ->post('/admin/b2b/login', [
                'email' => 'not-an-email',
                'password' => '',
                'locale' => 'en',
            ]);

        $response
            ->assertOk()
            ->assertSee('value="not-an-email"', false)
            ->assertSee('data-foodex-native-error-summary', false)
            ->assertSee('data-foodex-error-summary-key="email"', false)
            ->assertSee('data-foodex-error-summary-key="password"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="foodex-server-error-email"', false)
            ->assertSee('aria-errormessage="foodex-server-error-email"', false)
            ->assertSee('id="foodex-server-error-email"', false)
            ->assertSee('aria-describedby="foodex-server-error-password"', false)
            ->assertSee('aria-errormessage="foodex-server-error-password"', false)
            ->assertSee('id="foodex-server-error-password"', false);
    }

    public function test_invalid_credentials_keep_generic_non_enumerating_email_error_association(): void
    {
        $response = $this->followingRedirects()
            ->from('/admin/b2c/login?locale=en')
            ->post('/admin/b2c/login', [
                'email' => 'unknown-user@example.test',
                'password' => 'wrong-password',
                'locale' => 'en',
            ]);

        $response
            ->assertOk()
            ->assertSee(__('admin_login.invalid_credentials'))
            ->assertSee('data-foodex-error-summary-key="email"', false)
            ->assertSee('aria-describedby="foodex-server-error-email"', false)
            ->assertDontSee('data-foodex-error-summary-key="password"', false);
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
