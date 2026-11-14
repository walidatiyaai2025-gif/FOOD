<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Notification;
use App\Models\User;
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

    public function test_active_retail_driver_cannot_read_foreign_store_notifications_from_stale_profiles(): void
    {
        $storeA = $this->retailStore('V2-DRV-NOT-A');
        $storeB = $this->retailStore('V2-DRV-NOT-B');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->driver($user, $storeA, 'b2c', true);
        $this->driver($user, $storeB, 'b2c', false);
        $this->driver($user, app(WholesalePrincipal::class)->storeId(), 'b2b', true);

        $own = $this->notification($storeA, 'Own store notification');
        $foreign = $this->notification($storeB, 'Foreign store notification');

        $audience = app(NotificationAudience::class);
        $this->assertTrue($audience->matchesTarget($user, 'driver', 'driver', 'b2c', $storeA));
        $this->assertFalse($audience->matchesTarget($user, 'driver', 'driver', 'b2c', $storeB));

        Sanctum::actingAs($user, ['app:driver']);
        $this->getJson('/api/v1/notifications?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.title', 'Own store notification');

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

    public function test_driver_without_current_active_retail_store_has_no_driver_audience(): void
    {
        $inactiveStore = $this->retailStore('V2-DRV-NOT-INACTIVE');
        DB::table('stores')->where('id', $inactiveStore)->update(['is_active' => false]);
        $otherStore = $this->retailStore('V2-DRV-NOT-OTHER');
        $user = User::factory()->create(['is_active' => true]);

        $this->driver($user, $inactiveStore, 'b2c', true);
        $this->driver($user, $otherStore, 'b2c', false);
        $this->driver($user, app(WholesalePrincipal::class)->storeId(), 'b2b', true);

        $notification = $this->notification($otherStore, 'Inactive driver should not see');
        $audience = app(NotificationAudience::class);
        $this->assertFalse($audience->matchesTarget($user, 'driver', 'driver', 'b2c', $otherStore));

        Sanctum::actingAs($user, ['app:driver']);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/notifications/{$notification->id}")->assertNotFound();
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

    private function notification(int $storeId, string $name): Notification
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
            'audience' => 'driver',
            'app' => 'driver',
            'target_channel' => 'b2c',
            'store_id' => $storeId,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
}
