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

class StoreChannelOperationsCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_control_center_composes_dashboard_readiness_store_360_and_reports_on_canonical_route(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'stores-dashboard@example.test');
        $storeId = $this->provisionReadyStore($admin, 'OPS-DASH');

        DB::table('orders')->insert([
            'store_id' => $storeId,
            'customer_id' => $this->customerId($storeId),
            'address_id' => null,
            'order_number' => 'OPS-DASH-ORDER',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 12.500,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 12.500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.retail-stores.index', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $response
            ->assertOk()
            ->assertSee(__('store_channels.title'))
            ->assertSee(__('store_channels.sections.store360'))
            ->assertSee(__('store_channels.sections.readiness'))
            ->assertSee(__('store_channels.reports.performance'))
            ->assertSee('12.500 KWD')
            ->assertSee('OPS-DASH')
            ->assertSee('100%');
    }

    public function test_lifecycle_suspend_activate_and_close_are_reasoned_audited_and_sync_canonical_store_state(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'stores-lifecycle@example.test');
        $storeId = $this->provisionReadyStore($admin, 'OPS-LIFE');

        $this->actingAs($admin)->patch(route('admin.retail-stores.update', $storeId), [
            'lifecycle_action' => 'suspend',
            'lifecycle_reason' => 'Scheduled operational maintenance.',
        ])->assertRedirect();

        $this->assertDatabaseHas('stores', ['id' => $storeId, 'is_active' => false]);
        $this->assertDatabaseHas('store_channel_operational_profiles', ['store_id' => $storeId, 'lifecycle_state' => 'SUSPENDED']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'retail_store.lifecycle_changed', 'auditable_id' => $storeId]);

        $this->actingAs($admin)->patch(route('admin.retail-stores.update', $storeId), [
            'lifecycle_action' => 'activate',
            'lifecycle_reason' => 'Readiness verified after maintenance.',
        ])->assertRedirect();

        $this->assertDatabaseHas('stores', ['id' => $storeId, 'is_active' => true]);
        $this->assertDatabaseHas('store_channel_operational_profiles', ['store_id' => $storeId, 'lifecycle_state' => 'ACTIVE']);

        $this->actingAs($admin)->patch(route('admin.retail-stores.update', $storeId), [
            'lifecycle_action' => 'close',
            'lifecycle_reason' => 'Permanent store retirement approved.',
        ])->assertRedirect();

        $this->assertDatabaseHas('stores', ['id' => $storeId, 'is_active' => false]);
        $this->assertDatabaseHas('store_channel_operational_profiles', ['store_id' => $storeId, 'lifecycle_state' => 'CLOSED']);

        $this->actingAs($admin)->from(route('admin.retail-stores.index'))->patch(route('admin.retail-stores.update', $storeId), [
            'lifecycle_action' => 'activate',
            'lifecycle_reason' => 'Attempt to reopen closed store.',
        ])->assertSessionHasErrors('lifecycle_action');
    }

    public function test_activation_is_blocked_until_readiness_dependencies_are_present(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'stores-readiness@example.test');
        $tierId = $this->priceTierId();

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'code' => 'OPS-NOT-READY',
            'name' => 'Not Ready Store',
            'logo' => UploadedFile::fake()->image('not-ready.png', 128, 128),
            'price_tier_id' => $tierId,
            'is_active' => '0',
            'manager_mode' => 'new',
            'manager_name' => 'Not Ready Manager',
            'manager_email' => 'not-ready-manager@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect();

        $storeId = (int) DB::table('stores')->where('code', 'OPS-NOT-READY')->value('id');

        $this->actingAs($admin)->from(route('admin.retail-stores.index'))->patch(route('admin.retail-stores.update', $storeId), [
            'lifecycle_action' => 'activate',
            'lifecycle_reason' => 'Premature activation attempt.',
        ])->assertSessionHasErrors('lifecycle_action');

        $this->assertDatabaseHas('stores', ['id' => $storeId, 'is_active' => false]);
    }

    public function test_close_is_blocked_while_open_orders_exist(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'stores-close@example.test');
        $storeId = $this->provisionReadyStore($admin, 'OPS-CLOSE');

        DB::table('orders')->insert([
            'store_id' => $storeId,
            'customer_id' => $this->customerId($storeId),
            'address_id' => null,
            'order_number' => 'OPS-CLOSE-OPEN',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->from(route('admin.retail-stores.index'))->patch(route('admin.retail-stores.update', $storeId), [
            'lifecycle_action' => 'close',
            'lifecycle_reason' => 'Close while operational dependency exists.',
        ])->assertSessionHasErrors('lifecycle_action');

        $this->assertDatabaseHas('stores', ['id' => $storeId, 'is_active' => true]);
    }

    public function test_bounded_readiness_export_is_available_from_the_same_control_surface(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'stores-export@example.test');
        $this->provisionReadyStore($admin, 'OPS-EXPORT');

        $response = $this->actingAs($admin)->get(route('admin.retail-stores.index', ['export' => 'readiness']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('OPS-EXPORT', $response->streamedContent());
    }

    private function provisionReadyStore(User $admin, string $code): int
    {
        $tierId = $this->priceTierId();
        $email = strtolower($code).'@example.test';

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'code' => $code,
            'name' => $code.' Store',
            'logo' => UploadedFile::fake()->image(strtolower($code).'.png', 128, 128),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => $code.' Manager',
            'manager_email' => $email,
            'manager_password' => 'password123',
        ])->assertRedirect();

        $storeId = (int) DB::table('stores')->where('code', $code)->value('id');
        $unitId = (int) DB::table('units')->value('id');
        if ($unitId < 1) {
            $unitId = (int) DB::table('units')->insertGetId([
                'code' => 'EA-'.$code, 'name' => 'Each '.$code, 'decimal_places' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $productId = (int) DB::table('products')->insertGetId([
            'unit_id' => $unitId,
            'sku' => 'SKU-'.$code,
            'name' => 'Product '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId, 'product_id' => $productId, 'price' => 2.500, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId, 'code' => 'WH-'.$code, 'name' => 'Warehouse '.$code, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 20, 'reserved_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_service_zones')->insert([
            'store_id' => $storeId, 'country_code' => 'KW', 'city' => 'Kuwait City', 'area' => $code,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $storeId;
    }

    private function customerId(int $storeId): int
    {
        return (int) DB::table('customers')->insertGetId([
            'store_id' => $storeId,
            'type' => 'b2c',
            'name' => 'Store Operations Test Customer',
            'phone' => '+96550000000',
            'email' => 'customer-'.$storeId.'@example.test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function priceTierId(): int
    {
        return (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
