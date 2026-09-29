<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EngagementOperationsBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_guest_customer_install_can_register_push_before_login(): void
    {
        $this->postJson('/api/v1/push/devices/guest', [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'guest-fcm-token-417',
            'install_id' => 'install-417',
            'target_channel' => 'all',
            'locale' => 'ar',
        ])->assertOk()->assertJsonPath('data.app', 'customer');

        $this->assertDatabaseHas('push_device_tokens', [
            'user_id' => null,
            'app' => 'customer',
            'install_id' => 'install-417',
            'is_active' => 1,
        ]);
    }

    public function test_live_ad_image_and_store_feature_scope_are_exposed_to_customer_app(): void
    {
        Storage::fake('public');
        $store = $this->store('B2C', 'LIVE-ADS-417');
        DB::table('stores')->where('id', $store)->update(['live_ads_enabled' => true]);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->post('/admin/live-ads', [
            'name' => 'Launch',
            'channel' => 'b2c',
            'store_id' => $store,
            'title_ar' => 'عرض حي',
            'title_en' => 'Live offer',
            'body_ar' => 'اختبار',
            'body_en' => 'Test',
            'image' => UploadedFile::fake()->image('ad.jpg', 800, 600),
            'frequency' => 'once_per_session',
            'priority' => 10,
            'is_dismissible' => 1,
            'is_active' => 1,
        ])->assertRedirect();

        $this->getJson('/api/v1/live-ads?channel=b2c&store_id='.$store.'&locale=en')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Live offer')
            ->assertJsonPath('data.0.frequency', 'once_per_session')
            ->assertJsonPath('data.0.store_id', $store)
            ->assertJsonPath('data.0.image_url', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_operations_order_page_is_platform_and_store_scoped(): void
    {
        $mine = $this->store('B2C', 'OPS-MINE-417');
        $other = $this->store('B2C', 'OPS-OTHER-417');
        $admin = $this->storeAdmin($mine);

        $mineOrder = $this->order($mine, 'OPS-417-MINE');
        $this->order($other, 'OPS-417-OTHER');

        $response = $this->actingAs($admin)->get('/admin/operations/orders');
        $response->assertOk()
            ->assertSee('OPS-417-MINE')
            ->assertDontSee('OPS-417-OTHER')
            ->assertSee('Order Management');

        $this->actingAs($admin)
            ->get('/admin/operations/orders?store_id='.$other)
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/operations/orders?order='.$mineOrder)
            ->assertOk()
            ->assertSee('Order status timeline');
    }

    public function test_platform_logo_asset_is_packaged_in_public_brand_path(): void
    {
        $this->assertFileExists(public_path('brand/foodex-economical-group.webp'));
        $this->assertGreaterThan(0, filesize(public_path('brand/foodex-economical-group.webp')));
    }

    private function order(int $storeId, string $number): int
    {
        $customer = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => $number.' Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $b2c = (int) DB::table('b2c_customers')->insertGetId([
            'legacy_customer_id' => $customer,
            'store_id' => $storeId,
            'name' => $number.' Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customer,
            'b2c_customer_id' => $b2c,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => 100,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Super Admin',
            'email' => 'engagement-super@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Store Admin',
            'email' => 'engagement-store@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
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
