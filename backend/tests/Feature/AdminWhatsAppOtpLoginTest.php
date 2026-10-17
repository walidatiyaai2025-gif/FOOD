<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AdminWhatsAppOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('whatsapp_auth.enabled', true);
        config()->set('whatsapp_auth.default_country_code', '965');
        config()->set('whatsapp_auth.ttl_minutes', 5);
        config()->set('whatsapp_auth.resend_cooldown_seconds', 60);
        config()->set('whatsapp_auth.max_attempts', 5);
        config()->set('whatsapp_auth.max_resends', 3);
        config()->set('whatsapp_auth.request_window_seconds', 3600);
        config()->set('whatsapp_auth.max_requests_per_phone', 5);
        config()->set('whatsapp_auth.max_requests_per_ip', 20);
        config()->set('whatsapp_auth.max_requests_per_device', 10);
    }

    public function test_verified_management_phone_can_sign_in_with_single_use_hashed_whatsapp_otp(): void
    {
        $phone = '+96550001111';
        $user = $this->managementUser($phone);
        $this->enableWhatsAppAuth($phone);

        $otp = null;
        Http::fake(function (HttpRequest $request) use (&$otp) {
            if (str_contains($request->url(), '/messages')) {
                $otp = $request->data()['template']['components'][0]['parameters'][0]['text'] ?? null;

                return Http::response(['messages' => [['id' => 'wamid.otp-1']]], 200);
            }

            return Http::response([], 200);
        });

        $this->post('/admin/b2b/login', [
            'email' => $phone,
            'locale' => 'en',
        ])->assertRedirect();

        $challenge = DB::table('admin_whatsapp_otp_challenges')
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($challenge);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', (string) $otp);
        $this->assertNotSame($otp, $challenge->otp_hash);
        $this->assertTrue(Hash::check((string) $otp, (string) $challenge->otp_hash));
        $this->assertNotNull($challenge->sent_at);

        $this->post(route('admin.b2b.login.whatsapp.verify', ['challenge' => $challenge->id]), [
            'code' => $otp,
            'locale' => 'en',
        ])->assertRedirect(route('admin.b2b.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(DB::table('admin_whatsapp_otp_challenges')->where('id', $challenge->id)->value('consumed_at'));

        $this->post('/admin/logout')->assertRedirect();
        $this->post(route('admin.b2b.login.whatsapp.verify', ['challenge' => $challenge->id]), [
            'code' => $otp,
            'locale' => 'en',
        ])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', ['event' => 'management.whatsapp_otp.sent']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'management.whatsapp_otp.verified']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'management.login.succeeded', 'user_id' => $user->id]);
    }

    public function test_unknown_number_gets_same_generic_challenge_without_account_creation_or_send(): void
    {
        $eligiblePhone = '+96550002222';
        $this->enableWhatsAppAuth($eligiblePhone);
        Http::fake();

        $response = $this->post('/admin/b2c/login', [
            'email' => '+96559999999',
            'locale' => 'en',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', __('admin_login.otp_generic_sent'));
        $this->assertDatabaseCount('admin_whatsapp_otp_challenges', 1);
        $this->assertNull(DB::table('admin_whatsapp_otp_challenges')->value('user_id'));
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_request_rate_limit_is_account_independent_and_applies_to_phone_across_ip_and_device_changes(): void
    {
        $phone = '+96550007777';
        $this->enableWhatsAppAuth($phone);
        config()->set('whatsapp_auth.max_requests_per_phone', 2);
        config()->set('whatsapp_auth.max_requests_per_ip', 50);
        config()->set('whatsapp_auth.max_requests_per_device', 50);
        Http::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.1', 'HTTP_USER_AGENT' => 'otp-device-a'])
            ->post('/admin/b2b/login', ['email' => $phone, 'locale' => 'en'])
            ->assertRedirect();
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.2', 'HTTP_USER_AGENT' => 'otp-device-b'])
            ->post('/admin/b2b/login', ['email' => $phone, 'locale' => 'en'])
            ->assertRedirect();
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.3', 'HTTP_USER_AGENT' => 'otp-device-c'])
            ->post('/admin/b2b/login', ['email' => $phone, 'locale' => 'en'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('admin_whatsapp_otp_challenges', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'management.whatsapp_otp.rate_limited']);
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_expired_wrong_and_max_attempt_otp_are_rejected_without_authentication(): void
    {
        $phone = '+96550003333';
        $user = $this->managementUser($phone);
        $this->enableWhatsAppAuth($phone);

        $otp = null;
        Http::fake(function (HttpRequest $request) use (&$otp) {
            $otp = $request->data()['template']['components'][0]['parameters'][0]['text'] ?? null;

            return Http::response(['messages' => [['id' => 'wamid.otp-attempts']]], 200);
        });

        $this->post('/admin/b2b/login', ['email' => $phone, 'locale' => 'en'])->assertRedirect();
        $challenge = DB::table('admin_whatsapp_otp_challenges')->where('user_id', $user->id)->firstOrFail();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('admin.b2b.login.whatsapp.verify', ['challenge' => $challenge->id]), [
                'code' => '000000',
                'locale' => 'en',
            ])->assertSessionHasErrors('code');
        }

        $this->assertSame(5, (int) DB::table('admin_whatsapp_otp_challenges')->where('id', $challenge->id)->value('attempt_count'));
        $this->post(route('admin.b2b.login.whatsapp.verify', ['challenge' => $challenge->id]), [
            'code' => $otp,
            'locale' => 'en',
        ])->assertSessionHasErrors('code');
        $this->assertGuest();

        DB::table('admin_whatsapp_otp_challenges')->where('id', $challenge->id)->update([
            'attempt_count' => 0,
            'expires_at' => now()->subSecond(),
        ]);

        $this->post(route('admin.b2b.login.whatsapp.verify', ['challenge' => $challenge->id]), [
            'code' => $otp,
            'locale' => 'en',
        ])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_resend_is_cooled_down_bounded_and_replaces_the_previous_code(): void
    {
        $phone = '+96550004444';
        $user = $this->managementUser($phone);
        $this->enableWhatsAppAuth($phone);

        $codes = [];
        Http::fake(function (HttpRequest $request) use (&$codes) {
            $codes[] = (string) ($request->data()['template']['components'][0]['parameters'][0]['text'] ?? '');

            return Http::response(['messages' => [['id' => 'wamid.otp-resend']]], 200);
        });

        $this->post('/admin/b2b/login', ['email' => $phone, 'locale' => 'en'])->assertRedirect();
        $challenge = DB::table('admin_whatsapp_otp_challenges')->where('user_id', $user->id)->firstOrFail();

        $this->post(route('admin.b2b.login.whatsapp.resend', ['challenge' => $challenge->id]), ['locale' => 'en'])
            ->assertSessionHasErrors('code');

        DB::table('admin_whatsapp_otp_challenges')->where('id', $challenge->id)->update([
            'resend_available_at' => now()->subSecond(),
        ]);
        $this->post(route('admin.b2b.login.whatsapp.resend', ['challenge' => $challenge->id]), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertCount(2, $codes);
        $this->assertNotSame($codes[0], $codes[1]);
        $this->assertTrue(Hash::check($codes[1], (string) DB::table('admin_whatsapp_otp_challenges')->where('id', $challenge->id)->value('otp_hash')));

        DB::table('admin_whatsapp_otp_challenges')->where('id', $challenge->id)->update([
            'resend_count' => 3,
            'resend_available_at' => now()->subSecond(),
        ]);
        $this->post(route('admin.b2b.login.whatsapp.resend', ['challenge' => $challenge->id]), ['locale' => 'en'])
            ->assertSessionHasErrors('code');
    }

    public function test_unavailable_whatsapp_hides_otp_path_but_password_login_remains_operational(): void
    {
        $phone = '+96550005555';
        $user = $this->managementUser($phone);

        $this->get('/admin/b2b/login?locale=en')
            ->assertOk()
            ->assertSee(__('admin_login.identifier'))
            ->assertDontSee(__('admin_login.identifier_hint'));

        $this->post('/admin/b2b/login', ['email' => $phone, 'locale' => 'en'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/admin/b2b/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'locale' => 'en',
        ])->assertRedirect(route('admin.b2b.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_whatsapp_otp_verifies_identity_without_broadening_b2b_retail_authorization(): void
    {
        $phone = '+96550006666';
        $user = $this->managementUser($phone);
        $this->enableWhatsAppAuth($phone);

        $otp = null;
        Http::fake(function (HttpRequest $request) use (&$otp) {
            $otp = $request->data()['template']['components'][0]['parameters'][0]['text'] ?? null;

            return Http::response(['messages' => [['id' => 'wamid.otp-scope']]], 200);
        });

        $this->post('/admin/b2c/login', ['email' => $phone, 'locale' => 'en'])->assertRedirect();
        $challengeId = (string) DB::table('admin_whatsapp_otp_challenges')->where('user_id', $user->id)->value('id');

        $this->post(route('admin.b2c.login.whatsapp.verify', ['challenge' => $challengeId]), [
            'code' => $otp,
            'locale' => 'en',
        ])->assertRedirect(route('admin.b2b.dashboard'));

        $this->get('/admin/b2b/dashboard')->assertOk();
        $this->get('/admin/b2c/dashboard')->assertForbidden();
    }

    private function managementUser(string $phone): User
    {
        $user = User::query()->create([
            'name' => 'OTP B2B Admin',
            'email' => 'otp-'.preg_replace('/\D+/', '', $phone).'@example.test',
            'phone_e164' => $phone,
            'phone_verified_at' => now(),
            'password' => 'correct-password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $user;
    }

    private function enableWhatsAppAuth(string $allowedPhone): void
    {
        DB::table('whats_app_module_states')->where('id', 1)->update([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => json_encode([
                'outbound' => true,
                'transactional' => true,
            ], JSON_THROW_ON_ERROR),
            'test_numbers' => json_encode([$allowedPhone], JSON_THROW_ON_ERROR),
            'revision' => DB::raw('revision + 1'),
            'updated_at' => now(),
        ]);

        $profile = new WhatsAppConnectionProfile;
        $profile->forceFill([
            'name' => 'OTP primary',
            'display_phone_number' => '+96522220000',
            'normalized_phone_number' => '+96522220000',
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'otp-phone-id',
            'provider_account_id' => 'otp-account-id',
            'credential_secret' => 'test-access-token',
            'status' => ConnectionProfileStatus::ACTIVE,
            'health_status' => ConnectionHealthStatus::READY,
            'is_primary' => true,
            'primary_slot' => 1,
            'receive_only' => false,
            'configuration_revision' => 1,
            'last_checked_at' => now(),
            'last_validated_at' => now(),
        ])->save();

        DB::table('whats_app_templates')->insert([
            'name' => 'FOODEX Authentication',
            'provider_name' => 'foodex_authentication',
            'locale' => 'en',
            'category' => 'authentication',
            'provider_status' => 'approved',
            'body_preview' => 'Your FOODEX verification code is {{1}}.',
            'components' => null,
            'is_active' => true,
            'last_synced_at' => now(),
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
