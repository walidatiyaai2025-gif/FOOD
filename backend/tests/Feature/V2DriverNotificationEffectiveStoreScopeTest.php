<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Notification;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Services\NotificationAudience;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2DriverNotificationEffectiveStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_inactive_foreign_driver_cannot_expand_active_customer_notification_store_scope(): void
    {
        $storeA = $this->retailStore('V2-NOT-CUST-A');
        $storeB = $this->retailStore('V2-NOT-CUST-B');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        app(B2cCustomerService::class)->create($storeA, ['name' => 'Dual Persona'], $user);
        $this->driver($user, $storeB, 'b2c', false);

        $own = $this->notification($storeA, 'customer', 'customer', 'Own customer store');
        $foreign = $this->notification($storeB, 'customer', 'customer', 'Foreign customer store');

        $audience = app(NotificationAudience::class);
        $this->assertTrue($audience->matchesTarget($user, 'customer', 'customer', 'b2c', $storeA));
        $this->assertFalse($audience->matchesTarget($user, 'customer', 'customer', 'b2c', $storeB));

        Sanctum::actingAs($user, ['app:customer']);
        $this->getJson('/api/v1/notifications?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.title', 'Own customer store');

        $this->getJson("/api/v1/notifications/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/notifications/{$foreign->id}/read")->assertNotFound();
        $this->assertDatabaseMissing('notification_reads', [
            'notification_id' => $foreign->id,
            'user_id' => $user->id,
        ]);

        $this->postJson("/api/v1/notifications/{$own->id}/read")->assertNoContent();
        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $own->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_valid_active_driver_receives_own_retail_store_but_not_foreign_store(): void
    {
        $storeA = $this->retailStore('V2-NOT-DRV-A');
        $storeB = $this->retailStore('V2-NOT-DRV-B');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->driver($user, $storeA, 'b2c', true);

        $own = $this->notification($storeA, 'driver', 'driver', 'Own driver store');
        $foreign = $this->notification($storeB, 'driver', 'driver', 'Foreign driver store');

        $audience = app(NotificationAudience::class);
        $this->assertTrue($audience->matchesTarget($user, 'driver', 'driver', 'b2c', $storeA));
        $this->assertFalse($audience->matchesTarget($user, 'driver', 'driver', 'b2c', $storeB));

        Sanctum::actingAs($user, ['app:driver']);
        $this->getJson('/api/v1/notifications?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $own->id);
        $this->getJson("/api/v1/notifications/{$foreign->id}")->assertNotFound();
    }

    public function test_driver_in_inactive_retail_store_has_no_driver_notification_audience(): void
    {
        $store = $this->retailStore('V2-NOT-DRV-INACTIVE');
        DB::table('stores')->where('id', $store)->update(['is_active' => false]);
        $user = User::factory()->create(['is_active' => true]);
        $this->driver($user, $store, 'b2c', true);

        $notification = $this->notification($store, 'driver', 'driver', 'Inactive store');
        $this->assertFalse(app(NotificationAudience::class)
            ->matchesTarget($user, 'driver', 'driver', 'b2c', $store));

        Sanctum::actingAs($user, ['app:driver']);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/notifications/{$notification->id}")->assertNotFound();
    }

    public function test_retail_driver_pointing_to_wholesale_store_has_no_driver_audience(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $store = app(WholesalePrincipal::class)->storeId();
        $this->driver($user, $store, 'b2c', true);

        $this->assertFalse(app(NotificationAudience::class)
            ->matchesTarget($user, 'driver', 'driver', 'b2c', $store));
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function driver(User $user, int $storeId, string $type, bool $active): Driver
    {
        return Driver::query()->create([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'driver_type' => $type,
            'is_active' => $active,
            'is_available' => $active,
        ]);
    }

    private function notification(int $storeId, string $audience, string $app, string $name): Notification
    {
        return Notification::query()->create([
            'channel' => 'in_app',
            'type' => 'delivery.status',
            'title' => $name,
            'body' => $name,
            'title_ar' => 'تنبيه متجر',
            'title_en' => $name,
            'body_ar' => 'تحديث التوصيل',
            'body_en' => 'Delivery update',
            'audience' => $audience,
            'app' => $app,
            'target_channel' => 'b2c',
            'store_id' => $storeId,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
}
