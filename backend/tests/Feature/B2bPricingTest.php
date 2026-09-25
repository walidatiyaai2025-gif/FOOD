<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_rules_and_active_account_receives_account_price(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId, $tierId] = $this->catalog();
        $admin = $this->userWithRole('B2B_ADMIN', 'pricing-admin@example.test');
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/b2b/prices', ['price_tier_id' => $tierId, 'store_id' => $storeId, 'product_id' => $productId, 'unit_price' => 7.250, 'minimum_quantity' => 5])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.price_rule.saved']);

        $buyer = User::query()->create(['name' => 'Buyer', 'email' => 'price-buyer@example.test', 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $buyer->id, 'type' => 'b2b', 'name' => 'Buyer', 'email' => $buyer->email]);
        B2bAccount::query()->create(['customer_id' => $customer->id, 'price_tier_id' => $tierId, 'company_name' => 'Buyer Co', 'status' => 'active']);
        Sanctum::actingAs($buyer);

        $this->getJson("/api/v1/b2b/products?store_id={$storeId}")->assertOk()->assertJsonPath('data.0.unit_price', 7.25)->assertJsonPath('data.0.minimum_quantity', 5);
        $this->postJson('/api/v1/cart/items', ['store_id' => $storeId, 'product_id' => $productId, 'quantity' => 1])->assertConflict();
        $this->postJson('/api/v1/cart/items', ['store_id' => $storeId, 'product_id' => $productId, 'quantity' => 5])->assertCreated()->assertJsonPath('items.0.unit_price_snapshot', 7.25)->assertJsonPath('subtotal', 36.25);
    }

    public function test_unapproved_account_and_unauthorized_admin_are_denied(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId, $tierId] = $this->catalog();

        DB::table('b2b_price_rules')->insert(['price_tier_id' => $tierId, 'store_id' => $storeId, 'product_id' => $productId, 'unit_price' => 4, 'minimum_quantity' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $finance = $this->userWithRole('FINANCE', 'pricing-finance@example.test');
        Sanctum::actingAs($finance);

        $this->putJson('/api/v1/admin/b2b/prices', ['price_tier_id' => $tierId, 'store_id' => $storeId, 'product_id' => $productId, 'unit_price' => 1, 'minimum_quantity' => 1])->assertForbidden();

        $buyer = User::query()->create(['name' => 'Pending', 'email' => 'pending-price@example.test', 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $buyer->id, 'type' => 'b2b', 'name' => 'Pending', 'email' => $buyer->email]);
        B2bAccount::query()->create(['customer_id' => $customer->id, 'price_tier_id' => $tierId, 'company_name' => 'Pending Co', 'status' => 'pending']);
        Sanctum::actingAs($buyer);

        $this->getJson("/api/v1/b2b/products?store_id={$storeId}")->assertForbidden();
    }

    private function catalog(): array
    {
        $type = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'B2B-PRICE', 'name' => 'Wholesale', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $unit = (int) DB::table('units')->insertGetId(['code' => 'EA-PRICE', 'name' => 'Each', 'decimal_places' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $product = (int) DB::table('products')->insertGetId(['unit_id' => $unit, 'sku' => 'B2B-P-1', 'name' => 'Wholesale Product', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('store_products')->insert(['store_id' => $store, 'product_id' => $product, 'price' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tier = (int) DB::table('b2b_price_tiers')->insertGetId(['code' => 'GOLD', 'name' => 'Gold', 'priority' => 10, 'created_at' => now(), 'updated_at' => now()]);

        return [$store, $product, $tier];
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = User::query()->create(['name' => $role, 'email' => $email, 'password' => 'password', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
