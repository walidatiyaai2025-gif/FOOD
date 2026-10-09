<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Notification;
use App\Models\PushDeviceToken;
use App\Models\PushProviderSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\PushDeliveryService;
use App\Services\VanRegistryService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VanPushNotificationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_driver_compatibility_session_can_register_van_push_device(): void
    {
        $driverUser = $this->compatibilityDriverUser();

        Sanctum::actingAs($driverUser, ['app:van']);

        $token = 'van-compat-device-token';

        $this->postJson('/api/v1/push/devices', [
            'app' => 'van',
            'platform' => 'android',
            'environment' => 'production',
            'token' => $token,
            'install_id' => 'van-compat-install',
        ])
            ->assertCreated()
            ->assertJsonPath('data.app', 'van')
            ->assertJsonPath('data.environment', 'production');

        $this->assertDatabaseHas('push_device_tokens', [
            'user_id' => $driverUser->getKey(),
            'app' => 'van',
            'platform' => 'android',
            'environment' => 'production',
            'token_hash' => hash('sha256', $token),
            'revoked_at' => null,
        ]);
    }

    public function test_van_push_registration_rejects_non_van_token_ability(): void
    {
        $driverUser = $this->compatibilityDriverUser();

        Sanctum::actingAs($driverUser, ['app:driver']);

        $this->postJson('/api/v1/push/devices', [
            'app' => 'van',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'wrong-ability-token',
            'install_id' => 'wrong-ability-install',
        ])->assertForbidden();

        $this->assertDatabaseMissing('push_device_tokens', [
            'token_hash' => hash('sha256', 'wrong-ability-token'),
        ]);
    }

    public function test_van_notification_is_delivered_to_compatibility_driver_device(): void
    {
        $driverUser = $this->compatibilityDriverUser();

        PushProviderSetting::query()->create([
            'app' => 'van',
            'platform' => 'android',
            'environment' => 'production',
            'provider' => 'firebase',
            'enabled' => true,
            'credentials_encrypted' => [
                'project_id' => 'foodex-van-push-test',
                'access_token' => 'test-access-token',
            ],
            'default_sound' => 'default',
            'default_channel' => 'van',
        ]);

        $token = 'van-compat-delivery-token';
        $device = PushDeviceToken::query()->create([
            'user_id' => $driverUser->getKey(),
            'install_id' => 'van-compat-delivery-install',
            'app' => 'van',
            'platform' => 'android',
            'environment' => 'production',
            'target_channel' => 'all',
            'locale' => 'en',
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => $token,
        ]);

        $notification = Notification::query()->create([
            'channel' => 'push',
            'type' => 'van.operational',
            'title' => 'Van alert',
            'body' => 'Route updated',
            'title_ar' => 'تنبيه الفان',
            'title_en' => 'Van alert',
            'body_ar' => 'تم تحديث المسار',
            'body_en' => 'Route updated',
            'audience' => 'van',
            'app' => 'van',
            'target_channel' => 'b2b',
            'status' => 'published',
            'published_at' => now(),
            'data' => [],
        ]);

        Http::fake([
            'fcm.googleapis.com/*' => Http::response(
                ['name' => 'projects/foodex-van-push-test/messages/van-compat-1'],
                200,
            ),
        ]);

        app(PushDeliveryService::class)->dispatchNotification($notification);

        Http::assertSent(fn ($request): bool => data_get($request->data(), 'message.token') === $token);

        $this->assertDatabaseHas('push_delivery_logs', [
            'notification_id' => $notification->getKey(),
            'user_id' => $driverUser->getKey(),
            'device_id' => $device->getKey(),
            'status' => 'sent',
        ]);
    }

    private function compatibilityDriverUser(): User
    {
        $representative = User::factory()->create(['is_active' => true]);
        $representative->roles()->attach(
            Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail(),
        );

        $driverUser = User::factory()->create(['is_active' => true]);
        $driverUser->roles()->attach(
            Role::query()->where('code', 'B2B_DRIVER')->firstOrFail(),
        );

        $driver = Driver::query()->create([
            'user_id' => $driverUser->getKey(),
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan([
            'code' => 'PUSH-COMPAT-VAN-'.$driverUser->getKey(),
        ]);
        $registry->assign($representative, $van, [
            'driver_id' => $driver->getKey(),
            'representative_user_id' => $representative->getKey(),
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        return $driverUser;
    }
}
