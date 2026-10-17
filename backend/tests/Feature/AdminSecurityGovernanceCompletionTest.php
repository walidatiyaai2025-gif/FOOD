<?php

namespace Tests\Feature;

use App\Models\AdminMfaCredential;
use App\Models\AdminSecuritySession;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminTotpService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminSecurityGovernanceCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_privileged_management_access_fails_closed_into_mfa_enrollment(): void
    {
        config()->set('security.mfa.enforce_privileged', true);
        $admin = $this->superAdmin('en');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect(route('admin.mfa.enroll'));

        $this->assertDatabaseHas('admin_security_sessions', [
            'user_id' => $admin->id,
        ]);
    }

    public function test_mfa_secret_is_encrypted_and_recovery_code_is_one_time_hashed_data(): void
    {
        $admin = $this->superAdmin();
        $totp = app(AdminTotpService::class);
        $plainSecret = $totp->generateSecret();
        $plainRecoveryCode = 'ABCDE-FGHIJ';

        $credential = AdminMfaCredential::query()->create([
            'user_id' => $admin->id,
            'secret' => $plainSecret,
            'recovery_codes' => $totp->recoveryHashes([$plainRecoveryCode]),
            'enabled_at' => now(),
        ]);

        $raw = DB::table('admin_mfa_credentials')->where('id', $credential->id)->firstOrFail();

        $this->assertNotSame($plainSecret, $raw->secret);
        $this->assertSame($plainSecret, $credential->fresh()->secret);
        $this->assertNotContains($plainRecoveryCode, (array) $credential->fresh()->recovery_codes);

        $this->assertTrue($totp->consumeRecoveryCode($credential->fresh(), $plainRecoveryCode));
        $this->assertFalse($totp->consumeRecoveryCode($credential->fresh(), $plainRecoveryCode));
    }

    public function test_enterprise_sso_is_fail_closed_when_disabled(): void
    {
        config()->set('security.sso.enabled', false);

        $this->get(route('admin.sso.redirect'))
            ->assertNotFound();
    }

    public function test_revoked_management_session_is_logged_out_before_dashboard_access(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->superAdmin('en');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();

        $session = AdminSecuritySession::query()
            ->where('user_id', $admin->id)
            ->firstOrFail();

        $session->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ])->save();

        $this->get('/admin')
            ->assertRedirect(route('admin.b2c.login'));
    }

    public function test_security_governance_dashboard_is_available_in_english_and_arabic(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->superAdmin('en');

        $this->actingAs($admin)
            ->get(route('admin.security.index'))
            ->assertOk()
            ->assertSee('Security &amp; governance snapshot', false);

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin->fresh())
            ->get(route('admin.security.index'))
            ->assertOk()
            ->assertSee('لقطة الأمان والحوكمة');
    }

    private function superAdmin(string $locale = 'ar'): User
    {
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $user = User::factory()->create([
            'is_active' => true,
            'locale' => $locale,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
