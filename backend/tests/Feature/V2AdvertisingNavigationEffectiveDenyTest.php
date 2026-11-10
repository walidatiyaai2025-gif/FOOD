<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T037R02 / #2788: Sidebar advertising discovery uses the same effective
 * per-user scope as the protected HTTP endpoints, not an inherited role OR.
 */
final class V2AdvertisingNavigationEffectiveDenyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const ABILITIES = ['notifications.view', 'live_ads.view', 'coupons.view'];

    /** @var list<string> */
    private const NAV_KEYS = ['notification_campaigns', 'live_ads', 'coupons'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_global_denies_override_inherited_store_roles_and_real_http_in_both_locales(): void
    {
        $storeId = $this->store('V2-2788-GLOBAL');
        $actor = $this->retailActor([$storeId], 'global-deny@example.test');

        foreach (self::NAV_KEYS as $key) {
            $this->assertArrayHasKey($key, $this->visibleItems($actor->fresh()));
        }

        foreach (self::ABILITIES as $ability) {
            $this->deny($actor, $ability, 'global');
        }

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $items = $this->visibleItems($actor->fresh());
            foreach (self::NAV_KEYS as $key) {
                $this->assertArrayNotHasKey($key, $items, "{$key} leaked despite global DENY in {$locale}");
            }

            $this->actingAs($actor->fresh())->get(route('admin.notification-campaigns.index'))->assertForbidden();
            $this->actingAs($actor->fresh())->get(route('admin.coupons.index'))->assertForbidden();
        }
    }

    public function test_store_denies_hide_only_when_no_other_eligible_retail_store_remains(): void
    {
        $blocked = $this->store('V2-2788-BLOCKED');
        $allowed = $this->store('V2-2788-ALLOWED');
        $actor = $this->retailActor([$blocked, $allowed], 'store-deny@example.test');

        foreach (self::ABILITIES as $ability) {
            $this->deny($actor, $ability, 'store:'.$blocked, $blocked);
        }

        $items = $this->visibleItems($actor->fresh());
        foreach (self::NAV_KEYS as $key) {
            $this->assertArrayHasKey($key, $items, "{$key} must remain discoverable through Store B");
        }

        $this->actingAs($actor->fresh())->get(route('admin.notification-campaigns.index'))->assertOk();
        $this->actingAs($actor->fresh())->get(route('admin.coupons.index'))->assertOk();

        foreach (self::ABILITIES as $ability) {
            $this->deny($actor, $ability, 'store:'.$allowed, $allowed);
        }

        $items = $this->visibleItems($actor->fresh());
        foreach (self::NAV_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $items, "{$key} must disappear when every store is denied");
        }
        $this->actingAs($actor->fresh())->get(route('admin.notification-campaigns.index'))->assertForbidden();
        $this->actingAs($actor->fresh())->get(route('admin.coupons.index'))->assertForbidden();
    }

    public function test_feature_flags_and_missing_store_roles_do_not_create_advertising_links(): void
    {
        $storeId = $this->store('V2-2788-OFF', false);
        $actor = $this->retailActor([$storeId], 'feature-off@example.test');

        foreach (self::NAV_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $this->visibleItems($actor->fresh()));
        }
        $this->actingAs($actor)->get(route('admin.notification-campaigns.index'))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.coupons.index'))->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        foreach (self::NAV_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $this->visibleItems($roleless));
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function visibleItems(User $actor): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->keyBy('key')
            ->all();
    }

    /** @param  list<int>  $storeIds */
    private function retailActor(array $storeIds, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        // A disposable fixture explicitly grants all three abilities; the
        // assertions must prove that per-user DENY wins over a real role grant.
        foreach (self::ABILITIES as $ability) {
            $permissionId = DB::table('permissions')->where('code', $ability)->value('id');
            $this->assertNotNull($permissionId, $ability.' must be a defined permission');
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $role->getKey(),
                'permission_id' => $permissionId,
            ]);
        }

        foreach ($storeIds as $id) {
            DB::table('user_store_roles')->insert([
                'user_id' => $user->getKey(),
                'store_id' => $id,
                'role_id' => $role->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function store(string $code, bool $enabled = true): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => $enabled,
            'live_ads_enabled' => $enabled,
            'coupons_enabled' => $enabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deny(User $actor, string $ability, string $scopeKey, ?int $storeId = null): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => DB::table('permissions')->where('code', $ability)->value('id'),
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
