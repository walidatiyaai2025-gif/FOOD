<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\B2bCustomer;
use App\Models\GeographyNode;
use App\Models\Order;
use App\Models\Role;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\AdminOrderManagementService;
use App\Services\CustomerDomainResolver;
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

class B2BOrderPostCreateRoutingTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $productId;

    private int $warehouseId;

    private User $customerUser;

    private B2bCustomer $customer;

    private Address $address;

    private User $routingActor;

    private int $routingVanId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        $this->storeId = app(WholesalePrincipal::class)->storeId();
        config(['foodex.platform_wholesale_store_code' => WholesalePrincipal::STORE_CODE]);

        [$this->productId, $this->warehouseId] = $this->product($this->storeId);

        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'unit_price' => 7.250,
            'minimum_quantity' => 1,
            'ordering_increment' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customerUser = app(PlatformCustomerService::class)->register([
            'name' => 'W02 Wholesale Customer',
            'email' => 'w02-customer@example.test',
            'phone' => '+201000001193',
            'password' => 'Password123!',
            'locale' => 'en',
        ]);
        $this->customer = app(CustomerDomainResolver::class)->b2b($this->customerUser);

        $platformCustomerId = (int) DB::table('platform_customers')
            ->where('user_id', $this->customerUser->id)
            ->value('id');

        $this->address = Address::query()->create([
            'customer_id' => $this->customer->legacy_customer_id,
            'platform_customer_id' => $platformCustomerId,
            'b2b_customer_id' => $this->customer->id,
            'label' => 'W02 Warehouse',
            'line1' => 'W02 Routing Street',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'latitude' => 30.5,
            'longitude' => 30.5,
            'is_default' => true,
        ]);

        $country = GeographyNode::query()->create([
            'type' => 'country',
            'code' => 'w02-eg',
            'name_ar' => 'مصر',
            'name_en' => 'Egypt',
            'country_code' => 'EG',
            'is_active' => true,
        ]);
        $territory = ServiceTerritory::query()->create([
            'code' => 'W02-TERRITORY',
            'name_ar' => 'منطقة W02',
            'name_en' => 'W02 Territory',
            'country_node_id' => $country->id,
            'status' => 'active',
            'priority' => 100,
        ]);
        app(TerritoryService::class)->addGeometry($territory, [
            'type' => 'Polygon',
            'coordinates' => [[
                [30.0, 30.0],
                [31.0, 30.0],
                [31.0, 31.0],
                [30.0, 31.0],
                [30.0, 30.0],
            ]],
        ]);

        $this->routingActor = User::factory()->create(['is_active' => true]);
        $registry = app(VanRegistryService::class);
        $routingVan = $registry->createVan(['code' => 'W02-ROUTING-VAN']);
        $this->routingVanId = (int) $routingVan->id;
        $registry->assign($this->routingActor, $routingVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);
    }

    public function test_customer_b2b_checkout_routes_to_responsible_van_after_commercial_commit(): void
    {
        Sanctum::actingAs($this->customerUser);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'quantity' => 1,
        ])->assertCreated();

        $response = $this->withHeader('Idempotency-Key', 'w02-customer-checkout-0001')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->storeId,
                'address_id' => $this->address->id,
                'payment_method' => 'cash_on_delivery',
            ])
            ->assertCreated()
            ->assertJsonPath('channel', 'b2b');

        $this->assertRoutedToVan(
            (int) $response->json('id'),
            $this->routingVanId,
            'customer_checkout',
        );
    }

    public function test_dashboard_b2b_order_uses_the_same_post_create_routing_hook(): void
    {
        $admin = $this->globalAdmin('B2B_ADMIN', 'w02-dashboard@example.test');

        $this->actingAs($admin)
            ->post('/admin/b2b/orders', $this->orderPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order = Order::query()
            ->where('store_id', $this->storeId)
            ->where('b2b_customer_id', $this->customer->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertRoutedToVan((int) $order->id, $this->routingVanId, 'dashboard');
    }

    public function test_van_created_b2b_order_is_routed_by_policy_not_self_assigned(): void
    {
        $actor = $this->vanActor();
        $actorVanId = (int) DB::table('van_assignments')
            ->where('representative_user_id', $actor->id)
            ->where('status', 'active')
            ->value('van_id');

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => $this->customer->id,
            'store_id' => $this->storeId,
            'status' => 'planned',
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        $response = $this->withHeader('Idempotency-Key', 'w02-van-order-000001')
            ->postJson('/api/v1/van/customers/b2b/'.$this->customer->id.'/orders', [
                'store_id' => $this->storeId,
                ...$this->orderPayload(),
            ])
            ->assertCreated();

        $orderId = (int) $response->json('data.id');
        $this->assertNotSame($actorVanId, $this->routingVanId);
        $this->assertRoutedToVan($orderId, $this->routingVanId, 'van');
        $this->assertDatabaseMissing('order_van_assignments', [
            'order_id' => $orderId,
            'van_id' => $actorVanId,
            'status' => 'active',
        ]);
    }

    public function test_routing_inability_keeps_valid_b2b_order_and_enters_van_only_exception_queue(): void
    {
        $address = Address::query()->create([
            'customer_id' => $this->customer->legacy_customer_id,
            'b2b_customer_id' => $this->customer->id,
            'label' => 'Missing coordinates',
            'line1' => 'Unmapped street',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'is_default' => false,
        ]);

        Sanctum::actingAs($this->customerUser);
        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'quantity' => 1,
        ])->assertCreated();

        $response = $this->withHeader('Idempotency-Key', 'w02-awaiting-dispatch-001')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->storeId,
                'address_id' => $address->id,
                'payment_method' => 'cash_on_delivery',
            ])
            ->assertCreated();

        $orderId = (int) $response->json('id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'channel' => 'b2b']);
        $this->assertDatabaseHas('order_dispatch_states', [
            'order_id' => $orderId,
            'status' => 'awaiting_dispatch',
            'routing_reason' => 'missing_delivery_coordinates',
            'current_assignee_type' => null,
            'current_assignee_id' => null,
        ]);
        $this->assertDatabaseMissing('order_van_assignments', [
            'order_id' => $orderId,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('driver_assignments', [
            'order_id' => $orderId,
        ]);
    }

    public function test_supported_integration_service_source_uses_the_same_b2b_hook(): void
    {
        $request = Request::create('/internal/integration/orders', 'POST', $this->orderPayload());

        $order = app(AdminOrderManagementService::class)->create(
            $request,
            $this->routingActor,
            'b2b',
            $this->storeId,
            'integration',
            'admin',
            'w02-integration-order-0001',
        );

        $this->assertRoutedToVan((int) $order->id, $this->routingVanId, 'integration');
    }

    /** @return array<string, mixed> */
    private function orderPayload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customer->id,
            'address_id' => $this->address->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $this->productId, 'quantity' => 1],
            ],
        ];
    }

    private function assertRoutedToVan(int $orderId, int $vanId, string $orderSource): void
    {
        $this->assertDatabaseHas('order_dispatch_states', [
            'order_id' => $orderId,
            'status' => 'assigned',
            'current_assignee_type' => 'van',
            'current_assignee_id' => $vanId,
        ]);
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $orderId,
            'van_id' => $vanId,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('order_van_execution_states', [
            'order_id' => $orderId,
            'van_id' => $vanId,
            'status' => 'assigned',
        ]);

        $context = DB::table('order_dispatch_states')
            ->where('order_id', $orderId)
            ->value('context');
        $decoded = is_string($context) ? json_decode($context, true, 512, JSON_THROW_ON_ERROR) : $context;
        $this->assertSame($orderSource, $decoded['order_source'] ?? null);
    }

    private function vanActor(): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'W02-ACTOR-VAN-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        return $actor;
    }

    private function globalAdmin(string $roleCode, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    /** @return array{0:int,1:int} */
    private function product(int $storeId): array
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'w02-routing',
            'name' => 'W02 Routing Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-W02',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'W02-SKU',
            'name' => 'W02 Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 7.250,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-W02',
            'name' => 'W02 Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$productId, $warehouseId];
    }
}
