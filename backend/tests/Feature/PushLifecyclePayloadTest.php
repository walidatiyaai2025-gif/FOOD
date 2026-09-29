<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Notification;
use App\Models\PushDeliveryLog;
use App\Models\PushDeviceToken;
use App\Models\PushProviderSetting;
use App\Models\User;
use App\Services\PushDeliveryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PushLifecyclePayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_order_routing_metadata_is_forwarded_to_fcm_data_payload(): void
    {
        [$user, $device, $notification] = $this->fixture('routing');

        Http::fake([
            'fcm.googleapis.com/*' => Http::response(
                ['name' => 'projects/foodex-push-test/messages/routing-1'],
                200,
            ),
        ]);

        app(PushDeliveryService::class)->dispatchNotification($notification);

        Http::assertSent(function ($request): bool {
            $data = data_get($request->data(), 'message.data', []);

            return data_get($request->data(), 'message.token') === 'routing-device-token'
                && ($data['order_id'] ?? null) === '321'
                && ($data['order_number'] ?? null) === 'FDX-321'
                && ($data['channel'] ?? null) === 'b2c'
                && ($data['assignment_id'] ?? null) === '654'
                && ($data['access_revoked'] ?? null) === '0'
                && ($data['status'] ?? null) === 'out_for_delivery'
                && ($data['visible_notification'] ?? null) === '1';
        });

        $this->assertDatabaseHas('push_delivery_logs', [
            'notification_id' => $notification->id,
            'user_id' => $user->id,
            'device_id' => $device->id,
            'status' => 'sent',
            'attempt' => 1,
        ]);
    }

    public function test_transient_failure_retries_without_resending_after_success(): void
    {
        [, $device, $notification] = $this->fixture('retry');

        Http::fake([
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'temporary unavailable']], 503)
                ->push(['name' => 'projects/foodex-push-test/messages/retry-2'], 200),
        ]);

        try {
            app(PushDeliveryService::class)->dispatchNotification($notification, true);
            $this->fail('A transient provider failure should request a queue retry.');
        } catch (RuntimeException) {
            // Expected: lifecycle queue job will retry.
        }

        $this->assertDatabaseHas('push_delivery_logs', [
            'notification_id' => $notification->id,
            'device_id' => $device->id,
            'status' => 'failed',
            'attempt' => 1,
            'response_code' => 503,
        ]);

        app(PushDeliveryService::class)->dispatchNotification($notification, true);

        $this->assertDatabaseHas('push_delivery_logs', [
            'notification_id' => $notification->id,
            'device_id' => $device->id,
            'status' => 'sent',
            'attempt' => 2,
        ]);

        app(PushDeliveryService::class)->dispatchNotification($notification, true);

        $this->assertSame(
            2,
            PushDeliveryLog::query()
                ->where('notification_id', $notification->id)
                ->where('device_id', $device->id)
                ->count(),
        );
    }

    public function test_unregistered_fcm_token_is_revoked_and_not_retried(): void
    {
        [, $device, $notification] = $this->fixture('invalid');

        Http::fake([
            'fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 404,
                    'message' => 'Requested entity was not found.',
                    'status' => 'NOT_FOUND',
                    'details' => [
                        [
                            '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                            'errorCode' => 'UNREGISTERED',
                        ],
                    ],
                ],
            ], 404),
        ]);

        app(PushDeliveryService::class)->dispatchNotification($notification, true);

        $this->assertDatabaseHas('push_delivery_logs', [
            'notification_id' => $notification->id,
            'device_id' => $device->id,
            'status' => 'failed',
            'error_code' => 'invalid_device_token',
            'attempt' => 1,
        ]);
        $this->assertNotNull($device->fresh()->revoked_at);
    }

    /** @return array{0: User, 1: PushDeviceToken, 2: Notification} */
    private function fixture(string $suffix): array
    {
        $user = User::query()->create([
            'name' => 'Push Customer',
            'email' => 'push-'.$suffix.'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => $user->name,
            'email' => $user->email,
        ]);

        PushProviderSetting::query()->firstOrCreate(
            [
                'app' => 'customer',
                'platform' => 'android',
                'environment' => 'production',
            ],
            [
                'provider' => 'firebase',
                'enabled' => true,
                'credentials_encrypted' => [
                    'project_id' => 'foodex-push-test',
                    'access_token' => 'test-access-token',
                ],
                'default_sound' => 'default',
                'default_channel' => 'orders',
            ],
        );

        $token = $suffix.'-device-token';
        $device = PushDeviceToken::query()->create([
            'user_id' => $user->id,
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'production',
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => $token,
        ]);

        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'channel' => 'both',
            'type' => 'order.status_changed',
            'title' => 'تحديث الطلب',
            'body' => 'تم تحديث الطلب',
            'title_ar' => 'تحديث الطلب',
            'title_en' => 'Order update',
            'body_ar' => 'تم تحديث الطلب',
            'body_en' => 'Your order is on the way',
            'audience' => 'user',
            'app' => 'customer',
            'target_channel' => 'all',
            'store_id' => null,
            'status' => 'published',
            'published_at' => now(),
            'data' => [
                'order_id' => 321,
                'order_number' => 'FDX-321',
                'channel' => 'b2c',
                'assignment_id' => 654,
                'access_revoked' => false,
                'status' => 'out_for_delivery',
            ],
        ]);

        return [$user, $device, $notification];
    }
}
