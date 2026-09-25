<?php

namespace Tests\Feature;

use App\Models\Address;
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

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-PROFILE',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = (int) DB::table('products')->insertGetId([
            'unit_id' => $unitId,
            'sku' => 'PROFILE-001',
            'name' => 'Favorite Product',
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
            ->assertJsonPath('customer.id', $this->customer->id)
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
        $this->assertDatabaseHas('customers', [
            'id' => $this->customer->id,
            'name' => 'Updated Customer',
            'email' => 'updated@example.test',
            'phone' => '51111111',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'customer.profile_updated',
            'auditable_type' => 'App\\Models\\Customer',
            'auditable_id' => $this->customer->id,
        ]);
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
        $foreign = Address::query()->create([
            'customer_id' => $otherCustomer->id,
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
            'customer_id' => $this->customer->id,
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
