<?php

namespace Tests\Feature;

use App\Models\LiveAd;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P235: actual Live Ads discoverability must agree with the protected
 * management route, retail feature entitlement and public customer feed.
 * This exercises canonical policies without adding a second Sidebar gate.
 */
final class V2LiveAdsNavigationHttpBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_and_retail_navigation_matches_the_real_scoped_dashboard_and_customer_feed_in_ar_en(): void
    {
        $storeA = $this->retailStore('P235-A');
        $storeB = $this->retailStore('P235-B');
        $wholesale = $this->globalActor('B2B_ADMIN', 'wholesale');
        $retail = $this->retailActor($storeA, 'retail');

        $this->ad('b2b', null, 'P235-Wholesale', 'Wholesale live ad');
        $mine = $this->ad('b2c', $storeA, 'P235-StoreA', 'Store A live ad');
        $this->ad('b2c', $storeB, 'P235-StoreB', 'Store B live ad');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $wholesale->forceFill(['locale' => $locale])->save();
            $retail->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $wholesaleItem = $this->advertisingItem($wholesale->fresh(), 'live_ads');
            $this->assertNotNull($wholesaleItem);
            $this->assertSame('admin.live-ads.index', $wholesaleItem['route']);
            $this->actingAs($wholesale)
                ->get(route('admin.live-ads.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P235-Wholesale')
                ->assertDontSee('P235-StoreA')
                ->assertDontSee('P235-StoreB');

            $retailItem = $this->advertisingItem($retail->fresh(), 'live_ads');
            $this->assertNotNull($retailItem);
            $this->assertSame('admin.live-ads.index', $retailItem['route']);
            $this->actingAs($retail)
                ->get(route('admin.live-ads.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P235-StoreA')
                ->assertDontSee('P235-Wholesale')
                ->assertDontSee('P235-StoreB');

            // The existing Customer API keeps its own server-side store fence.
            $this->getJson('/api/v1/live-ads?channel=b2c&store_id='.$storeA.'&locale='.$locale)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $mine->id)
                ->assertJsonPath('data.0.title', $locale === 'ar' ? 'إعلان المتجر أ' : 'Store A live ad');
        }
    }

    public function test_store_disabled_live_ads_removes_sidebar_and_rejects_http_without_leaking_other_store_actions(): void
    {
        $storeA = $this->retailStore('P235-TOGGLE-A');
        $storeB = $this->retailStore('P235-TOGGLE-B');
        $actor = $this->retailActor($storeA, 'toggle');
        $own = $this->ad('b2c', $storeA, 'P235-OWN', 'Own live ad');
        $foreign = $this->ad('b2c', $storeB, 'P235-FOREIGN', 'Foreign live ad');

        $this->actingAs($actor)
            ->patch('/admin/live-ads/'.$foreign->id.'/toggle')
            ->assertNotFound();
        $this->assertDatabaseHas('live_ads', ['id' => $foreign->id, 'is_active' => true]);

        $this->actingAs($actor)
            ->patch('/admin/live-ads/'.$own->id.'/toggle')
            ->assertRedirect();
        $this->assertDatabaseHas('live_ads', ['id' => $own->id, 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'live_ad.status_changed',
            'user_id' => $actor->id,
        ]);

        DB::table('stores')->where('id', $storeA)->update([
            'live_ads_enabled' => false,
            'advertising_enabled' => false,
            'coupons_enabled' => false,
        ]);

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->assertNull($this->advertisingItem($actor->fresh(), 'live_ads'));
            $this->actingAs($actor)
                ->get(route('admin.live-ads.index', [], false))
                ->assertForbidden();
            $this->actingAs($actor)
                ->patch('/admin/live-ads/'.$own->id.'/toggle')
                ->assertForbidden();
        }

        $this->getJson('/api/v1/live-ads?channel=b2c&store_id='.$storeA.'&locale=en')
            ->assertNotFound();
        $this->assertDatabaseHas('live_ads', ['id' => $foreign->id, 'is_active' => true]);
        $this->assertDatabaseHas('live_ads', ['id' => $own->id, 'is_active' => false]);
    }

    public function test_forced_visible_sidebar_cannot_override_explicit_wholesale_deny_or_inactive_role(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $denied = $this->globalActor('B2B_ADMIN', 'denied');
        $permission = Permission::query()->where('code', 'live_ads.view')->firstOrFail();

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'live_ads',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $denied->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $denied->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            $denied->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->assertFalse($denied->fresh()->hasPermission('live_ads.view'));
            $this->assertNull($this->advertisingItem($denied->fresh(), 'live_ads'));
            $this->actingAs($denied)
                ->get(route('admin.live-ads.index', [], false))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'live_ads',
            'is_visible' => true,
        ]);

        $inactive = $this->globalActor('B2B_ADMIN', 'inactive');
        $role->forceFill(['is_active' => false])->save();

        $this->assertNull($this->advertisingItem($inactive->fresh(), 'live_ads'));
        $this->actingAs($inactive)
            ->get(route('admin.live-ads.index', [], false))
            ->assertForbidden();
    }

    private function globalActor(string $roleCode, string $suffix): User
    {
        $actor = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
            'email' => 'v2-p235-'.$suffix.'@example.test',
        ]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function retailActor(int $storeId, string $suffix): User
    {
        $actor = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
            'email' => 'v2-p235-'.$suffix.'@example.test',
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'live_ads_enabled' => true,
            'coupons_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ad(string $channel, ?int $storeId, string $name, string $titleEn): LiveAd
    {
        return LiveAd::query()->create([
            'scope_key' => $channel === 'b2c' ? 'b2c:'.$storeId : 'b2b',
            'channel' => $channel,
            'store_id' => $storeId,
            'name' => $name,
            'title_ar' => $channel === 'b2b' ? 'إعلان الجملة' : ($name === 'P235-StoreA' ? 'إعلان المتجر أ' : 'إعلان المتجر ب'),
            'title_en' => $titleEn,
            'body_ar' => 'نص الإعلان',
            'body_en' => 'Live ad content',
            'frequency' => 'once_per_session',
            'is_dismissible' => true,
            'priority' => 10,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function advertisingItem(User $actor, string $key): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === $key) {
                    return $item;
                }
            }
        }

        return null;
    }
}
