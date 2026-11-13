<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2VanDirectSalesScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_van_direct_sale_endpoints_reject_retail_customer_even_with_legacy_visit(): void
    {
        $actor = $this->vanActor();
        $storeId = $this->store('B2C', 'P275-RETAIL');
        $customerId = 9751;

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2c',
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'status' => 'planned',
        ]);

        $prefix = "/api/v1/van/customers/b2c/{$customerId}";
        $this->getJson("{$prefix}/catalog?store_id={$storeId}")->assertNotFound();
        $this->getJson("{$prefix}/order-options?store_id={$storeId}")->assertNotFound();
        $this->postJson("{$prefix}/order-quote", ['store_id' => $storeId])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'p275-retail-request-0001')
            ->postJson("{$prefix}/orders", ['store_id' => $storeId])
            ->assertNotFound();
    }

    public function test_van_direct_sale_requires_visit_at_selected_wholesale_store_and_rejects_tampering(): void
    {
        $actor = $this->vanActor();
        $oldStore = $this->store('B2B', 'P275-OLD-STORE');
        $assignedStore = $this->store('B2B', 'P275-ASSIGNED-STORE');
        $customerId = 9752;

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $assignedStore,
            'code' => 'P275-ASSIGNED-WH',
            'name' => 'Assigned Van Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('van_assignments')
            ->where('representative_user_id', $actor->id)
            ->where('status', 'active')
            ->update(['warehouse_id' => $warehouseId, 'updated_at' => now()]);

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => $customerId,
            'store_id' => $oldStore,
            'status' => 'planned',
        ]);

        $prefix = "/api/v1/van/customers/b2b/{$customerId}";
        $this->getJson("{$prefix}/catalog?store_id={$assignedStore}")->assertNotFound();
        $this->getJson("{$prefix}/order-options?store_id={$assignedStore}")->assertNotFound();
        $this->postJson("{$prefix}/order-quote", ['store_id' => $assignedStore])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'p275-cross-store-request-0001')
            ->postJson("{$prefix}/orders", ['store_id' => $assignedStore])
            ->assertNotFound();

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => $customerId,
            'store_id' => $assignedStore,
            'status' => 'planned',
        ]);

        $this->getJson("{$prefix}/order-options?store_id={$assignedStore}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['addresses', 'warehouses', 'payment_methods']]);

        // A caller cannot override the effective Van assignment by supplying
        // another store, even when a valid visit also exists in that store.
        $this->getJson("{$prefix}/order-options?store_id={$oldStore}")->assertNotFound();
        $this->postJson("{$prefix}/order-quote", ['store_id' => $oldStore])->assertNotFound();
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

    private function vanActor(): User
    {
        $this->seed(CoreReferenceSeeder::class);

        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'P275-VAN-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        return $actor;
    }
}
