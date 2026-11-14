<?php

namespace Tests\Feature;

use App\Models\MarketingCoupon;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2CouponLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_coupon_search_treats_symbols_as_literal_across_code_and_localized_names(): void
    {
        $this->actingAs($this->actor('SUPER_ADMIN', 'v2-coupon-super@example.test'));

        $code = $this->coupon('b2b', null, 'CODE_UNDERSCORE', [
            'name_ar' => 'عنوان اعتيادي',
            'name_en' => 'Normal name',
        ]);
        $arabic = $this->coupon('b2b', null, 'AR-COUPON', [
            'name_ar' => 'خصم %_! بالعربية',
        ]);
        $english = $this->coupon('b2b', null, 'EN-COUPON', [
            'name_en' => 'Deal %_! English',
        ]);
        $lookalike = $this->coupon('b2b', null, 'WILDCARD', ['name_en' => 'Unrelated !']);
        $this->coupon('b2b', null, 'PLAIN');

        $auditBefore = DB::table('audit_logs')->count();

        $this->assertResults(['q' => '%_!'], [$arabic->id, $english->id]);
        $this->assertResults(['q' => '%'], [$arabic->id, $english->id]);
        $this->assertResults(['q' => '_'], [$code->id, $arabic->id, $english->id]);
        $this->assertResults(['q' => '!'], [$arabic->id, $english->id, $lookalike->id]);
        $this->assertResults(['q' => 'CODE'], [$code->id]);
        $this->assertResults(['q' => 'خصم'], [$arabic->id]);
        $this->assertResults(['q' => 'Deal'], [$english->id]);

        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('marketing_coupons', [
            'id' => $code->id,
            'code' => 'CODE_UNDERSCORE',
            'is_active' => true,
        ]);
    }

    public function test_store_scoped_coupon_search_keeps_channel_status_and_foreign_store_exclusion(): void
    {
        $storeA = $this->retailStore('V2-CUP-LITERAL-A');
        $storeB = $this->retailStore('V2-CUP-LITERAL-B');

        $mine = $this->coupon('b2c', $storeA, 'MINE_VALID', ['name_en' => 'Mine %_!']);
        $inactive = $this->coupon('b2c', $storeA, 'OFF_VALID', ['name_ar' => 'غير نشط %_!', 'is_active' => false]);
        $foreign = $this->coupon('b2c', $storeB, 'FOREIGN_VALID', ['name_en' => 'Foreign %_!']);
        $wholesale = $this->coupon('b2b', null, 'B2B_VALID', ['name_en' => 'Wholesale %_!']);

        $this->actingAs($this->retailAdmin($storeA));
        $this->assertResults(['q' => '%_!', 'channel' => 'b2c', 'status' => 'active'], [$mine->id]);
        $this->assertResults(['q' => '%_!', 'status' => 'inactive'], [$inactive->id]);
        $this->assertResults(['q' => '%_!', 'channel' => 'b2b'], []);

        $this->actingAs($this->actor('SUPER_ADMIN', 'v2-coupon-platform@example.test'));
        $this->assertResults(['q' => '%_!', 'channel' => 'b2b'], [$wholesale->id]);
        $this->assertResults(['q' => '%_!', 'channel' => 'b2c', 'status' => 'active'], [$mine->id, $foreign->id]);
        $this->assertDatabaseHas('marketing_coupons', [
            'id' => $foreign->id,
            'store_id' => $storeB,
        ]);
    }

    public function test_unauthorized_actor_cannot_read_coupon_search(): void
    {
        $user = User::query()->create([
            'name' => 'Coupon Denied',
            'email' => 'v2-coupon-denied@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.coupons.index', ['q' => '%_!']))
            ->assertForbidden();
    }

    /**
     * @param  array<string, string>  $filters
     * @param  list<int>  $expectedIds
     */
    private function assertResults(array $filters, array $expectedIds): void
    {
        $this->get(route('admin.coupons.index', $filters))
            ->assertOk()
            ->assertViewHas('coupons', static function ($page) use ($expectedIds): bool {
                $actual = $page->getCollection()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                sort($actual);
                sort($expectedIds);

                return $actual === $expectedIds;
            });
    }

    private function actor(string $roleCode, string $email): User
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

    private function retailAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Retail Coupon Manager',
            'email' => 'v2-retail-coupon-literal@example.test',
            'password' => 'password',
            'locale' => 'ar',
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

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'coupons_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function coupon(string $channel, ?int $storeId, string $code, array $overrides = []): MarketingCoupon
    {
        return MarketingCoupon::query()->create([
            'scope_key' => $channel === 'b2c' ? 'b2c:'.$storeId : 'b2b',
            'channel' => $channel,
            'store_id' => $storeId,
            'code' => $code,
            'name_ar' => 'عنوان '.$code,
            'name_en' => 'Title '.$code,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'usage_limit_total' => 100,
            'usage_limit_per_user' => 1,
            'first_order_only' => false,
            'is_active' => true,
            'used_count' => 0,
            ...$overrides,
        ]);
    }
}
