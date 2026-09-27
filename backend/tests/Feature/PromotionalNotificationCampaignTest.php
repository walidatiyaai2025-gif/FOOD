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
            'schedule_kind' => 'once',
            'starts_at' => '2026-09-28T10:00',
            ...$overrides,
        ];
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
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
