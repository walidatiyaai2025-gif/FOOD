<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\PlatformCustomerService;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2VanTerminalVisitDirectSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_endpoints_require_planned_or_started_visit_and_terminal_history_remains_collectable(): void
    {
        $actor = $this->vanActor();
        $wholesaleStoreId = $this->store('B2B', 'V2-3515-WHOLESALE');
        $retailStoreId = $this->store('B2C', 'V2-3515-RETAIL');
        $this->assignWarehouse($actor, $wholesaleStoreId);

        config(['foodex.platform_wholesale_store_code' => 'V2-3515-WHOLESALE']);
        $buyer = app(PlatformCustomerService::class)->register([
            'name' => 'Van terminal visit test customer',
            'email' => 'van-terminal-visit-3515@example.test',
            'phone' => '+201000003515',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $retailStoreId,
        ]);
        $customer = B2bCustomer::query()->where('user_id', $buyer->id)->firstOrFail();
        $visit = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => $customer->id,
            'store_id' => $wholesaleStoreId,
            'status' => 'planned',
        ]);
        $prefix = '/api/v1/van/customers/b2b/'.$customer->id;
        $saleStore = ['store_id' => $wholesaleStoreId];

        foreach (['planned', 'started'] as $status) {
            $visit->forceFill(['status' => $status])->save();
            $this->getJson('/api/v1/van/customers')
                ->assertOk()
                ->assertJsonPath('data.0.can_direct_sell', true)
                ->assertJsonPath('data.0.type', 'b2b');
            $this->getJson($prefix.'/order-options?store_id='.$wholesaleStoreId)
                ->assertOk();
            $this->getJson($prefix.'/catalog?store_id='.$wholesaleStoreId)
                ->assertOk();
        }

        foreach (['completed_with_order', 'completed_no_order', 'customer_unavailable', 'closed'] as $status) {
            $visit->forceFill(['status' => $status])->save();
            // Historical customers must still appear for collection and audit,
            // but may not authorize a new Van sale via any HTTP entry point.
            $this->getJson('/api/v1/van/customers')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.can_direct_sell', false);
            $this->getJson($prefix.'/catalog?store_id='.$wholesaleStoreId)->assertNotFound();
            $this->getJson($prefix.'/order-options?store_id='.$wholesaleStoreId)->assertNotFound();
            $this->postJson($prefix.'/order-quote', $saleStore)->assertNotFound();
            $this->withHeader('Idempotency-Key', 'v2-3515-terminal-order-request')
                ->postJson($prefix.'/orders', $saleStore)
                ->assertNotFound();
        }

        $otherActor = User::factory()->create(['is_active' => true]);
        VanVisit::query()->create([
            'actor_user_id' => $otherActor->id,
            'customer_type' => 'b2b',
            'customer_id' => $customer->id,
            'store_id' => $wholesaleStoreId,
            'status' => 'started',
        ]);

        $this->getJson('/api/v1/van/customers')
            ->assertOk()
            ->assertJsonPath('data.0.can_direct_sell', false);
        $this->getJson($prefix.'/order-options?store_id='.$wholesaleStoreId)->assertNotFound();
    }

    private function vanActor(): User
    {
        $this->seed(CoreReferenceSeeder::class);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'V2-3515-VAN-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        return $actor;
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

    private function assignWarehouse(User $actor, int $storeId): void
    {
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'V2-3515-VAN-WH',
            'name' => 'Terminal Visit Van Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('van_assignments')
            ->where('representative_user_id', $actor->id)
            ->where('status', 'active')
            ->update(['warehouse_id' => $warehouseId, 'updated_at' => now()]);
    }
}
