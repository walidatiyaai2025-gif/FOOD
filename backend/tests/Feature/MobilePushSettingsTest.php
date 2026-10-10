<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\PushProviderSetting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobilePushSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_manages_runtime_settings_and_runtime_is_secret_free(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings/mobile/app', [
            'app' => 'customer',
            'environment' => 'production',
            'display_name' => 'FOODEX Customer',
            'android_package_id' => 'com.foodex.customer',
            'ios_bundle_id' => 'com.foodex.customer',
            'published_version' => '3.2.0',
            'published_build' => '320',
            'minimum_supported_version' => '3.0.0',
            'recommended_version' => '3.2.0',
            'force_update' => '1',
            'maintenance_message_ar' => 'صيانة',
            'maintenance_message_en' => 'Maintenance',
            'release_notes_ar' => 'جديد',
            'release_notes_en' => 'New',
            'google_play_url' => 'https://play.google.com/store/apps/details?id=com.foodex.customer',
            'app_store_url' => 'https://apps.apple.com/app/id123456789',
            'privacy_url' => 'https://example.test/privacy',
            'terms_url' => 'https://example.test/terms',
            'support_url' => 'https://example.test/support',
            'deep_link_json' => '{"scheme":"foodex"}',
            'store_readiness_json' => '{"privacy":true}',
        ])->assertRedirect();

        $this->assertDatabaseHas('mobile_app_settings', [
            'app' => 'customer',
            'environment' => 'production',
            'force_update' => 1,
            'published_version' => '3.2.0',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'mobile_settings.updated',
            'user_id' => $admin->id,
        ]);

        $this->getJson('/api/v1/mobile/runtime?app=customer&environment=production&locale=en')
            ->assertOk()
            ->assertJsonPath('data.maintenance_message', 'Maintenance')
            ->assertJsonPath('data.release_notes', 'New')
            ->assertJsonPath('data.force_update', true)
            ->assertJsonMissingPath('data.credentials_encrypted');
    }

    public function test_encrypted_provider_device_lifecycle_and_test_send(): void
    {
        $admin = $this->admin();
        $secret = 'secret-access-token';

        $this->actingAs($admin)->put('/admin/settings/mobile/push', [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'production',
            'enabled' => '1',
            'credentials_json' => json_encode(
                ['project_id' => 'foodex-prod', 'access_token' => $secret],
                JSON_THROW_ON_ERROR,
            ),
            'default_sound' => 'default',
            'default_channel' => 'orders',
        ])->assertRedirect();

        $provider = PushProviderSetting::query()->firstOrFail();
        $this->assertStringNotContainsString(
            $secret,
            (string) $provider->getRawOriginal('credentials_encrypted'),
        );

        $customer = User::query()->create([
            'name' => 'Customer',
            'email' => 'push-customer@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        Customer::query()->create([
            'user_id' => $customer->id,
            'type' => 'b2c',
            'name' => 'Customer',
            'email' => $customer->email,
        ]);

        Sanctum::actingAs($customer);

        $device = $this->postJson('/api/v1/push/devices', [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'device-token-123',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/push/devices', [
            'app' => 'driver',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'wrong',
        ])->assertForbidden();

        Http::fake([
            'fcm.googleapis.com/*' => Http::response(
                ['name' => 'projects/foodex-prod/messages/1'],
                200,
            ),
        ]);

        $this->actingAs($admin)->post('/admin/settings/mobile/test-push', [
            'device_id' => $device,
            'title_ar' => 'اختبار',
            'title_en' => 'Test',
            'body_ar' => 'رسالة',
            'body_en' => 'Message',
        ])->assertRedirect();

        $this->assertDatabaseHas('push_delivery_logs', [
            'device_id' => $device,
            'status' => 'sent',
            'is_test' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'push.test_sent',
            'user_id' => $admin->id,
        ]);

        Sanctum::actingAs($customer);
        $this->deleteJson('/api/v1/push/devices/'.$device)->assertOk();

        $this->assertDatabaseMissing('push_device_tokens', [
            'id' => $device,
            'revoked_at' => null,
        ]);
    }

    public function test_ios_fcm_device_uses_firebase_http_v1(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings/mobile/push', [
            'app' => 'customer',
            'platform' => 'ios',
            'environment' => 'production',
            'enabled' => '1',
            'credentials_json' => json_encode(
                ['project_id' => 'foodex-prod', 'access_token' => 'ios-fcm-access-token'],
                JSON_THROW_ON_ERROR,
            ),
            'default_sound' => 'default',
            'default_category' => 'orders',
        ])->assertRedirect();

        $this->assertDatabaseHas('push_provider_settings', [
            'app' => 'customer',
            'platform' => 'ios',
            'environment' => 'production',
            'provider' => 'firebase',
            'enabled' => 1,
        ]);

        $customer = User::query()->create([
            'name' => 'iOS Customer',
            'email' => 'push-ios@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        Customer::query()->create([
            'user_id' => $customer->id,
            'type' => 'b2c',
            'name' => 'iOS Customer',
            'email' => $customer->email,
        ]);

        Sanctum::actingAs($customer);
        $device = $this->postJson('/api/v1/push/devices', [
            'app' => 'customer',
            'platform' => 'ios',
            'environment' => 'production',
            'token' => 'ios-fcm-token-123',
        ])->assertCreated()->json('data.id');

        Http::fake([
            'fcm.googleapis.com/*' => Http::response(
                ['name' => 'projects/foodex-prod/messages/ios-1'],
                200,
            ),
        ]);

        $this->actingAs($admin)->post('/admin/settings/mobile/test-push', [
            'device_id' => $device,
            'title_ar' => 'اختبار iOS',
            'title_en' => 'iOS Test',
            'body_ar' => 'رسالة',
            'body_en' => 'Message',
        ])->assertRedirect();

        Http::assertSent(
            fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/foodex-prod/messages:send')
                && data_get($request->data(), 'message.token') === 'ios-fcm-token-123'
                && data_get($request->data(), 'message.apns.payload.aps.category') === 'orders'
        );

        $this->assertDatabaseHas('push_delivery_logs', [
            'device_id' => $device,
            'platform' => 'ios',
            'status' => 'sent',
        ]);
    }

    public function test_service_account_credentials_generate_oauth_token_and_send_push(): void
    {
        $admin = $this->admin();
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'service-account-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'fcm.googleapis.com/*' => Http::response([
                'name' => 'projects/foodex-prod/messages/service-account-1',
            ], 200),
        ]);

        $this->actingAs($admin)->put('/admin/settings/mobile/push', [
            'app' => 'driver',
            'platform' => 'android',
            'environment' => 'production',
            'enabled' => '1',
            'credentials_json' => json_encode([
                'type' => 'service_account',
                'project_id' => 'foodex-prod',
                'client_email' => 'firebase-adminsdk@foodex-prod.iam.gserviceaccount.com',
                'private_key' => $pem,
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ], JSON_THROW_ON_ERROR),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/settings/mobile/push/test-connection', [
            'app' => 'driver',
            'platform' => 'android',
            'environment' => 'production',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $driver = $this->roleUser('B2C_DRIVER', 'push-driver-service-account@example.test');
        Driver::query()->create([
            'user_id' => $driver->id,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($driver);
        $deviceId = $this->postJson('/api/v1/push/devices', [
            'app' => 'driver',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'driver-service-account-device-token',
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)->post('/admin/settings/mobile/test-push', [
            'device_id' => $deviceId,
            'title_ar' => 'اختبار السائق',
            'title_en' => 'Driver test',
            'body_ar' => 'رسالة اختبار',
            'body_en' => 'Test message',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('push_delivery_logs', [
            'device_id' => $deviceId,
            'status' => 'sent',
            'is_test' => 1,
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && ($request['grant_type'] ?? null) === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
        );
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'fcm.googleapis.com/v1/projects/foodex-prod/messages:send')
                || ! $request->hasHeader('Authorization', 'Bearer service-account-access-token')) {
                return false;
            }

            $body = json_decode($request->body());

            return is_object($body?->message?->android?->notification);
        });
    }

    public function test_non_privileged_admin_is_denied(): void
    {
        $user = User::query()->create([
            'name' => 'Ops',
            'email' => 'ops-mobile@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(
            Role::query()->where('code', 'OPERATIONS')->firstOrFail(),
        );

        $this->actingAs($user)
            ->get('/admin/settings/mobile')
            ->assertForbidden();
    }

    private function roleUser(string $role, string $email): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    private function admin(): User
    {
        $admin = User::query()->create([
            'name' => 'Super',
            'email' => 'mobile-admin@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(
            Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail(),
        );

        return $admin;
    }
}
