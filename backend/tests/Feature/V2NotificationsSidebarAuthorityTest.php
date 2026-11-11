<?php

namespace Tests\Feature;

use App\Models\NotificationCampaign;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2NotificationsSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_retail_manager_reaches_existing_campaign_workflow_in_both_locales(): void
    {
        $store = $this->store('NOTIFY-MANAGE');
        $manager = $this->storeUser($store, 'manage-notifications@example.test');

        foreach (['ar' => 'الحملات الإعلانية المجدولة', 'en' => 'Scheduled Promotional Campaigns'] as $locale => $title) {
            $manager->forceFill(['locale' => $locale])->save();
            $this->assertCampaignLink($manager);

            $this->actingAs($manager)->get(route('admin.notification-campaigns.index'))
                ->assertOk()
                ->assertSee($title)
                ->assertSee('js-campaign-form');
        }

        // A manager reaches the real server-side validation workflow, not a dead link.
        $this->actingAs($manager)->post(route('admin.notification-campaigns.store'), [])
            ->assertSessionHasErrors(['name', 'title_ar', 'title_en']);
    }

    public function test_store_scoped_view_only_actor_can_read_own_campaigns_but_not_mutate_or_review_send(): void
    {
        $store = $this->store('NOTIFY-VIEW');
        $foreignStore = $this->store('NOTIFY-FOREIGN');
        $viewer = $this->storeUser($store, 'view-only-notifications@example.test');
        $this->makeStoreRoleViewOnly();

        $own = $this->campaign($store, 'Visible retail notification', $viewer);
        $foreign = $this->campaign($foreignStore, 'Foreign retail notification', $viewer);
        $this->assertCampaignLink($viewer);

        foreach (['ar', 'en'] as $locale) {
            $viewer->forceFill(['locale' => $locale])->save();

            $this->actingAs($viewer)->get(route('admin.notification-campaigns.index', [
                'review_campaign' => $own->id,
                'review_action' => 'send_now',
            ]))
                ->assertOk()
                ->assertSee('Visible retail notification')
                ->assertDontSee('Foreign retail notification')
                ->assertDontSee('action="'.route('admin.notification-campaigns.store').'"', false)
                ->assertDontSee('action="'.route('admin.notification-campaigns.update', $own).'"', false)
                ->assertDontSee('id="campaign-review"', false);
        }

        $this->actingAs($viewer)->post(route('admin.notification-campaigns.store'), [])
            ->assertForbidden();
        $this->actingAs($viewer)->patch(route('admin.notification-campaigns.update', $own), [])
            ->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.notification-campaigns.send-now', $own))
            ->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.notification-campaigns.state', $own), [
            'state' => 'active',
        ])->assertForbidden();

        // A read grant cannot be used to inspect or mutate another store's record.
        $this->actingAs($viewer)->patch(route('admin.notification-campaigns.update', $foreign), [])
            ->assertForbidden();
        $this->assertSame('draft', $own->fresh()->status);
        $this->assertSame('draft', $foreign->fresh()->status);
    }

    public function test_disabled_retail_advertising_hides_campaign_link_and_denies_direct_http(): void
    {
        $store = $this->store('NOTIFY-DISABLED', false);
        $manager = $this->storeUser($store, 'disabled-notifications@example.test');

        $this->assertNoCampaignLink($manager);
        $this->actingAs($manager)->get(route('admin.notification-campaigns.index'))
            ->assertForbidden();
        $this->actingAs($manager)->post(route('admin.notification-campaigns.store'), [])
            ->assertForbidden();
    }

    public function test_notifications_center_remains_super_admin_only_and_not_store_manager_shortcut(): void
    {
        $store = $this->store('NOTIFY-CENTER');
        $manager = $this->storeUser($store, 'center-notifications@example.test');
        $this->actingAs($manager)->get(route('admin.notifications.index'))->assertForbidden();

        $this->assertFalse($this->navigationItems($manager)->contains('key', 'notifications_center'));

        $admin = User::query()->create([
            'name' => 'Notifications Super Admin',
            'email' => 'super-notifications@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $this->assertTrue($this->navigationItems($admin)->contains('key', 'notifications_center'));
        $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk();
    }

    private function assertCampaignLink(User $user): void
    {
        $links = $this->navigationItems($user)->where('key', 'notification_campaigns');
        $this->assertCount(1, $links);
        $this->assertSame('admin.notification-campaigns.index', $links->first()['route']);
    }

    private function assertNoCampaignLink(User $user): void
    {
        $this->assertFalse($this->navigationItems($user)->contains('key', 'notification_campaigns'));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function navigationItems(User $user): Collection
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group) => $group['children'] ?? [])
            ->values();
    }

    private function makeStoreRoleViewOnly(): void
    {
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $viewId = DB::table('permissions')->where('code', 'notifications.view')->value('id');
        $manageId = DB::table('permissions')->where('code', 'notifications.manage')->value('id');

        $this->assertNotNull($viewId);
        $this->assertNotNull($manageId);
        DB::table('permission_role')->where('role_id', $role->id)->where('permission_id', $manageId)->delete();
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $role->id,
            'permission_id' => $viewId,
        ]);
    }

    private function store(string $code, bool $advertising = true): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => $advertising,
            'live_ads_enabled' => $advertising,
            'coupons_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeUser(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function campaign(int $storeId, string $name, User $actor): NotificationCampaign
    {
        return NotificationCampaign::query()->create([
            'name' => $name,
            'type' => 'promotion',
            'title_ar' => 'إشعار تجريبي',
            'title_en' => $name,
            'body_ar' => 'نص تجريبي',
            'body_en' => 'Test content',
            'audience' => 'customer',
            'app' => 'customer',
            'target_channel' => 'b2c',
            'delivery_channel' => 'in_app',
            'store_id' => $storeId,
            'created_by' => $actor->id,
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now(),
            'next_run_at' => now(),
            'status' => 'draft',
        ]);
    }
}
