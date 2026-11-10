<?php

namespace Tests\Feature;

use App\Models\AdminMfaCredential;
use App\Models\AdminSecuritySession;
use App\Models\EnterpriseSsoIdentity;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformOwnerProvisioner;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SensitivePlatformOwnerBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_an_unregistered_owner_role_cannot_impersonate_privileged_management_authority(): void
    {
        $impostor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $impostor->roles()->attach(Role::query()->where('code', 'PLATFORM_OWNER')->firstOrFail());

        $this->assertFalse($impostor->isPlatformOwner());
        $this->assertFalse($impostor->hasRole('SUPER_ADMIN'));

        $this->actingAs($impostor)
            ->get(route('admin.security.index'))
            ->assertForbidden();

        $this->actingAs($impostor)
            ->get(route('admin.system-update.index'))
            ->assertForbidden();

        $this->assertDatabaseEmpty('platform_owner_registry');
    }

    public function test_super_admin_cannot_assign_owner_role_or_mutate_registered_owner_roles_status_or_permissions(): void
    {
        $superAdmin = $this->superAdmin();
        $owner = $this->registeredOwner();
        $ownerRole = Role::query()->where('code', 'PLATFORM_OWNER')->firstOrFail();
        $ordinaryUser = User::factory()->create(['is_active' => true]);

        $this->actingAs($superAdmin)->post(route('admin.security.users.store'), [
            'name' => 'Unapproved Owner',
            'username' => 'unapproved.owner',
            'email' => 'unapproved-owner@example.test',
            'password' => 'StrongTestSecret2026!',
            'password_confirmation' => 'StrongTestSecret2026!',
            'locale' => 'en',
            'role_id' => $ownerRole->getKey(),
            'is_active' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'unapproved-owner@example.test']);

        $this->actingAs($superAdmin)
            ->put(route('admin.security.users.roles', $ordinaryUser), [
                'global_role_ids' => [$ownerRole->getKey()],
            ])->assertForbidden();

        $this->actingAs($superAdmin)
            ->put(route('admin.security.users.roles', $owner), [
                'global_role_ids' => [],
            ])->assertForbidden();

        $this->actingAs($superAdmin)
            ->patch(route('admin.security.users.status', $owner), [
                'is_active' => false,
                'reason' => 'Unauthorized owner deactivation',
            ])->assertForbidden();

        $this->actingAs($superAdmin)
            ->put(route('admin.security.users.permissions', $owner), [
                'scope_type' => 'global',
                'permission_ids' => [],
            ])->assertForbidden();

        $this->assertTrue($owner->fresh()->isPlatformOwner());
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertFalse($ordinaryUser->fresh()->hasRole('PLATFORM_OWNER'));
        $this->assertDatabaseHas('platform_owner_registry', [
            'id' => 1,
            'user_id' => $owner->getKey(),
        ]);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('user_id', $superAdmin->getKey())
            ->whereIn('event', ['user.created', 'user.roles_changed', 'user.status_changed', 'user.permissions_changed'])
            ->count());
    }

    public function test_super_admin_cannot_reset_owner_mfa_change_sso_identity_or_revoke_owner_session(): void
    {
        $owner = $this->registeredOwner();
        $superAdmin = $this->superAdmin();

        $credential = AdminMfaCredential::query()->create([
            'user_id' => $owner->getKey(),
            'secret' => 'JBSWY3DPEHPK3PXP',
            'recovery_codes' => [],
            'enabled_at' => now(),
        ]);
        $identity = EnterpriseSsoIdentity::query()->create([
            'user_id' => $owner->getKey(),
            'provider' => 'enterprise',
            'subject' => 'registered-owner-subject',
            'email' => $owner->email,
        ]);
        $session = AdminSecuritySession::query()->create([
            'user_id' => $owner->getKey(),
            'session_hash' => hash('sha256', 'v2-protected-owner-session'),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->delete(route('admin.security.users.mfa.reset', $owner))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->post(route('admin.security.users.sso.store', $owner), [
                'provider' => 'enterprise',
                'subject' => 'attacker-controlled-subject',
            ])->assertForbidden();

        $this->actingAs($superAdmin)
            ->delete(route('admin.security.users.sso.destroy', $identity))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->delete(route('admin.security.sessions.revoke', $session))
            ->assertForbidden();

        $this->assertNull($credential->fresh()->revoked_at);
        $this->assertSame('registered-owner-subject', $identity->fresh()->subject);
        $this->assertNull($session->fresh()->revoked_at);
        $this->assertDatabaseMissing('enterprise_sso_identities', [
            'user_id' => $owner->getKey(),
            'subject' => 'attacker-controlled-subject',
        ]);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('user_id', $superAdmin->getKey())
            ->whereIn('event', [
                'management.mfa.reset',
                'management.sso.identity_linked',
                'management.sso.identity_unlinked',
                'management.session.revoked',
            ])->count());
    }

    public function test_normal_account_security_actions_still_work_and_remain_audited(): void
    {
        $superAdmin = $this->superAdmin();
        $owner = $this->registeredOwner();
        $ordinaryUser = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $credential = AdminMfaCredential::query()->create([
            'user_id' => $ordinaryUser->getKey(),
            'secret' => 'JBSWY3DPEHPK3PXP',
            'recovery_codes' => [],
            'enabled_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->delete(route('admin.security.users.mfa.reset', $ordinaryUser))
            ->assertRedirect();

        $this->assertNotNull($credential->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'management.mfa.reset',
            'user_id' => $superAdmin->getKey(),
            'auditable_id' => $ordinaryUser->getKey(),
        ]);

        $this->actingAs($owner)
            ->patch(route('admin.security.users.status', $ordinaryUser), [
                'is_active' => false,
                'reason' => 'V2 role boundary acceptance',
            ])->assertRedirect();

        $this->assertFalse($ordinaryUser->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.status_changed',
            'user_id' => $owner->getKey(),
            'auditable_id' => $ordinaryUser->getKey(),
        ]);
        $this->assertTrue($owner->fresh()->isPlatformOwner());
    }

    private function registeredOwner(): User
    {
        return app(PlatformOwnerProvisioner::class)->provision(
            'registered-owner@example.test',
            'VeryStrongOwnerSecret2026!',
        );
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
