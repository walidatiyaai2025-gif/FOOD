<?php

namespace Tests\Feature;

use App\Models\NotificationCampaign;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationCampaignDispatcher;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionalNotificationCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2b_admin_can_schedule_b2b_campaign_but_cannot_target_b2c(): void
    {
        $admin = $this->globalRoleUser('B2B_ADMIN', 'b2b-campaigns@example.test');
        $wholesaleStore = $this->store('B2B', 'CAMPAIGN-WHOLESALE');

        $this->actingAs($admin)
            ->get('/admin/notification-campaigns')
            ->assertOk()
            ->assertSee('Scheduled Promotional Campaigns');

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->payload([
            'target_channel' => 'b2b',
            'store_id' => $wholesaleStore,
            'schedule_kind' => 'recurring',
            'interval_value' => 2,
            'interval_unit' => 'hour',
            'activate' => 1,
        ]))->assertRedirect();

        $this->assertDatabaseHas('notification_campaigns', [
            'target_channel' => 'b2b',
            'store_id' => $wholesaleStore,
            'schedule_kind' => 'recurring',
            'interval_value' => 2,
            'interval_unit' => 'hour',
            'status' => 'active',
        ]);

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->payload([
            'target_channel' => 'b2c',
            'store_id' => $this->store('B2C', 'CAMPAIGN-FOREIGN-RETAIL'),
        ]))->assertForbidden();
    }

    public function test_b2c_store_admin_can_only_manage_campaigns_for_assigned_store(): void
    {
        $mine = $this->store('B2C', 'CAMPAIGN-MINE');
        $other = $this->store('B2C', 'CAMPAIGN-OTHER');
        $admin = $this->storeRoleUser($mine, 'B2C_STORE_ADMIN', 'b2c-campaigns@example.test');

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->payload([
            'target_channel' => 'b2c',
            'store_id' => $mine,
            'activate' => 1,
        ]))->assertRedirect();

        $campaign = NotificationCampaign::query()->firstOrFail();
        $this->assertSame($mine, (int) $campaign->store_id);

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->payload([
            'target_channel' => 'b2c',
            'store_id' => $other,
        ]))->assertNotFound();

        $foreign = NotificationCampaign::query()->create([
            'name' => 'Foreign retail campaign',
            'type' => 'promotion',
            'title_ar' => 'أخرى',
            'title_en' => 'Other',
            'body_ar' => 'نص',
            'body_en' => 'Body',
            'audience' => 'customer',
            'app' => 'customer',
            'target_channel' => 'b2c',
            'delivery_channel' => 'in_app',
            'store_id' => $other,
            'created_by' => $admin->id,
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now(),
            'next_run_at' => now(),
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post("/admin/notification-campaigns/{$foreign->id}/send-now")
            ->assertNotFound();
    }

    public function test_b2c_store_admin_cannot_target_a_user_from_another_store(): void
    {
        $mine = $this->store('B2C', 'USER-TARGET-MINE');
        $other = $this->store('B2C', 'USER-TARGET-OTHER');
        $admin = $this->storeRoleUser($mine, 'B2C_STORE_ADMIN', 'user-target-admin@example.test');

        $mineUser = User::query()->create([
            'name' => 'Mine Customer',
            'email' => 'mine-target@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $foreignUser = User::query()->create([
            'name' => 'Foreign Customer',
            'email' => 'foreign-target@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        DB::table('b2c_customers')->insert([
            [
                'user_id' => $mineUser->id,
                'store_id' => $mine,
                'name' => 'Mine Customer',
                'email' => $mineUser->email,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $foreignUser->id,
                'store_id' => $other,
                'name' => 'Foreign Customer',
                'email' => $foreignUser->email,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->payload([
            'audience' => 'user',
            'app' => 'customer',
            'target_channel' => 'b2c',
            'store_id' => $mine,
            'user_id' => $foreignUser->id,
        ]))->assertNotFound();

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->payload([
            'audience' => 'user',
            'app' => 'customer',
            'target_channel' => 'b2c',
            'store_id' => $mine,
            'user_id' => $mineUser->id,
        ]))->assertRedirect();

        $this->assertDatabaseHas('notification_campaigns', [
            'store_id' => $mine,
            'user_id' => $mineUser->id,
            'target_channel' => 'b2c',
        ]);
    }

    public function test_customer_launch_popups_are_windowed_localized_and_strictly_store_scoped(): void
    {
        CarbonImmutable::setTestNow('2026-10-04 08:00:00');

        $storeA = $this->store('B2C', 'POPUP-STORE-A');
        $storeB = $this->store('B2C', 'POPUP-STORE-B');

        $eligible = $this->launchCampaign([
            'name' => 'Store A popup',
            'title_en' => 'Store A launch offer',
            'target_channel' => 'b2c',
            'store_id' => $storeA,
            'popup_frequency' => 'once_per_session',
            'popup_cta_label_en' => 'View offers',
            'popup_cta_target' => '/offers',
        ]);
        $this->launchCampaign([
            'name' => 'Foreign store',
            'target_channel' => 'b2c',
            'store_id' => $storeB,
        ]);
        $this->launchCampaign([
            'name' => 'Push only',
            'target_channel' => 'b2c',
            'store_id' => $storeA,
            'delivery_channel' => 'push',
        ]);
        $this->launchCampaign([
            'name' => 'Expired',
            'target_channel' => 'b2c',
            'store_id' => $storeA,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);
        $this->launchCampaign([
            'name' => 'Draft',
            'target_channel' => 'b2c',
            'store_id' => $storeA,
            'status' => 'draft',
        ]);
        $this->launchCampaign([
            'name' => 'Driver only',
            'target_channel' => 'b2c',
            'store_id' => $storeA,
            'app' => 'driver',
        ]);

        $this->getJson(
            "/api/v1/notification-campaign-popups?channel=b2c&store_id={$storeA}&locale=en&install_id=install-a",
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eligible->id)
            ->assertJsonPath('data.0.title', 'Store A launch offer')
            ->assertJsonPath('data.0.frequency', 'once_per_session')
            ->assertJsonPath('data.0.cta_label', 'View offers')
            ->assertJsonPath('data.0.cta_target', '/offers');

        $this->getJson(
            "/api/v1/notification-campaign-popups?channel=b2c&store_id={$storeB}&locale=ar&install_id=install-b",
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.store_id', $storeB);

        $this->getJson('/api/v1/notification-campaign-popups?channel=b2c&locale=en&install_id=missing-store')
            ->assertStatus(422);

        CarbonImmutable::setTestNow();
    }

    public function test_customer_popup_audience_and_once_per_user_are_server_authoritative(): void
    {
        $store = $this->store('B2C', 'POPUP-USER-STORE');
        $customer = User::query()->create([
            'name' => 'Popup Customer',
            'email' => 'popup-customer@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('b2c_customers')->insert([
            'user_id' => $customer->id,
            'store_id' => $store,
            'name' => 'Popup Customer',
            'email' => $customer->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $campaign = $this->launchCampaign([
            'name' => 'Customer once',
            'audience' => 'customer',
            'target_channel' => 'b2c',
            'store_id' => $store,
            'popup_frequency' => 'once_per_user',
        ]);

        $guestUrl = "/api/v1/notification-campaign-popups?channel=b2c&store_id={$store}&locale=en&install_id=guest-install";
        $this->getJson($guestUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($customer);
        $headers = ['Authorization' => 'Bearer popup-test-token'];
        $firstUrl = "/api/v1/notification-campaign-popups?channel=b2c&store_id={$store}&locale=en&install_id=user-install-a";

        $this->withHeaders($headers)->getJson($firstUrl)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $campaign->id);

        $this->withHeaders($headers)->postJson(
            "/api/v1/notification-campaign-popups/{$campaign->id}/events",
            [
                'event' => 'impression',
                'channel' => 'b2c',
                'store_id' => $store,
                'locale' => 'en',
                'install_id' => 'user-install-a',
            ],
        )->assertNoContent();

        $secondUrl = "/api/v1/notification-campaign-popups?channel=b2c&store_id={$store}&locale=en&install_id=user-install-b";
        $this->withHeaders($headers)->getJson($secondUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('notification_campaign_popup_views', [
            'campaign_id' => $campaign->id,
            'user_id' => $customer->id,
            'viewer_key' => hash('sha256', 'user:'.$customer->id),
            'impression_count' => 1,
        ]);
    }

    public function test_campaign_rejects_customer_driver_app_mismatch(): void
    {
        $mine = $this->store('B2C', 'APP-MISMATCH-MINE');
        $admin = $this->storeRoleUser($mine, 'B2C_STORE_ADMIN', 'app-mismatch-admin@example.test');

        $this->actingAs($admin)->post('/admin/notification-campaigns', $this->payload([
            'audience' => 'customer',
            'app' => 'driver',
            'target_channel' => 'b2c',
            'store_id' => $mine,
        ]))->assertSessionHasErrors('app');
    }

    public function test_recurring_campaign_creates_independent_notification_for_every_occurrence(): void
    {
        CarbonImmutable::setTestNow('2026-09-27 14:00:00');
        $campaign = NotificationCampaign::query()->create([
            'name' => 'Hourly offer',
            'type' => 'promotion',
            'title_ar' => 'عرض',
            'title_en' => 'Offer',
            'body_ar' => 'خصم',
            'body_en' => 'Discount',
            'audience' => 'customer',
            'app' => 'customer',
            'target_channel' => 'b2b',
            'delivery_channel' => 'in_app',
            'schedule_kind' => 'recurring',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now(),
            'interval_value' => 1,
            'interval_unit' => 'hour',
            'max_runs' => 2,
            'run_count' => 0,
            'next_run_at' => now(),
            'status' => 'active',
        ]);

        $dispatcher = app(NotificationCampaignDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue());

        $campaign->refresh();
        $firstNotification = (int) $campaign->last_notification_id;
        $this->assertSame(1, (int) $campaign->run_count);
        $this->assertSame('active', $campaign->status);
        $this->assertSame('2026-09-27 15:00:00', $campaign->next_run_at?->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow('2026-09-27 15:00:00');
        $this->assertSame(1, $dispatcher->dispatchDue());

        $campaign->refresh();
        $secondNotification = (int) $campaign->last_notification_id;
        $this->assertNotSame($firstNotification, $secondNotification);
        $this->assertSame(2, (int) $campaign->run_count);
        $this->assertSame('completed', $campaign->status);
        $this->assertNull($campaign->next_run_at);
        $this->assertDatabaseCount('notification_campaign_runs', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(
            2,
            DB::table('audit_logs')->where('event', 'notification_campaign.dispatched')->count(),
        );
        $this->assertDatabaseHas('notifications', [
            'id' => $firstNotification,
            'type' => 'promotion',
            'target_channel' => 'b2b',
            'status' => 'published',
        ]);

        CarbonImmutable::setTestNow();
    }

    public function test_one_time_campaign_completes_and_does_not_dispatch_twice(): void
    {
        CarbonImmutable::setTestNow('2026-09-27 14:30:00');
        $campaign = NotificationCampaign::query()->create([
            'name' => 'One time',
            'type' => 'promotion',
            'title_ar' => 'مرة',
            'title_en' => 'Once',
            'body_ar' => 'مرة واحدة',
            'body_en' => 'One time',
            'audience' => 'all',
            'app' => 'all',
            'target_channel' => 'b2b',
            'delivery_channel' => 'in_app',
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now(),
            'next_run_at' => now(),
            'status' => 'active',
        ]);

        $dispatcher = app(NotificationCampaignDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue());
        $this->assertSame(0, $dispatcher->dispatchDue());

        $campaign->refresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1, (int) $campaign->run_count);
        $this->assertDatabaseCount('notification_campaign_runs', 1);

        $admin = $this->globalRoleUser('SUPER_ADMIN', 'campaign-super@example.test');
        $this->actingAs($admin)
            ->post("/admin/notification-campaigns/{$campaign->id}/send-now")
            ->assertStatus(409);
        $this->assertDatabaseCount('notifications', 1);

        CarbonImmutable::setTestNow();
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Campaign',
            'title_ar' => 'عرض اليوم',
            'title_en' => 'Today offer',
            'body_ar' => 'خصم خاص',
            'body_en' => 'Special discount',
            'audience' => 'customer',
            'app' => 'customer',
            'target_channel' => 'b2b',
            'delivery_channel' => 'both',
            'popup_frequency' => 'once_per_session',
            'schedule_kind' => 'once',
            'starts_at' => '2026-09-28T10:00',
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function launchCampaign(array $overrides = []): NotificationCampaign
    {
        return NotificationCampaign::query()->create([
            'name' => 'Launch campaign',
            'type' => 'promotion',
            'title_ar' => 'عرض التطبيق',
            'title_en' => 'App offer',
            'body_ar' => 'عرض متاح الآن',
            'body_en' => 'Offer available now',
            'audience' => 'all',
            'app' => 'customer',
            'target_channel' => 'all',
            'delivery_channel' => 'both',
            'popup_frequency' => 'once_per_session',
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'next_run_at' => now(),
            'status' => 'active',
            ...$overrides,
        ]);
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalRoleUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeRoleUser(int $storeId, string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
