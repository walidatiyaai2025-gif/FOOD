<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2UserCreateEditAccountScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_creates_explicit_global_staff_scope_without_extra_grants_and_preexisting_clients_still_work(): void
    {
        $admin = $this->actor();
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();
        $payload = [
            'name' => 'Staff Global',
            'username' => 'staff.global',
            'email' => 'staff.global@example.test',
            'password' => 'SecurePass!2026',
            'password_confirmation' => 'SecurePass!2026',
            'locale' => 'en',
            'role_id' => $role->id,
            'is_active' => 1,
        ];
        $this->actingAs($admin)->post(route('admin.security.users.store', [], false), [
            ...$payload,
            'account_scope' => 'global',
        ])->assertRedirect(route('admin.security.index', ['section' => 'users'], false));

        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertTrue($user->hasRole('CUSTOMER_SERVICE_AGENT'));
        $this->assertSame(0, $user->storeRoleAssignments()->count());
        $this->assertFalse((bool) $user->is_platform_customer);

        unset($payload['username']);
        $payload['email'] = 'legacy.staff@example.test';
        $this->actingAs($admin)->post(route('admin.security.users.store', [], false), $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'legacy.staff@example.test']);
    }

    public function test_retail_store_scope_requires_store_role_and_rejects_mismatched_wholesale_selection_atomically(): void
    {
        $admin = $this->actor();
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'P079-STORE',
            'name' => 'P079 Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = [
            'name' => 'Retail Staff',
            'email' => 'retail.p079@example.test',
            'password' => 'SecurePass!2026',
            'password_confirmation' => 'SecurePass!2026',
            'locale' => 'ar',
            'store_role_assignment' => $storeId.':'.$role->id,
            'is_active' => 1,
            'account_scope' => 'store',
        ];
        $this->actingAs($admin)->post(route('admin.security.users.store', [], false), $payload)
            ->assertRedirect();
        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertTrue($user->hasRole('B2C_STORE_ADMIN', $storeId));
        $this->assertFalse($user->hasRole('B2C_STORE_ADMIN'));
        $this->assertDatabaseHas('user_store_roles', ['user_id' => $user->id, 'store_id' => $storeId]);

        $payload['email'] = 'wrong.p079@example.test';
        $payload['account_scope'] = 'global';
        $this->actingAs($admin)->from(route('admin.security.index', ['section' => 'users'], false))
            ->post(route('admin.security.users.store', [], false), $payload)
            ->assertSessionHasErrors('account_scope');
        $this->assertDatabaseMissing('users', ['email' => 'wrong.p079@example.test']);
    }

    public function test_identity_edit_audits_reason_preserves_roles_and_revokes_login_sessions_when_email_changes(): void
    {
        $admin = $this->actor();
        $target = User::factory()->create([
            'name' => 'Old Staff',
            'email' => 'old.p079@example.test',
            'locale' => 'ar',
            'is_platform_customer' => false,
        ]);
        $target->roles()->attach(Role::query()->where('code', 'B2B_ACCOUNT_MANAGER')->firstOrFail());
        $token = $target->createToken('old-access')->plainTextToken;
        $this->assertNotSame('', $token);
        $this->assertNotNull($target->email_verified_at);

        $this->actingAs($admin)->patch(route('admin.security.users.profile', $target, false), [
            'name' => 'New Staff',
            'username' => 'new.p079',
            'email' => 'new.p079@example.test',
            'locale' => 'en',
            'change_reason' => 'Verified employee identity correction',
        ])->assertRedirect();
        $fresh = $target->fresh();
        $this->assertSame('New Staff', $fresh->name);
        $this->assertSame('new.p079@example.test', $fresh->email);
        $this->assertSame('en', $fresh->locale);
        $this->assertNull($fresh->email_verified_at);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertTrue($fresh->hasRole('B2B_ACCOUNT_MANAGER'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.profile_updated',
            'user_id' => $admin->id,
            'auditable_id' => $target->id,
        ]);
        $audit = DB::table('audit_logs')->where('event', 'user.profile_updated')->where('auditable_id', $target->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('Verified employee identity correction', (string) $audit->after);
        $this->assertStringNotContainsString('SecurePass!2026', (string) $audit->after);
    }

    public function test_username_only_login_change_revokes_existing_tokens_without_resetting_verified_email(): void
    {
        $admin = $this->actor();
        $target = User::factory()->create([
            'username' => 'old.p079.user',
            'is_platform_customer' => false,
        ]);
        $target->createToken('original-client');
        $this->assertNotNull($target->email_verified_at);

        $this->actingAs($admin)->patch(route('admin.security.users.profile', $target, false), [
            'name' => $target->name,
            'username' => 'new.p079.user',
            'email' => $target->email,
            'locale' => $target->locale,
            'change_reason' => 'Authorized username identity correction',
        ])->assertRedirect();

        $fresh = $target->fresh();
        $this->assertSame('new.p079.user', $fresh->username);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertSame(0, $fresh->tokens()->count());
    }

    public function test_protected_super_admin_customer_and_delegated_manager_cannot_edit_identity(): void
    {
        $admin = $this->actor();
        $protected = User::factory()->create();
        $protected->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $customer = User::factory()->create(['is_platform_customer' => true]);
        $delegated = User::factory()->create();
        $role = Role::query()->create(['code' => 'P079_DELEGATE', 'name' => 'Delegated', 'scope' => 'global', 'is_active' => true, 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->where('code', 'users.roles.manage')->firstOrFail());
        $delegated->roles()->attach($role);

        $payload = ['name' => 'Hijacked', 'username' => 'bad.p079', 'email' => 'hijacked.p079@example.test', 'locale' => 'en', 'change_reason' => 'Illicit profile takeover attempt'];
        foreach ([$protected, $customer] as $target) {
            $this->actingAs($admin)->patch(route('admin.security.users.profile', $target, false), $payload)->assertForbidden();
            $this->assertSame($target->name, $target->fresh()->name);
        }
        $normal = User::factory()->create(['is_platform_customer' => false]);
        $this->actingAs($delegated)->patch(route('admin.security.users.profile', $normal, false), $payload)->assertForbidden();
        $this->assertNotSame('Hijacked', $normal->fresh()->name);
    }

    public function test_profile_change_requires_reason_and_bilingual_security_center_declares_account_channel(): void
    {
        $admin = $this->actor();
        $target = User::factory()->create(['is_platform_customer' => false]);
        $this->actingAs($admin)->patch(route('admin.security.users.profile', $target, false), [
            'name' => 'No reason', 'username' => 'new.name', 'email' => $target->email, 'locale' => 'en',
        ])->assertSessionHasErrors('change_reason');
        $this->assertSame($target->name, $target->fresh()->name);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $dir) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin)->get(route('admin.security.index', ['section' => 'users'], false))->assertOk()
                ->assertSee(__('admin.security.account_scope_title'))
                ->assertSee(__('admin.security.edit_user_profile'))
                ->assertSee('data-security-edit-profile', false)
                ->assertSee('name="account_scope"', false);
            $this->assertStringContainsString('dir="'.$dir.'"', (string) $response->getContent());
        }
    }

    private function actor(): User
    {
        $admin = User::factory()->create(['locale' => 'en', 'is_active' => true, 'is_platform_customer' => false]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $admin;
    }
}
