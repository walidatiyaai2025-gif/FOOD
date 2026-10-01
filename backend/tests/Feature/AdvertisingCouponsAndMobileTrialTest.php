<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvertisingCouponsAndMobileTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_feature_switches_gate_advertising_and_coupons(): void
    {
        $storeId = $this->store('B2C', 'FEATURE-OFF', false, false);
        $admin = $this->storeRoleUser($storeId, 'B2C_STORE_ADMIN', 'feature-off@example.test');

        $this->actingAs($admin)->get('/admin/coupons')->assertForbidden();
        $this->actingAs($admin)->get('/admin/notification-campaigns')->assertForbidden();

        $advertising = collect(app(AdminNavigation::class)->groupsFor($admin))
            ->firstWhere('key', 'advertising');
        $this->assertNull($advertising);

        DB::table('stores')->where('id', $storeId)->update([
            'advertising_enabled' => true,
            'coupons_enabled' => true,
        ]);

        $this->actingAs($admin)->get('/admin/coupons')->assertOk();
        $this->actingAs($admin)->get('/admin/notification-campaigns')->assertOk();

        $advertising = collect(app(AdminNavigation::class)->groupsFor($admin))
            ->firstWhere('key', 'advertising');
        $this->assertNotNull($advertising);
        $this->assertSame(
            ['notification_campaigns', 'coupons'],
            collect($advertising['children'])->pluck('key')->values()->all(),
        );
    }

    public function test_coupon_index_renders_coupons_without_optional_dates(): void
    {
        $admin = $this->globalRoleUser('B2B_ADMIN', 'coupon-null-dates@example.test');

        $this->actingAs($admin)->post('/admin/coupons', $this->couponPayload([
            'channel' => 'b2b',
            'store_id' => null,
            'code' => 'NO-DATES',
            'starts_at' => null,
            'ends_at' => null,
        ]))->assertRedirect();

        $this->actingAs($admin)
            ->get('/admin/coupons')
            ->assertOk()
            ->assertSee('NO-DATES');
    }

    public function test_coupon_codes_are_isolated_between_wholesale_and_each_retail_store(): void
    {
        $storeA = $this->store('B2C', 'COUPON-A');
        $storeB = $this->store('B2C', 'COUPON-B');
        $b2bAdmin = $this->globalRoleUser('B2B_ADMIN', 'coupon-b2b@example.test');
        $retailA = $this->storeRoleUser($storeA, 'B2C_STORE_ADMIN', 'coupon-a@example.test');
        $retailB = $this->storeRoleUser($storeB, 'B2C_STORE_ADMIN', 'coupon-b@example.test');

        $this->actingAs($b2bAdmin)->post('/admin/coupons', $this->couponPayload([
            'channel' => 'b2b',
            'store_id' => null,
            'code' => 'WELCOME10',
        ]))->assertRedirect();

        $this->actingAs($retailA)->post('/admin/coupons', $this->couponPayload([
            'channel' => 'b2c',
            'store_id' => $storeA,
            'code' => 'WELCOME10',
        ]))->assertRedirect();

        $this->actingAs($retailB)->post('/admin/coupons', $this->couponPayload([
            'channel' => 'b2c',
            'store_id' => $storeB,
            'code' => 'WELCOME10',
        ]))->assertRedirect();

        $this->assertDatabaseHas('marketing_coupons', ['scope_key' => 'b2b', 'code' => 'WELCOME10']);
        $this->assertDatabaseHas('marketing_coupons', ['scope_key' => 'b2c:'.$storeA, 'code' => 'WELCOME10']);
        $this->assertDatabaseHas('marketing_coupons', ['scope_key' => 'b2c:'.$storeB, 'code' => 'WELCOME10']);
        $this->assertDatabaseCount('marketing_coupons', 3);

        $this->actingAs($retailA)->post('/admin/coupons', $this->couponPayload([
            'channel' => 'b2b',
            'store_id' => null,
            'code' => 'RETAIL-CANNOT-WHOLESALE',
        ]))->assertForbidden();
    }

    public function test_mobile_trial_login_accepts_username_only_and_keeps_app_roles_separate(): void
    {
        $storeId = $this->store('B2C', 'TRIAL');

        $customer = User::query()->create([
            'name' => 'Pilot Customer',
            'username' => 'pilot.customer',
            'email' => 'pilot.customer@example.test',
            'password' => 'unused-password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('b2c_customers')->insert([
            'user_id' => $customer->id,
            'store_id' => $storeId,
            'name' => 'Pilot Customer',
            'email' => $customer->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerLogin = $this->postJson('/api/v1/auth/mobile-trial', [
            'username' => 'pilot.customer',
            'app' => 'customer',
        ])->assertOk()
            ->assertJsonPath('trial_username_login', true)
            ->assertJsonPath('user.username', 'pilot.customer');

        $this->assertNotEmpty($customerLogin->json('token'));

        $driver = User::query()->create([
            'name' => 'Pilot Driver',
            'username' => 'pilot.driver',
            'email' => 'pilot.driver@example.test',
            'password' => 'unused-password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $driver->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());
        DB::table('drivers')->insert([
            'user_id' => $driver->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/mobile-trial', [
            'username' => 'pilot.driver',
            'app' => 'driver',
        ])->assertOk()
            ->assertJsonPath('user.username', 'pilot.driver');

        $this->postJson('/api/v1/auth/mobile-trial', [
            'username' => 'pilot.customer',
            'app' => 'driver',
        ])->assertUnprocessable();
    }

    /** @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function couponPayload(array $overrides = []): array
    {
        return [
            'channel' => 'b2b',
            'code' => 'WELCOME10',
            'name_ar' => 'خصم ترحيبي',
            'name_en' => 'Welcome discount',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'usage_limit_total' => 100,
            'usage_limit_per_user' => 1,
            'first_order_only' => 0,
            'is_active' => 1,
            ...$overrides,
        ];
    }

    private function store(
        string $type,
        string $code,
        bool $advertising = true,
        bool $coupons = true,
    ): int {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => $advertising,
            'live_ads_enabled' => $advertising,
            'coupons_enabled' => $coupons,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalRoleUser(string $roleCode, string $email): User
    {
        $user = $this->user($email);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeRoleUser(int $storeId, string $roleCode, string $email): User
    {
        $user = $this->user($email);
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
}
