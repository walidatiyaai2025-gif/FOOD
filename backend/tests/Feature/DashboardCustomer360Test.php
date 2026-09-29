<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardCustomer360Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $this->store('B2B', 'WHOLESALE-MAIN', 'Main Wholesale');
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);
    }

    public function test_retail_scope_hides_foreign_customer_store_and_invoice(): void
    {
        $storeA = $this->store('B2C', 'RETAIL-A', 'Retail A');
        $storeB = $this->store('B2C', 'RETAIL-B', 'Retail B');
        $user = $this->customer('Scoped Customer', 'scoped@example.test', $storeA);
        app(CustomerDomainResolver::class)->b2c($user, $storeB);
        $foreign = $this->customer('Foreign Customer', 'foreign@example.test', $storeB);
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
        $foreignPlatform = PlatformCustomer::query()->where('user_id', $foreign->id)->firstOrFail();

        $storeADomain = (int) DB::table('b2c_customers')
            ->where('user_id', $user->id)
            ->where('store_id', $storeA)
            ->value('id');
        $storeBDomain = (int) DB::table('b2c_customers')
            ->where('user_id', $user->id)
            ->where('store_id', $storeB)
            ->value('id');

        $invoiceA = $this->invoice($platform, $storeADomain, $storeA, 'INV-A');
        $invoiceB = $this->invoice($platform, $storeBDomain, $storeB, 'INV-B');
        $admin = $this->retailAdmin($storeA);

        $this->actingAs($admin)
            ->get(route('admin.customer-360.index'))
            ->assertOk()
            ->assertSee('scoped@example.test')
            ->assertDontSee('foreign@example.test');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Retail A')
            ->assertSee('INV-A')
            ->assertDontSee('Retail B')
            ->assertDontSee('INV-B');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $foreignPlatform->id]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', ['invoice' => $invoiceA]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', ['invoice' => $invoiceB]))
            ->assertNotFound();
    }

    public function test_super_admin_sees_exact_origin_and_all_materialized_retail_stores(): void
    {
        $storeA = $this->store('B2C', 'RETAIL-A', 'Retail A');
        $storeB = $this->store('B2C', 'RETAIL-B', 'Retail B');
        $user = $this->customer('Unified Customer', 'unified@example.test', $storeA);
        app(CustomerDomainResolver::class)->b2c($user, $storeB);
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
        $admin = $this->globalAdmin('SUPER_ADMIN');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Retail A · RETAIL-A')
            ->assertSee('Retail B · RETAIL-B')
            ->assertSee('Wholesale account');
    }

    public function test_retail_admin_can_fully_manage_visible_customer_addresses_only(): void
    {
        $storeA = $this->store('B2C', 'ADDRESS-A', 'Address Store A');
        $storeB = $this->store('B2C', 'ADDRESS-B', 'Address Store B');
        $user = $this->customer('Address Customer', 'address-customer@example.test', $storeA);
        $foreign = $this->customer('Foreign Address Customer', 'address-foreign@example.test', $storeB);
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
        $foreignPlatform = PlatformCustomer::query()->where('user_id', $foreign->id)->firstOrFail();
        $admin = $this->retailAdmin($storeA);

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Manage addresses')
            ->assertSee('Add new address');

        $this->actingAs($admin)
            ->post(route('admin.customer-360.addresses.store', ['platformCustomer' => $platform->id]), [
                'label' => 'Home',
                'recipient_name' => 'Address Customer',
                'delivery_phone' => '+201011111111',
                'line1' => 'Street 1',
                'city' => 'Cairo',
                'area' => 'Nasr City',
                'country_code' => 'EG',
                'latitude' => 30.04442,
                'longitude' => 31.235712,
                'location_source' => 'map_pin',
                'landmark' => 'Near the park',
            ])
            ->assertRedirect();

        $first = Address::query()
            ->where('platform_customer_id', $platform->id)
            ->where('label', 'Home')
            ->firstOrFail();

        $this->assertTrue((bool) $first->is_default);

        $this->actingAs($admin)
            ->patch(route('admin.customer-360.addresses.update', [
                'platformCustomer' => $platform->id,
                'address' => $first->id,
            ]), [
                'label' => 'Home updated',
                'line1' => 'Street 2',
                'city' => 'Cairo',
                'country_code' => 'EG',
                'latitude' => 30.0500000,
                'longitude' => 31.2400000,
                'location_source' => 'map_pin',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('addresses', [
            'id' => $first->id,
            'platform_customer_id' => $platform->id,
            'label' => 'Home updated',
            'line1' => 'Street 2',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.customer-360.addresses.store', ['platformCustomer' => $platform->id]), [
                'label' => 'Office',
                'line1' => 'Office Street',
                'city' => 'Cairo',
                'country_code' => 'EG',
            ])
            ->assertRedirect();

        $second = Address::query()
            ->where('platform_customer_id', $platform->id)
            ->where('label', 'Office')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.customer-360.addresses.default', [
                'platformCustomer' => $platform->id,
                'address' => $second->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('addresses', ['id' => $first->id, 'is_default' => false]);
        $this->assertDatabaseHas('addresses', ['id' => $second->id, 'is_default' => true]);

        $this->actingAs($admin)
            ->delete(route('admin.customer-360.addresses.destroy', [
                'platformCustomer' => $platform->id,
                'address' => $second->id,
            ]))
            ->assertRedirect();

        $this->assertSoftDeleted('addresses', ['id' => $second->id]);
        $this->assertDatabaseHas('addresses', ['id' => $first->id, 'is_default' => true]);

        $this->actingAs($admin)
            ->post(route('admin.customer-360.addresses.store', ['platformCustomer' => $foreignPlatform->id]), [
                'line1' => 'Forbidden Street',
                'city' => 'Cairo',
                'country_code' => 'EG',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('addresses', [
            'platform_customer_id' => $foreignPlatform->id,
            'line1' => 'Forbidden Street',
        ]);
    }

    public function test_b2b_admin_does_not_receive_exact_retail_origin_store(): void
    {
        $storeA = $this->store('B2C', 'RETAIL-A', 'Retail A');
        $user = $this->customer('Wholesale Customer', 'wholesale@example.test', $storeA);
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($this->globalAdmin('B2B_ADMIN'))
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Wholesale account')
            ->assertDontSee('Retail A · RETAIL-A');
    }

    private function customer(string $name, string $email, int $storeId): User
    {
        return app(PlatformCustomerService::class)->register([
            'name' => $name,
            'email' => $email,
            'phone' => '+201000000000',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeId,
        ]);
    }

    private function store(string $type, string $code, string $name): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Retail Admin',
            'email' => "retail-{$storeId}@example.test",
            'password' => 'password123',
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

    private function globalAdmin(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function invoice(
        PlatformCustomer $platform,
        int $domainId,
        int $storeId,
        string $number,
    ): int {
        return (int) DB::table('invoices')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $platform->legacy_customer_id,
            'b2c_customer_id' => $domainId,
            'platform_customer_id' => $platform->id,
            'invoice_number' => $number,
            'status' => 'issued',
            'channel' => 'b2c',
            'currency' => 'EGP',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'total' => 10,
            'issued_at' => now(),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
