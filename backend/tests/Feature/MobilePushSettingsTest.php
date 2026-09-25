<?php

namespace Tests\Feature;

use App\Models\Customer;
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
