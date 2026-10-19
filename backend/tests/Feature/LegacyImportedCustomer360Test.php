<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyImportedCustomer360Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'WHOLESALE-MAIN',
            'name' => 'Main Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);
        config(['foodex.platform_wholesale_store_id' => $storeId]);
    }

    public function test_historical_imported_customer_without_login_is_visible_in_customer_360(): void
    {
        $storeId = (int) DB::table('stores')->where('code', 'WHOLESALE-MAIN')->value('id');
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');

        $legacyCustomerId = (int) DB::table('customers')->insertGetId([
            'user_id' => null,
            'type' => 'b2b',
            'name' => 'Historical Foodex Customer',
            'phone' => '+96550000000',
            'email' => null,
            'created_at' => now()->subYears(3),
            'updated_at' => now(),
        ]);

        $b2bCustomerId = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacyCustomerId,
            'user_id' => null,
            'name' => 'Historical Foodex Customer',
            'phone' => '+96550000000',
            'email' => null,
            'created_at' => now()->subYears(3),
            'updated_at' => now(),
        ]);

        DB::table('b2b_accounts')->insert([
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $b2bCustomerId,
            'price_tier_id' => $tierId,
            'company_name' => 'Historical Foodex Customer',
            'status' => 'active',
            'tax_number' => null,
            'credit_limit' => 250,
            'created_at' => now()->subYears(3),
            'updated_at' => now(),
        ]);

        $platformCustomerId = (int) DB::table('platform_customers')->insertGetId([
            'user_id' => null,
            'legacy_customer_id' => $legacyCustomerId,
            'name' => 'Historical Foodex Customer',
            'phone' => '+96550000000',
            'email' => null,
            'origin_channel' => 'b2b',
            'origin_store_id' => $storeId,
            'registration_source' => 'import',
            'registered_at' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $superAdmin = $this->admin('SUPER_ADMIN');

        $this->actingAs($superAdmin)
            ->get(route('admin.customer-360.index'))
            ->assertOk()
            ->assertSee('Historical Foodex Customer')
            ->assertSee('Import');

        $this->actingAs($superAdmin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platformCustomerId]))
            ->assertOk()
            ->assertSee('Historical Foodex Customer')
            ->assertSee('Accounts & finance');

        $b2bAdmin = $this->admin('B2B_ADMIN');

        $this->actingAs($b2bAdmin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platformCustomerId]))
            ->assertOk()
            ->assertSee('Historical Foodex Customer')
            ->assertSee('Accounts & finance');
    }

    private function admin(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-legacy-customer-360@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
