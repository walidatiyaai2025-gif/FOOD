<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Notification;
use App\Models\PushDeviceToken;
use App\Models\PushProviderSetting;
use App\Models\User;
use App\Services\PushDeliveryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushLifecyclePayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_routing_metadata_is_forwarded_to_fcm_data_payload(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Push Customer',
            'email' => 'push-routing@example.test',
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

        PushProviderSetting::query()->create([
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'production',
            'provider' => 'firebase',
            'enabled' => true,
            'credentials_encrypted' => [
                'project_id' => 'foodex-push-test',
                'access_token' => 'test-access-token',
            ],
            'default_sound' => 'default',
            'default_channel' => 'orders',
        ]);

        PushDeviceToken::query()->create([
            'user_id' => $user->id,
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'production',
            'token_hash' => hash('sha256', 'routing-device-token'),
            'token_encrypted' => 'routing-device-token',
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
            'user_id' => $user->id,
            'status' => 'sent',
        ]);
    }
}
