<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2QuantityTierAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_foreign_wholesale_store_cannot_be_repriced_using_global_b2b_permission(): void
    {
        $canonicalStore = app(WholesalePrincipal::class)->storeId();
        $foreignStore = $this->store('B2B', 'V2-QT-FOREIGN');
        $foreignProduct = $this->product($foreignStore, 'b2b');
        $tierId = $this->tier();
        $this->setPricingMode($canonicalStore, true);
        $this->setPricingMode($foreignStore, true);

        $admin = $this->user('B2B_ADMIN', 'v2-qt-foreign@example.test');
        $permissionId = (int) DB::table('permissions')->where('code', 'b2b.pricing.manage')->value('id');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $admin->id,
            'permission_id' => $permissionId,
            'scope_key' => 'store:'.$foreignStore,
            'store_id' => $foreignStore,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin->forceFill(['has_permission_overrides' => true])->save();

        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/b2b/prices', $this->payload($foreignStore, $foreignProduct, $tierId))
            ->assertNotFound();

        $this->assertDatabaseMissing('b2b_price_rules', [
            'store_id' => $foreignStore,
            'product_id' => $foreignProduct,
        ]);
    }

    public function test_authorized_quantity_rule_persists_increments_and_disabled_mode_blocks_changes(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $productId = $this->product($storeId, 'b2b');
        $tierId = $this->tier();
        $this->setPricingMode($storeId, true);

        Sanctum::actingAs($this->user('B2B_ADMIN', 'v2-qt-allowed@example.test'));
        $this->putJson('/api/v1/admin/b2b/prices', $this->payload($storeId, $productId, $tierId))
            ->assertCreated();

        $this->assertDatabaseHas('b2b_price_rules', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'price_tier_id' => $tierId,
            'minimum_quantity' => 6,
            'ordering_increment' => 3,
        ]);
        $this->getJson('/api/v1/admin/b2b/prices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $productId);

        $this->setPricingMode($storeId, false);
        $this->putJson('/api/v1/admin/b2b/prices', $this->payload($storeId, $productId, $tierId))
            ->assertStatus(409);
        $this->getJson('/api/v1/admin/b2b/prices')->assertStatus(409);
        $this->assertDatabaseCount('b2b_price_rules', 1);
    }

    public function test_retail_product_cannot_be_added_to_wholesale_quantity_rules(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('B2C', 'V2-QT-RETAIL');
        $retailProduct = $this->product($retailStore, 'b2c');
        $tierId = $this->tier();
        $this->setPricingMode($storeId, true);

        Sanctum::actingAs($this->user('B2B_ADMIN', 'v2-qt-retail@example.test'));
        $this->putJson('/api/v1/admin/b2b/prices', $this->payload($storeId, $retailProduct, $tierId))
            ->assertStatus(422);
        $this->assertDatabaseMissing('b2b_price_rules', [
            'store_id' => $storeId,
            'product_id' => $retailProduct,
        ]);
    }

    public function test_pricing_workspace_reuses_canonical_route_with_bilingual_accessible_quantity_controls(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->setPricingMode($storeId, true);
        $admin = $this->user('B2B_ADMIN', 'v2-qt-dashboard@example.test', 'en');

        $this->actingAs($admin)->get('/admin/b2b/pricing-approvals')
            ->assertOk()
            ->assertSee('data-v2-quantity-tier-workflow', false)
            ->assertSee('Quantity tiers')
            ->assertSee('Quantity increment')
            ->assertSee('name="ordering_increment"', false)
            ->assertSee('dir="ltr"', false);

        $admin->update(['locale' => 'ar']);
        $this->actingAs($admin)->get('/admin/b2b/pricing-approvals')
            ->assertOk()
            ->assertSee('شرائح الكميات')
            ->assertSee('خطوة زيادة الكمية')
            ->assertSee('dir="rtl"', false);

        $finance = $this->user('FINANCE', 'v2-qt-finance@example.test');
        $this->actingAs($finance)->get('/admin/b2b/pricing-approvals')->assertForbidden();
    }

    private function store(string $channel, string $code): int
    {
        $typeId = DB::table('store_types')->where('code', $channel)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $storeId, string $channel): int
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'v2-quantity-tiers',
            'name' => 'Quantity tiers catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = DB::table('units')->value('id');
        if ($unitId === null) {
            $unitId = DB::table('units')->insertGetId([
                'code' => 'V2-QT-EA',
                'name' => 'Each',
                'decimal_places' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'V2-QT-'.$storeId,
            'name' => 'Volume quantity test product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 12,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    private function tier(): int
    {
        return (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'V2-QT',
            'name' => 'Quantity Test',
            'priority' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(string $role, string $email, string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    /** @return array<string,int|float> */
    private function payload(int $storeId, int $productId, int $tierId): array
    {
        return [
            'store_id' => $storeId,
            'product_id' => $productId,
            'price_tier_id' => $tierId,
            'unit_price' => 8.5,
            'minimum_quantity' => 6,
            'ordering_increment' => 3,
        ];
    }

    private function setPricingMode(int $storeId, bool $enabled): void
    {
        DB::table('settings')->updateOrInsert([
            'store_id' => $storeId,
            'key' => 'b2b.pricing_tiers_enabled',
        ], [
            'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
            'is_secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
