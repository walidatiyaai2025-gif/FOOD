<?php

namespace Tests\Feature;

use App\Models\AdminSecuritySession;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class V2UserDeactivationRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_status_deactivation_revokes_all_app_tokens_and_management_sessions_but_preserves_identity_and_audit(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2B_ADMIN');
        $target->forceFill(['remember_token' => 'old-remember-me-token'])->save();

        foreach (['customer', 'driver', 'van'] as $app) {
            $target->createToken('foodex-'.$app, ['app:'.$app]);
        }

        $targetSession = AdminSecuritySession::query()->create([
            'user_id' => $target->id,
            'session_hash' => hash('sha256', 'v2-target-session'),
            'last_seen_at' => now(),
        ]);
        $unrelatedSession = AdminSecuritySession::query()->create([
            'user_id' => $admin->id,
            'session_hash' => hash('sha256', 'v2-admin-session'),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'Access terminated',
                'impact_reviewed' => true,
                'confirm_email' => $target->email,
            ])->assertRedirect();

        $target->refresh();
        $this->assertFalse((bool) $target->is_active);
        $this->assertNotNull($target->deactivated_at);
        $this->assertSame('Access terminated', $target->deactivation_reason);
        $this->assertNotSame('old-remember-me-token', $target->getRememberToken());
        $this->assertSame(0, $target->tokens()->count());
        $this->assertNotNull($targetSession->fresh()->revoked_at);
        $this->assertSame($admin->id, $targetSession->fresh()->revoked_by);
        $this->assertNull($unrelatedSession->fresh()->revoked_at);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'user.status_changed',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => true,
                'reason' => 'Approved identity reactivation',
                'impact_reviewed' => true,
                'confirm_email' => $target->email,
            ])->assertRedirect();

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertNotNull($targetSession->fresh()->revoked_at);
        $this->assertNotSame('old-remember-me-token', $target->fresh()->getRememberToken());
    }

    public function test_inactive_management_identity_is_logged_out_instead_of_retaining_a_web_session(): void
    {
        $target = $this->withRole('B2B_ADMIN');
        $target->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

        $this->actingAs($target)
            ->get('/admin/security')
            ->assertForbidden();

        $this->assertFalse(Auth::guard('web')->check());

        // After the denied request destroyed the cookie-backed session, it is a guest.
        $this->get('/admin/security')
            ->assertRedirect(route('admin.b2c.login'));
    }

    public function test_operator_without_global_status_permission_cannot_deactivate_another_account(): void
    {
        $operator = $this->withRole('B2B_ADMIN');
        $target = $this->withRole('B2B_ADMIN');
        $token = $target->createToken('foodex-customer', ['app:customer']);

        $this->actingAs($operator)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
            ])->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertNotNull($token->accessToken->fresh());
    }

    private function withRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', $code)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    }
}
