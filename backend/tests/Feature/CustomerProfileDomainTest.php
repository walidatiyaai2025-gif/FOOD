<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerProfileDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private B2cCustomer $domainCustomer;

    private int $storeId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $this->user = User::query()->create([
            'name' => 'Profile Customer',
            'email' => 'profile@example.test',
            'password' => 'secret-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'user_id' => $this->user->id,
            'type' => 'b2c',
            'name' => 'Profile Customer',
            'phone' => '50000000',
            'email' => $this->user->email,
        ]);

        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'PROFILE-B2C',
            'name' => 'Profile Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $this->storeId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Profile Retail Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->domainCustomer = B2cCustomer::query()->create([
            'legacy_customer_id' => $this->customer->id,
            'store_id' => $this->storeId,
            'user_id' => $this->user->id,
            'name' => 'Profile Customer',
            'phone' => '50000000',
            'email' => $this->user->email,
        ]);

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-PROFILE',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'PROFILE-001',
            'name' => 'Favorite Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'price' => 1.000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_profile_preserves_identity_shape_and_updates_customer_owned_contact_data(): void
    {
        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('id', $this->user->id)
            ->assertJsonPath('email', 'profile@example.test')
            ->assertJsonPath('locale', 'ar')
            ->assertJsonPath('customer.id', $this->domainCustomer->id)
            ->assertJsonPath('customer.phone', '50000000')
            ->assertJsonCount(0, 'addresses')
            ->assertJsonCount(0, 'favorites');

        $this->patchJson('/api/v1/profile', [
            'name' => 'Updated Customer',
            'email' => 'UPDATED@example.test',
            'phone' => '51111111',
            'locale' => 'en',
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Customer')
            ->assertJsonPath('email', 'updated@example.test')
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('customer.name', 'Updated Customer')
            ->assertJsonPath('customer.email', 'updated@example.test')
            ->assertJsonPath('customer.phone', '51111111');

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Updated Customer',
            'email' => 'updated@example.test',
            'locale' => 'en',
        ]);
        $this->assertDatabaseHas('b2c_customers', [
            'id' => $this->domainCustomer->id,
            'store_id' => $this->storeId,
            'name' => 'Updated Customer',
            'email' => 'updated@example.test',
            'phone' => '51111111',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'customer.profile_updated',
            'auditable_type' => 'App\\Models\\B2cCustomer',
            'auditable_id' => $this->domainCustomer->id,
        ]);
    }

    public function test_b2b_profile_exposes_only_the_owned_business_account_identity(): void
    {
        $user = User::query()->create([
            'name' => 'Wholesale Buyer',
            'email' => 'wholesale-profile@example.test',
            'password' => 'secret-password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $legacyCustomerId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => 'b2b',
            'name' => 'Wholesale Buyer',
            'phone' => '52222222',
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $b2bCustomerId = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacyCustomerId,
            'user_id' => $user->id,
            'name' => 'Wholesale Buyer',
            'phone' => '52222222',
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_accounts')->insert([
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $b2bCustomerId,
            'company_name' => 'Acme Wholesale',
            'status' => 'active',
            'tax_number' => 'TAX-872',
            'credit_limit' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('customer.id', $b2bCustomerId)
            ->assertJsonPath('customer.type', 'b2b')
            ->assertJsonPath('business_account.company_name', 'Acme Wholesale')
            ->assertJsonPath('business_account.status', 'active')
            ->assertJsonPath('business_account.tax_number', 'TAX-872');
    }

    public function test_addresses_are_customer_owned_and_keep_one_default_when_possible(): void
    {
        $first = $this->postJson('/api/v1/profile/addresses', [
            'label' => 'Home',
            'line1' => 'Street 1',
            'city' => 'Kuwait City',
            'country_code' => 'kw',
        ])->assertCreated()
            ->assertJsonPath('country_code', 'KW')
            ->assertJsonPath('is_default', true);

        $firstId = (int) $first->json('id');

        $second = $this->postJson('/api/v1/profile/addresses', [
            'label' => 'Office',
            'line1' => 'Street 2',
            'city' => 'Kuwait City',
            'country_code' => 'KW',
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('is_default', true);

        $secondId = (int) $second->json('id');

        $this->assertDatabaseHas('addresses', [
            'id' => $firstId,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('addresses', [
            'id' => $secondId,
            'is_default' => true,
        ]);

        $this->patchJson("/api/v1/profile/addresses/{$secondId}", [
            'is_default' => false,
            'area' => 'Sharq',
        ])->assertOk()
            ->assertJsonPath('area', 'Sharq');

        $this->assertDatabaseHas('addresses', [
            'id' => $firstId,
            'is_default' => true,
        ]);

        $this->deleteJson("/api/v1/profile/addresses/{$firstId}")
            ->assertNoContent();

        $this->assertDatabaseHas('addresses', [
            'id' => $secondId,
            'is_default' => true,
        ]);

        $this->getJson('/api/v1/profile/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondId);
    }

    public function test_customer_cannot_read_update_or_delete_another_customers_address(): void
    {
        $otherUser = User::query()->create([
            'name' => 'Other Customer',
            'email' => 'profile-other@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        $otherCustomer = Customer::query()->create([
            'user_id' => $otherUser->id,
            'type' => 'b2c',
            'name' => 'Other Customer',
            'email' => $otherUser->email,
        ]);
        $otherDomainCustomer = B2cCustomer::query()->create([
            'legacy_customer_id' => $otherCustomer->id,
            'store_id' => $this->storeId,
            'user_id' => $otherUser->id,
            'name' => 'Other Customer',
            'email' => $otherUser->email,
        ]);
        $foreign = Address::query()->create([
            'customer_id' => $otherCustomer->id,
            'b2c_customer_id' => $otherDomainCustomer->id,
            'line1' => 'Other street',
            'city' => 'Kuwait City',
            'country_code' => 'KW',
            'is_default' => true,
        ]);

        $this->patchJson("/api/v1/profile/addresses/{$foreign->id}", [
            'label' => 'Stolen',
        ])->assertNotFound();

        $this->deleteJson("/api/v1/profile/addresses/{$foreign->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('addresses', [
            'id' => $foreign->id,
            'customer_id' => $otherCustomer->id,
        ]);
    }

    public function test_favorites_are_idempotent_customer_owned_and_hide_inactive_products(): void
    {
        $this->postJson("/api/v1/profile/favorites/{$this->productId}")
            ->assertCreated()
            ->assertJsonPath('product.id', $this->productId);

        $this->postJson("/api/v1/profile/favorites/{$this->productId}")
            ->assertOk();

        $this->assertDatabaseCount('customer_favorites', 1);

        $this->getJson('/api/v1/profile/favorites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->productId);

        DB::table('products')
            ->where('id', $this->productId)
            ->update(['is_active' => false]);

        $this->getJson('/api/v1/profile/favorites')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/profile/favorites/{$this->productId}")
            ->assertNotFound();

        DB::table('products')
            ->where('id', $this->productId)
            ->update(['is_active' => true]);

        $this->deleteJson("/api/v1/profile/favorites/{$this->productId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('customer_favorites', [
            'b2c_customer_id' => $this->domainCustomer->id,
            'product_id' => $this->productId,
        ]);
    }

    public function test_non_customer_identity_profile_still_works_but_customer_resources_are_forbidden(): void
    {
        $admin = User::query()->create([
            'name' => 'Identity Only',
            'email' => 'identity-only@example.test',
            'password' => 'secret-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('email', 'identity-only@example.test')
            ->assertJsonPath('customer', null)
            ->assertJsonCount(0, 'addresses')
            ->assertJsonCount(0, 'favorites');

        $this->getJson('/api/v1/profile/addresses')->assertForbidden();
        $this->getJson('/api/v1/profile/favorites')->assertForbidden();
    }
}
