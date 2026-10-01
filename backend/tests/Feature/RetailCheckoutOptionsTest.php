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

class RetailCheckoutOptionsTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;
    private User $user;
    private B2cCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config(['checkout.payment_methods' => ['cash_on_delivery', 'knet']]);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'RETAIL-OPTIONS',
            'name' => 'Retail Options',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::query()->create([
            'name' => 'Retail Customer',
            'email' => 'retail-options@example.test',
            'password' => 'secret-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $legacy = Customer::query()->create([
            'user_id' => $this->user->id,
            'type' => 'b2c',
            'name' => $this->user->name,
            'email' => $this->user->email,
        ]);
        $this->customer = B2cCustomer::query()->create([
            'legacy_customer_id' => $legacy->id,
            'store_id' => $this->storeId,
            'user_id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_retail_checkout_options_are_store_scoped_and_authoritative(): void
    {
        $owned = Address::query()->create([
            'b2c_customer_id' => $this->customer->id,
            'label' => 'Home',
            'line1' => 'Street 1',
            'city' => 'Kuwait City',
            'country_code' => 'KW',
            'is_default' => true,
        ]);

        $otherUser = User::query()->create([
            'name' => 'Other',
            'email' => 'retail-options-other@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        $otherLegacy = Customer::query()->create([
            'user_id' => $otherUser->id,
            'type' => 'b2c',
            'name' => 'Other',
            'email' => $otherUser->email,
        ]);
        $otherCustomer = B2cCustomer::query()->create([
            'legacy_customer_id' => $otherLegacy->id,
            'store_id' => $this->storeId,
            'user_id' => $otherUser->id,
            'name' => 'Other',
            'email' => $otherUser->email,
        ]);
        Address::query()->create([
            'b2c_customer_id' => $otherCustomer->id,
            'label' => 'Foreign',
            'line1' => 'Other Street',
            'city' => 'Kuwait City',
            'country_code' => 'KW',
        ]);

        $this->withHeader('X-FOODEX-Customer-Domain', 'b2c')
            ->getJson('/api/v1/checkout/options?store_id='.$this->storeId)
            ->assertOk()
            ->assertJsonPath('store_id', $this->storeId)
            ->assertJsonPath('addresses.0.id', $owned->id)
            ->assertJsonCount(1, 'addresses')
            ->assertJsonPath('payment_methods.0', 'cash_on_delivery')
            ->assertJsonPath('payment_methods.1', 'knet');
    }

    public function test_retail_checkout_options_require_authentication_and_store(): void
    {
        $this->getJson('/api/v1/checkout/options')->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/checkout/options?store_id='.$this->storeId)
            ->assertUnauthorized();
    }
}
