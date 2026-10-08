<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SmsMessageLog;
use App\Models\SmsProviderSetting;
use App\Models\User;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\SmsMessage;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmsGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_gateway_normalizes_operator_and_is_idempotent(): void
    {
        $this->setting();
        Http::fake([
            'hub.advansystelecom.com/*' => Http::response('1', 200),
        ]);

        $requestId = (string) Str::uuid();
        $gateway = app(SmsGateway::class);
        $message = new SmsMessage(
            recipient: '01012345678',
            message: 'FOODEX test',
            purpose: 'system',
            requestId: $requestId,
        );

        $first = $gateway->send($message);
        $second = $gateway->send($message);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('sent', $first->status);
        $this->assertSame(1, $first->operator_id);
        $this->assertSame('1', $first->provider_response_code);
        $this->assertStringEndsWith('5678', $first->recipient_masked);
        $this->assertSame(
            1,
            SmsMessageLog::query()->where('request_id', $requestId)->count(),
        );

        Http::assertSentCount(1);
        Http::assertSent(
            fn ($request): bool => $request->url()
                === 'https://hub.advansystelecom.com/generalapiv12/api/bulkSMS/ForwardSMS'
                && $request->hasHeader('Authorization', 'secret-token')
                && $request['PhoneNumber'] === '201012345678'
                && $request['OperatorID'] === 1,
        );
    }

    public function test_error_mapping_does_not_log_secret_or_message(): void
    {
        $this->setting();
        Http::fake([
            'hub.advansystelecom.com/*' => Http::response('-1', 200),
        ]);

        $log = app(SmsGateway::class)->send(new SmsMessage(
            recipient: '01212345678',
            message: 'Sensitive OTP 123456',
            purpose: 'system',
            requestId: (string) Str::uuid(),
        ));

        $this->assertSame('failed', $log->status);
        $this->assertSame('invalid_authorization', $log->error_code);

        $raw = json_encode(
            DB::table('sms_message_logs')
                ->where('id', $log->id)
                ->first(),
        );

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('secret-token', $raw);
        $this->assertStringNotContainsString('Sensitive OTP', $raw);
        $this->assertStringNotContainsString('123456', $raw);
    }

    public function test_super_admin_page_masks_encrypted_token(): void
    {
        $setting = $this->setting();
        $user = User::query()->create([
            'name' => 'SMS Owner',
            'email' => 'sms-owner@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(
            Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail(),
        );

        $this->actingAs($user)
            ->get('/admin/settings/sms')
            ->assertOk()
            ->assertSee('SMS Gateway')
            ->assertSee('••••••••oken')
            ->assertDontSee('secret-token');

        $stored = DB::table('sms_provider_settings')
            ->where('id', $setting->id)
            ->value('api_token_encrypted');

        $this->assertIsString($stored);
        $this->assertNotSame('secret-token', $stored);
    }

    public function test_settings_reject_ssrf_provider_url(): void
    {
        $this->setting();
        $user = User::query()->create([
            'name' => 'SMS Owner',
            'email' => 'sms-owner-2@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(
            Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail(),
        );

        $this->actingAs($user)
            ->from('/admin/settings/sms')
            ->put('/admin/settings/sms', [
                'provider' => 'advansys_bulk_sms',
                'enabled' => '1',
                'api_base_url' => 'https://127.0.0.1',
                'endpoint_path' => '/generalapiv12/api/bulkSMS/ForwardSMS',
                'default_sender_name' => 'FOODEX',
                'default_country_code' => '20',
                'operator_resolution_mode' => 'automatic',
                'request_timeout' => 5,
                'retry_count' => 0,
                'retry_backoff_seconds' => 0,
            ])
            ->assertRedirect('/admin/settings/sms')
            ->assertSessionHasErrors('api_base_url');
    }

    private function setting(): SmsProviderSetting
    {
        return SmsProviderSetting::query()->create([
            'provider' => 'advansys_bulk_sms',
            'environment' => 'production',
            'enabled' => true,
            'api_base_url' => 'https://hub.advansystelecom.com',
            'endpoint_path' => '/generalapiv12/api/bulkSMS/ForwardSMS',
            'api_token_encrypted' => 'secret-token',
            'default_sender_name' => 'FOODEX',
            'default_country_code' => '20',
            'operator_resolution_mode' => 'automatic',
            'request_timeout' => 5,
            'retry_count' => 0,
            'retry_backoff_seconds' => 0,
            'delivery_logging' => true,
            'otp_sender_enabled' => true,
            'notification_sender_enabled' => true,
        ]);
    }
}
