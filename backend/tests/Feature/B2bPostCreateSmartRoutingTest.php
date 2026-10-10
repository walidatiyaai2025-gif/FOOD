<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\B2bCustomer;
use App\Models\GeographyNode;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\Role;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\AdminOrderManagementService;
use App\Services\CatalogOwnership;
use App\Services\PlatformCustomerService;
use App\Services\TerritoryService;
use App\Services\VanRegistryService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bPostCreateSmartRoutingTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $warehouseId;

    private int $productId;

    private User $buyer;

    private B2bCustomer $customer;

    private Address $address;

    private ServiceTerritory $territory;

    private int $targetVanId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $this->storeId = app(WholesalePrincipal::class)->storeId();
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($this->storeId, 'b2b');

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-W02',
            'name' => 'Each W02',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => 'W02-SKU',
            'name' => 'W02 Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'price' => 15.000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $this->storeId,
            'code' => 'WH-W02',
            'name' => 'W02 Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'warehouse_id' => $this->warehouseId,
            'product_id' => $this->productId,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->buyer = app(PlatformCustomerService::class)->register([
            'name' => 'W02 Buyer',
            'email' => 'w02-buyer@example.test',
            'phone' => '+201000001193',
            'password' => 'Password123!',
            'locale' => 'en',
        ]);

        $this->customer = B2bCustomer::query()
            ->where('user_id', $this->buyer->id)
            ->firstOrFail();

        $this->address = Address::query()->create([
            'customer_id' => $this->customer->legacy_customer_id,
            'b2b_customer_id' => $this->customer->id,
            'label' => 'W02 Route Address',
            'line1' => 'W02 Street',
            'city' => 'Cairo',
            'area' => 'Routing Area',
            'country_code' => 'EG',
            'latitude' => 30.5,
            'longitude' => 30.5,
            'is_default' => true,
        ]);

        $country = GeographyNode::query()->create([
            'type' => 'country',
            'code' => 'country-w02',
            'name_ar' => 'مصر',
            'name_en' => 'Egypt',
            'country_code' => 'EG',
            'is_active' => true,
        ]);

        $this->territory = ServiceTerritory::query()->create([
            'code' => 'TERR-W02',
            'name_ar' => 'منطقة W02',
            'name_en' => 'W02 Territory',
            'country_node_id' => $country->id,
            'status' => 'active',
            'priority' => 100,
        ]);

        app(TerritoryService::class)->addGeometry($this->territory, [
            'type' => 'Polygon',
            'coordinates' => [[
                [30.0, 30.0],
                [31.0, 30.0],
                [31.0, 31.0],
                [30.0, 31.0],
                [30.0, 30.0],
            ]],
        ]);

        $routingActor = User::factory()->create(['is_active' => true]);
        $registry = app(VanRegistryService::class);
        $targetVan = $registry->createVan(['code' => 'VAN-W02-TARGET']);
        $registry->assign($routingActor, $targetVan, [
            'territory_key' => $this->territory->code,
            'assignment_type' => 'primary',
            'effective_from' => now()->subDay(),
        ]);
        $this->targetVanId = (int) $targetVan->id;
    }

    public function test_customer_checkout_routes_b2b_order_to_responsible_van(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'quantity' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('channel', 'b2b');

        $response = $this->withHeader('Idempotency-Key', 'w02-customer-checkout-0001')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->storeId,
                'address_id' => $this->address->id,
                'payment_method' => config('checkout.default_payment_method'),
            ])
            ->assertCreated()
            ->assertJsonPath('channel', 'b2b');

        $this->assertAssigned((int) $response->json('id'), 'customer_checkout');
    }

    public function test_dashboard_created_b2b_order_uses_same_post_create_routing_hook(): void
    {
        $admin = $this->roleUser('B2B_ADMIN');

        $this->actingAs($admin)
            ->post('/admin/b2b/orders', $this->orderPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order = Order::query()
            ->where('b2b_customer_id', $this->customer->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertAssigned((int) $order->id, 'dashboard');
    }

    public function test_van_created_b2b_order_is_policy_authoritative_not_self_assigned(): void
    {
        $vanActor = $this->roleUser('VAN_OPERATOR');
        $registry = app(VanRegistryService::class);
        $sourceVan = $registry->createVan(['code' => 'VAN-W02-SOURCE']);
        $registry->assign($vanActor, $sourceVan, [
            'representative_user_id' => $vanActor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subHour(),
        ]);

        VanVisit::query()->create([
            'actor_user_id' => $vanActor->id,
            'customer_type' => 'b2b',
            'customer_id' => $this->customer->id,
            'store_id' => $this->storeId,
            'status' => 'planned',
        ]);

        Sanctum::actingAs($vanActor, ['app:van']);

        $response = $this->withHeader('Idempotency-Key', 'w02-van-order-create-0001')
            ->postJson('/api/v1/van/customers/b2b/'.$this->customer->id.'/orders', [
                'store_id' => $this->storeId,
                ...$this->orderPayload(),
            ])
            ->assertCreated();

        $orderId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $orderId);
        $this->assertNotSame((int) $sourceVan->id, $this->targetVanId);
        $this->assertAssigned($orderId, 'van');
    }

    public function test_integration_and_import_orders_survive_routing_inability_without_driver_fallback(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $noCoordinates = Address::query()->create([
            'customer_id' => $this->customer->legacy_customer_id,
            'b2b_customer_id' => $this->customer->id,
            'label' => 'No coordinates',
            'line1' => 'Unknown route',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'is_default' => false,
        ]);

        foreach (['integration', 'import'] as $source) {
            $request = Request::create('/internal/'.$source.'/orders', 'POST', [
                ...$this->orderPayload(),
                'address_id' => $noCoordinates->id,
            ]);

            $order = app(AdminOrderManagementService::class)->create(
                $request,
                $actor,
                'b2b',
                $this->storeId,
                $source,
                'admin',
            );

            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'channel' => 'b2b',
            ]);

            $state = OrderDispatchState::query()
                ->where('order_id', $order->id)
                ->firstOrFail();

            $this->assertSame('awaiting_dispatch', $state->status);
            $this->assertSame('missing_delivery_coordinates', $state->routing_reason);
            $this->assertSame($source, $state->context['order_source'] ?? null);
            $this->assertNull($state->current_assignee_id);
            $this->assertDatabaseMissing('driver_assignments', [
                'order_id' => $order->id,
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function orderPayload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customer->id,
            'address_id' => $this->address->id,
            'payment_method' => config('checkout.default_payment_method'),
            'discount_total' => 0,
            'delivery_total' => 0,
            'items' => [
                [
                    'product_id' => $this->productId,
                    'quantity' => 2,
                ],
            ],
        ];
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    private function assertAssigned(int $orderId, string $source): void
    {
        $state = OrderDispatchState::query()
            ->where('order_id', $orderId)
            ->firstOrFail();

        $this->assertSame('assigned', $state->status);
        $this->assertSame('van', $state->current_assignee_type);
        $this->assertSame($this->targetVanId, (int) $state->current_assignee_id);
        $this->assertSame($source, $state->context['order_source'] ?? null);

        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $orderId,
            'van_id' => $this->targetVanId,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('driver_assignments', [
            'order_id' => $orderId,
        ]);
    }
}
