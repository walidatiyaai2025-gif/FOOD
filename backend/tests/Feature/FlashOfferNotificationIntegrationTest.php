<?php

namespace Tests\Feature;

use App\Models\FlashOffer;
use App\Services\FlashOfferNotificationDispatcher;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlashOfferNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

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
}
