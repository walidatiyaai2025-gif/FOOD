<?php

namespace Tests\Feature;

use App\Models\FlashOffer;
use App\Models\Notification;
use App\Models\User;
use App\Services\FlashOfferNotificationDispatcher;
use App\Services\NotificationAudience;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlashOfferNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['flash_offers_enabled', 'customer_flash_popup_enabled', 'van_offers_enabled'] as $flag) {
            $this->flag($flag, true);
        }
    }

    public function test_flash_start_reuses_notification_stack_with_customer_popup_and_van_push_only(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'FLASH-NOTIFY',
            'name' => 'Flash Notify Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $offer = FlashOffer::query()->create([
            'store_id' => $storeId,
            'name' => 'Notification acceptance',
            'title_ar' => 'عرض سريع',
            'title_en' => 'Flash Offer',
            'body_ar' => 'ابدأ الآن',
            'body_en' => 'Start now',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'timezone' => 'Asia/Kuwait',
            'channels' => ['customer', 'van'],
            'allocation_mode' => 'shared',
            'reservation_seconds' => 300,
            'retry_count' => 0,
            'cooldown_seconds' => 0,
            'priority' => 10,
            'popup_frequency' => 'once_per_session',
            'counts_toward_normal_quota' => true,
            'stackable' => false,
            'kill_switch' => false,
        ]);

        $dispatcher = app(FlashOfferNotificationDispatcher::class);

        $this->assertSame(2, $dispatcher->dispatchDue());
        $this->assertSame(2, DB::table('notifications')->where('type', 'flash_offer.started')->count());

        $customer = DB::table('notifications')
            ->where('dedupe_key', 'flash-offer:'.$offer->id.':start:customer')
            ->first();
        $van = DB::table('notifications')
            ->where('dedupe_key', 'flash-offer:'.$offer->id.':start:van')
            ->first();

        $this->assertNotNull($customer);
        $this->assertNotNull($van);
        $this->assertSame('customer', $customer->app);
        $this->assertSame('customer', $customer->audience);
        $this->assertSame('b2c', $customer->target_channel);
        $this->assertTrue((bool) json_decode($customer->data, true, 512, JSON_THROW_ON_ERROR)['popup']);
        $this->assertSame('van', $van->app);
        $this->assertSame('van', $van->audience);
        $this->assertSame('b2b', $van->target_channel);
        $this->assertFalse((bool) json_decode($van->data, true, 512, JSON_THROW_ON_ERROR)['popup']);

        $this->assertDatabaseHas('flash_offer_events', [
            'flash_offer_id' => $offer->id,
            'event' => 'start_notification_customer',
            'channel' => 'customer',
        ]);
        $this->assertDatabaseHas('flash_offer_events', [
            'flash_offer_id' => $offer->id,
            'event' => 'start_notification_van',
            'channel' => 'van',
        ]);

        $this->assertSame(0, $dispatcher->dispatchDue());
        $this->assertSame(2, DB::table('notifications')->where('type', 'flash_offer.started')->count());
        $this->assertSame(2, DB::table('flash_offer_events')->where('flash_offer_id', $offer->id)
            ->whereIn('event', ['start_notification_customer', 'start_notification_van'])->count());
    }

    public function test_kill_switch_blocks_new_flash_start_notifications(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'FLASH-KILL',
            'name' => 'Flash Kill Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        FlashOffer::query()->create([
            'store_id' => $storeId,
            'name' => 'Killed offer',
            'title_ar' => 'متوقف',
            'title_en' => 'Stopped',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'timezone' => 'Asia/Kuwait',
            'channels' => ['customer', 'van'],
            'allocation_mode' => 'shared',
            'reservation_seconds' => 300,
            'retry_count' => 0,
            'cooldown_seconds' => 0,
            'priority' => 10,
            'popup_frequency' => 'once_per_session',
            'counts_toward_normal_quota' => true,
            'stackable' => false,
            'kill_switch' => true,
        ]);

        $this->assertSame(0, app(FlashOfferNotificationDispatcher::class)->dispatchDue());
        $this->assertDatabaseMissing('notifications', ['type' => 'flash_offer.started']);
    }

    public function test_targeted_flash_push_filters_users_and_popup_flag_is_backend_authoritative(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'FLASH-TARGET',
            'name' => 'Flash Target Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $target = User::factory()->create(['is_active' => true]);
        $outsider = User::factory()->create(['is_active' => true]);
        $customerId = (int) DB::table('customers')->insertGetId([
            'user_id' => $target->id,
            'type' => 'b2c',
            'name' => 'Target Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $outsiderCustomerId = (int) DB::table('customers')->insertGetId([
            'user_id' => $outsider->id,
            'type' => 'b2c',
            'name' => 'Outsider Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2c_customers')->insert([
            [
                'legacy_customer_id' => $customerId,
                'store_id' => $storeId,
                'user_id' => $target->id,
                'name' => 'Target Customer',
                'email' => $target->email,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'legacy_customer_id' => $outsiderCustomerId,
                'store_id' => $storeId,
                'user_id' => $outsider->id,
                'name' => 'Outsider Customer',
                'email' => $outsider->email,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $this->flag('customer_flash_popup_enabled', false);

        $offer = FlashOffer::query()->create([
            'store_id' => $storeId,
            'name' => 'Targeted notification',
            'title_ar' => 'مستهدف',
            'title_en' => 'Targeted',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'timezone' => 'Asia/Kuwait',
            'channels' => ['customer'],
            'audience_customer_ids' => [$customerId],
            'allocation_mode' => 'shared',
            'reservation_seconds' => 300,
            'retry_count' => 0,
            'cooldown_seconds' => 0,
            'priority' => 10,
            'popup_frequency' => 'once_per_session',
            'counts_toward_normal_quota' => true,
            'stackable' => false,
            'kill_switch' => false,
        ]);

        $this->assertSame(1, app(FlashOfferNotificationDispatcher::class)->dispatchDue());
        $notification = Notification::query()
            ->where('dedupe_key', 'flash-offer:'.$offer->id.':start:customer')
            ->firstOrFail();
        $data = (array) $notification->data;

        $this->assertFalse((bool) ($data['popup'] ?? true));
        $this->assertSame([$target->id], array_values($data['eligible_user_ids'] ?? []));
        $this->assertTrue(app(NotificationAudience::class)->apply(Notification::query(), $target)->whereKey($notification->id)->exists());
        $this->assertFalse(app(NotificationAudience::class)->apply(Notification::query(), $outsider)->whereKey($notification->id)->exists());
    }

    private function flag(string $key, bool $enabled): void
    {
        DB::table('settings')->updateOrInsert(
            ['store_id' => null, 'key' => $key],
            [
                'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
