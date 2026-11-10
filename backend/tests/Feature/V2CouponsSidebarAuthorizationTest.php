<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2CouponsSidebarAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_coupon_navigation_and_http_respect_exact_store_denials(): void
    {
        $storeA = $this->store('V2-COUPON-DENIED');
        $storeB = $this->store('V2-COUPON-ALLOWED');
        $owner = $this->globalUser('SUPER_ADMIN', 'v2-coupon-root@example.test');

        $this->actingAs($owner)->post(route('admin.coupons.store'), $this->payload('DENIED-A', $storeA))->assertRedirect();
        $this->actingAs($owner)->post(route('admin.coupons.store'), $this->payload('VISIBLE-B', $storeB))->assertRedirect();

        $retail = $this->retailUser([$storeA, $storeB], 'v2-coupon-retail@example.test');
        $this->deny($retail, 'coupons.view', 'store:'.$storeA, $storeA);
        $this->deny($retail, 'coupons.manage', 'store:'.$storeA, $storeA);
        $retail->refresh();

        $groups = collect(app(AdminNavigation::class)->groupsFor($retail));
        $couponItem = $groups->flatMap(fn (array $group) => $group['children'])
            ->firstWhere('key', 'coupons');
        $this->assertIsArray($couponItem);
        $this->assertSame('admin.coupons.index', $couponItem['route']);

        $this->actingAs($retail)
            ->get(route('admin.coupons.index'))
            ->assertOk()
            ->assertDontSee('DENIED-A')
            ->assertSee('VISIBLE-B');

        $this->actingAs($retail)
            ->post(route('admin.coupons.store'), $this->payload('BLOCKED-CREATE', $storeA))
            ->assertNotFound();

        $deniedCouponId = (int) DB::table('marketing_coupons')->where('code', 'DENIED-A')->value('id');
        $allowedCouponId = (int) DB::table('marketing_coupons')->where('code', 'VISIBLE-B')->value('id');

        $this->actingAs($retail)
            ->patch(route('admin.coupons.toggle', $deniedCouponId))
            ->assertNotFound();
        $this->actingAs($retail)
            ->patch(route('admin.coupons.toggle', $allowedCouponId))
            ->assertRedirect();
        $this->assertDatabaseHas('marketing_coupons', ['id' => $deniedCouponId, 'is_active' => true]);
        $this->assertDatabaseHas('marketing_coupons', ['id' => $allowedCouponId, 'is_active' => false]);
    }

    public function test_global_deny_revokes_coupon_dashboard_http_even_with_retail_role(): void
    {
        $store = $this->store('V2-COUPON-GLOBAL-DENY');
        $retail = $this->retailUser([$store], 'v2-coupon-deny-global@example.test');

        $this->actingAs($retail)->get(route('admin.coupons.index'))->assertOk();

        $this->deny($retail, 'coupons.view', 'global');
        $this->deny($retail, 'coupons.manage', 'global');
        $retail->refresh();

        $this->actingAs($retail)->get(route('admin.coupons.index'))->assertForbidden();
        $this->actingAs($retail)
            ->post(route('admin.coupons.store'), $this->payload('GLOBAL-DENIED', $store))
            ->assertForbidden();
        $this->assertDatabaseMissing('marketing_coupons', ['code' => 'GLOBAL-DENIED']);
    }

    public function test_coupon_store_feature_and_locale_keep_consistent_authorized_navigation(): void
    {
        $store = $this->store('V2-COUPON-FEATURE');
        $retail = $this->retailUser([$store], 'v2-coupon-locale@example.test');

        $this->actingAs($retail)->get(route('admin.coupons.index'))
            ->assertOk()
            ->assertSee('dir="ltr"', false);
        $retail->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($retail)->get(route('admin.coupons.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false);

        DB::table('stores')->where('id', $store)->update(['coupons_enabled' => false]);

        $this->actingAs($retail)->get(route('admin.coupons.index'))->assertForbidden();
        $groups = collect(app(AdminNavigation::class)->groupsFor($retail));
        $this->assertFalse(
            $groups->flatMap(fn (array $group) => $group['children'])->contains('key', 'coupons'),
        );
    }

    /** @param list<int> $storeIds */
    private function retailUser(array $storeIds, string $email): User
    {
        $user = $this->user($email);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $user->id,
                'store_id' => $storeId,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = $this->user($email);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'live_ads_enabled' => false,
            'coupons_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deny(User $user, string $permission, string $scopeKey, ?int $storeId = null): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => DB::table('permissions')->where('code', $permission)->value('id'),
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->forceFill(['has_permission_overrides' => true])->save();
    }

    /** @return array<string, mixed> */
    private function payload(string $code, int $storeId): array
    {
        return [
            'channel' => 'b2c',
            'store_id' => $storeId,
            'code' => $code,
            'name_ar' => 'خصم',
            'name_en' => 'Discount',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => 1,
        ];
    }
}
