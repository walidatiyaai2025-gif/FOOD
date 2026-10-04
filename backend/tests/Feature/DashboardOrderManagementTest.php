<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\B2cCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2c_store_admin_can_create_edit_and_cancel_manual_order_with_correct_reservations(): void
    {
        $store = $this->store('B2C', 'ORDER-RETAIL');
        [$product, $inventory] = $this->product($store, 'b2c', 'RETAIL-ORDER-SKU', 4.500, 10);
        $admin = $this->storeAdmin($store, 'retail-orders@example.test');
        $customer = app(B2cCustomerService::class)->create($store, [
            'name' => 'Retail Buyer',
            'email' => 'retail-buyer@example.test',
        ]);

        $this->actingAs($admin)
            ->get('/admin/b2c/orders?store_id='.$store)
            ->assertOk()
            ->assertSee('Create new order');

        $this->actingAs($admin)->post('/admin/b2c/orders', [
            'store_id' => $store,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'discount_total' => 1,
            'delivery_total' => 0.5,
            'customer_note' => 'Phone order',
            'items' => [
                ['product_id' => $product, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $order = DB::table('orders')->where('store_id', $store)->where('channel', 'b2c')->first();
        $this->assertNotNull($order);
        $this->assertSame((int) $customer->id, (int) $order->b2c_customer_id);
        $this->assertSame(9.0, (float) $order->subtotal);
        $this->assertSame(0.0, (float) $order->discount_total);
        $this->assertSame(0.0, (float) $order->delivery_total);
        $this->assertSame(9.0, (float) $order->grand_total);
        $this->assertNotNull($order->quote_id, 'Dashboard orders must persist the backend quote identity.');
        $this->assertSame(2.0, (float) DB::table('inventories')->where('id', $inventory)->value('reserved_quantity'));
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'cash_on_delivery', 'status' => 'pending']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'dashboard.order_created', 'store_id' => $store]);

        $this->actingAs($admin)->patch('/admin/b2c/orders/'.$order->id, [
            'store_id' => $store,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'discount_total' => 0,
            'delivery_total' => 0,
            'customer_note' => 'Quantity changed',
            'items' => [
                ['product_id' => $product, 'quantity' => 3],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_note' => 'Quantity changed',
        ]);
        $this->assertSame(13.5, (float) DB::table('orders')->where('id', $order->id)->value('grand_total'));
        $this->assertSame(3.0, (float) DB::table('inventories')->where('id', $inventory)->value('reserved_quantity'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'dashboard.order_updated', 'store_id' => $store]);

        $this->actingAs($admin)->post('/admin/b2c/orders/'.$order->id.'/status', [
            'store_id' => $store,
            'status' => 'cancelled',
            'note' => 'Customer cancelled',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertSame(0.0, (float) DB::table('inventories')->where('id', $inventory)->value('reserved_quantity'));
    }

    public function test_b2c_manual_order_rejects_foreign_store_product_and_customer_ids(): void
    {
        $storeA = $this->store('B2C', 'ORDER-A');
        $storeB = $this->store('B2C', 'ORDER-B');
        [$productA] = $this->product($storeA, 'b2c', 'ORDER-A-P', 2, 10);
        [$productB] = $this->product($storeB, 'b2c', 'ORDER-B-P', 3, 10);
        $admin = $this->storeAdmin($storeA, 'store-a-orders@example.test');
        $customerA = app(B2cCustomerService::class)->create($storeA, ['name' => 'Store A Buyer']);
        $customerB = app(B2cCustomerService::class)->create($storeB, ['name' => 'Store B Buyer']);

        $this->actingAs($admin)->post('/admin/b2c/orders', [
            'store_id' => $storeA,
            'customer_id' => $customerB->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $productA, 'quantity' => 1]],
        ])->assertNotFound();

        $this->actingAs($admin)->post('/admin/b2c/orders', [
            'store_id' => $storeA,
            'customer_id' => $customerA->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $productB, 'quantity' => 1]],
        ])->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_retail_invoice_dashboard_is_store_scoped_and_supports_controlled_reissue(): void
    {
        $storeA = $this->store('B2C', 'INVOICE-STORE-A');
        $storeB = $this->store('B2C', 'INVOICE-STORE-B');
        [$product] = $this->product($storeA, 'b2c', 'INVOICE-A-SKU', 3.750, 10);
        $adminA = $this->storeAdmin($storeA, 'invoice-admin-a@example.test');
        $adminB = $this->storeAdmin($storeB, 'invoice-admin-b@example.test');
        $customer = app(B2cCustomerService::class)->create($storeA, [
            'name' => 'Invoice Buyer',
            'email' => 'invoice-buyer@example.test',
        ]);

        $this->actingAs($adminA)->post('/admin/b2c/orders', [
            'store_id' => $storeA,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $product, 'quantity' => 2]],
        ])->assertRedirect();

        $order = DB::table('orders')->where('store_id', $storeA)->where('channel', 'b2c')->first();
        $this->assertNotNull($order);

        $this->actingAs($adminA)->post('/admin/b2c/orders/'.$order->id.'/status', [
            'store_id' => $storeA,
            'status' => 'confirmed',
            'note' => 'Approved',
        ])->assertRedirect();

        $invoice = DB::table('invoices')->where('order_id', $order->id)->where('status', 'issued')->first();
        $this->assertNotNull($invoice);

        $this->actingAs($adminA)
            ->get('/admin/b2c/finance?store_id='.$storeA)
            ->assertOk()
            ->assertSee($invoice->invoice_number);

        $this->actingAs($adminA)
            ->get('/admin/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee($invoice->invoice_number);

        $this->actingAs($adminA)
            ->get('/admin/invoices/'.$invoice->id.'/download?locale=ar')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($adminB)
            ->get('/admin/invoices/'.$invoice->id)
            ->assertNotFound();

        $this->actingAs($adminA)
            ->post('/admin/invoices/'.$invoice->id.'/void-reissue', [
                'reason' => 'Controlled invoice correction',
            ])
            ->assertRedirect();

        $replacement = DB::table('invoices')
            ->where('order_id', $order->id)
            ->where('status', 'reissued')
            ->orderByDesc('revision')
            ->first();
        $this->assertNotNull($replacement);
        $this->assertSame(2, (int) $replacement->revision);
        $this->assertSame((int) $invoice->id, (int) $replacement->revision_of_invoice_id);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'voided',
            'void_reason' => 'Controlled invoice correction',
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'invoice_id' => $replacement->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'invoice.voided',
            'auditable_id' => $invoice->id,
        ]);

        $this->actingAs($adminA)->post('/admin/b2c/orders/'.$order->id.'/status', [
            'store_id' => $storeA,
            'status' => 'cancelled',
            'note' => 'Customer cancelled after confirmation',
        ])->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'id' => $replacement->id,
            'status' => 'voided',
        ]);
    }

    public function test_b2b_dashboard_order_uses_approved_customer_tier_price_and_minimum_quantity(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$product, $inventory] = $this->product($store, 'b2b', 'WHOLESALE-ORDER-SKU', 10, 20);
        $warehouse = (int) DB::table('inventories')->where('inventories.id', $inventory)
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->value('warehouses.id');
        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'ORDER-GOLD',
            'name' => 'Order Gold',
            'priority' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tier,
            'store_id' => $store,
            'product_id' => $product,
            'unit_price' => 7.250,
            'minimum_quantity' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = app(B2bCustomerService::class)->create([
            'name' => 'Wholesale Buyer',
            'email' => 'wholesale-buyer@example.test',
        ]);
        B2bAccount::query()->create([
            'customer_id' => $customer->legacy_customer_id,
            'b2b_customer_id' => $customer->id,
            'price_tier_id' => $tier,
            'company_name' => 'Wholesale Buyer Co',
            'status' => 'active',
        ]);

        $admin = $this->globalAdmin('B2B_ADMIN', 'wholesale-orders@example.test');

        $this->actingAs($admin)
            ->get('/admin/b2b/orders')
            ->assertOk()
            ->assertSee('Create new order');

        $this->actingAs($admin)->post('/admin/b2b/orders', [
            'warehouse_id' => $warehouse,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $product, 'quantity' => 5]],
        ])->assertRedirect();

        $order = DB::table('orders')->where('store_id', $store)->where('channel', 'b2b')->first();
        $this->assertNotNull($order);
        $this->assertSame((int) $customer->id, (int) $order->b2b_customer_id);
        $this->assertSame($warehouse, (int) $order->warehouse_id);
        $this->assertSame(36.25, (float) $order->subtotal);
        $this->assertSame(36.25, (float) $order->grand_total);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product,
            'quantity' => 5,
            'unit_price' => 7.250,
            'line_total' => 36.250,
        ]);
        $this->assertSame(5.0, (float) DB::table('inventories')->where('id', $inventory)->value('reserved_quantity'));
        $this->assertDatabaseMissing('invoices', ['order_id' => $order->id]);

        $this->actingAs($admin)
            ->get('/admin/b2b/orders')
            ->assertOk()
            ->assertSee('Approve order')
            ->assertSee('Reject order')
            ->assertSee('Rejection reason (required)');

        $this->actingAs($admin)->post('/admin/b2b/orders/'.$order->id.'/status', [
            'status' => 'cancelled',
        ])->assertSessionHasErrors('note');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);

        $this->actingAs($admin)->post('/admin/b2b/orders/'.$order->id.'/status', [
            'status' => 'confirmed',
            'note' => 'Commercially approved',
        ])->assertRedirect();

        $invoice = DB::table('invoices')->where('order_id', $order->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('b2b', $invoice->channel);
        $this->assertSame('ORDER-GOLD', $invoice->price_tier_code_snapshot);
        $this->assertSame(36.25, (float) $invoice->total);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'product_id' => $product,
            'quantity' => 5,
            'unit_price' => 7.250,
            'price_tier_code_snapshot' => 'ORDER-GOLD',
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
        ]);

        DB::table('b2b_price_rules')->where('price_tier_id', $tier)->where('product_id', $product)->update(['unit_price' => 99.999]);
        $this->assertSame(7.25, (float) DB::table('invoice_items')->where('invoice_id', $invoice->id)->value('unit_price'));

        $this->actingAs($admin)->post('/admin/b2b/orders', [
            'warehouse_id' => $warehouse,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $product, 'quantity' => 1]],
        ])->assertSessionHasErrors('items');

        $this->assertSame(1, DB::table('orders')->where('store_id', $store)->where('channel', 'b2b')->count());
    }

    public function test_retail_dashboard_quote_and_multiline_invoice_are_authoritative(): void
    {
        $store = $this->store('B2C', 'ORDER-MULTI-RETAIL');
        [$productA] = $this->product($store, 'b2c', 'MULTI-RETAIL-A', 4.500, 20);
        [$productB] = $this->product($store, 'b2c', 'MULTI-RETAIL-B', 2.000, 20);
        $admin = $this->storeAdmin($store, 'multi-retail@example.test');
        $customer = app(B2cCustomerService::class)->create($store, [
            'name' => 'Multi Retail Buyer',
            'email' => 'multi-retail-buyer@example.test',
        ]);
        $payload = [
            'store_id' => $store,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $productA, 'quantity' => 2],
                ['product_id' => $productB, 'quantity' => 3],
            ],
        ];

        $quoteResponse = $this->actingAs($admin)->postJson('/admin/b2c/orders/quote', $payload)->assertOk();
        $quote = $quoteResponse->json('data');
        $this->assertSame('backend_authoritative_quote_v1', $quote['pricing_source']);
        $this->assertFalse((bool) $quote['has_unavailable_items']);
        $this->assertSame(15.0, (float) $quote['subtotal']);
        $this->assertCount(2, $quote['items']);

        $this->actingAs($admin)->post('/admin/b2c/orders', $payload)->assertRedirect();

        $order = DB::table('orders')->where('store_id', $store)->where('channel', 'b2c')->first();
        $this->assertNotNull($order);
        $this->assertSame(2, DB::table('order_items')->where('order_id', $order->id)->count());
        $this->assertSame(15.0, (float) $order->subtotal);

        $this->actingAs($admin)->post('/admin/b2c/orders/'.$order->id.'/status', [
            'store_id' => $store,
            'status' => 'confirmed',
            'note' => 'Confirm multi-line Retail order',
        ])->assertRedirect();

        $invoice = DB::table('invoices')->where('order_id', $order->id)->where('status', 'issued')->first();
        $this->assertNotNull($invoice);
        $this->assertSame((float) $order->grand_total, (float) $invoice->total);
        $this->assertSame(2, DB::table('invoice_items')->where('invoice_id', $invoice->id)->count());

        $this->actingAs($admin)
            ->get('/admin/b2c/orders?store_id='.$store)
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('Print');
    }

    public function test_wholesale_dashboard_quote_uses_selected_warehouse_and_tier_for_multiple_lines(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$productA, $inventoryA] = $this->product($store, 'b2b', 'MULTI-B2B-A', 12, 20);
        [$productB] = $this->product($store, 'b2b', 'MULTI-B2B-B', 20, 20);
        $warehouse = (int) DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('inventories.id', $inventoryA)
            ->value('warehouses.id');
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse,
            'product_id' => $productB,
            'quantity' => 20,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'MULTI-GOLD',
            'name' => 'Multi Gold',
            'priority' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([[$productA, 7.500, 2], [$productB, 11.000, 3]] as [$product, $price, $minimum]) {
            DB::table('b2b_price_rules')->insert([
                'price_tier_id' => $tier,
                'store_id' => $store,
                'product_id' => $product,
                'unit_price' => $price,
                'minimum_quantity' => $minimum,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $customer = app(B2bCustomerService::class)->create([
            'name' => 'Multi Wholesale Buyer',
            'email' => 'multi-wholesale@example.test',
        ]);
        B2bAccount::query()->create([
            'customer_id' => $customer->legacy_customer_id,
            'b2b_customer_id' => $customer->id,
            'price_tier_id' => $tier,
            'company_name' => 'Multi Wholesale Co',
            'status' => 'active',
        ]);
        $admin = $this->globalAdmin('B2B_ADMIN', 'multi-b2b-admin@example.test');
        $payload = [
            'warehouse_id' => $warehouse,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $productA, 'quantity' => 2],
                ['product_id' => $productB, 'quantity' => 3],
            ],
        ];

        $quoteResponse = $this->actingAs($admin)->postJson('/admin/b2b/orders/quote', $payload)->assertOk();
        $quote = $quoteResponse->json('data');
        $this->assertFalse((bool) $quote['has_unavailable_items']);
        $this->assertSame('MULTI-GOLD', $quote['customer_context']['price_tier_code']);
        $this->assertSame(48.0, (float) $quote['subtotal']);
        $this->assertCount(2, $quote['items']);
        $this->assertSame(20.0, (float) $quote['items'][1]['available_quantity']);

        $this->actingAs($admin)->post('/admin/b2b/orders', $payload)->assertRedirect();
        $order = DB::table('orders')->where('store_id', $store)->where('channel', 'b2b')->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame(2, DB::table('order_items')->where('order_id', $order->id)->count());

        $this->actingAs($admin)->post('/admin/b2b/orders/'.$order->id.'/status', [
            'status' => 'confirmed',
            'note' => 'Confirm multi-line Wholesale order',
        ])->assertRedirect();

        $invoice = DB::table('invoices')->where('order_id', $order->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('MULTI-GOLD', $invoice->price_tier_code_snapshot);
        $this->assertSame((float) $order->grand_total, (float) $invoice->total);
        $this->assertSame(2, DB::table('invoice_items')->where('invoice_id', $invoice->id)->count());
    }

    public function test_central_new_order_wizard_is_scoped_and_final_create_reprices_authoritatively(): void
    {
        $storeA = $this->store('B2C', 'WIZARD-STORE-A');
        $storeB = $this->store('B2C', 'WIZARD-STORE-B');
        [$productA, $inventoryA] = $this->product($storeA, 'b2c', 'WIZARD-A-SKU', 4.500, 20);
        [$productB] = $this->product($storeB, 'b2c', 'WIZARD-B-SKU', 8.000, 20);
        $admin = $this->storeAdmin($storeA, 'wizard-retail@example.test');
        $customerA = app(B2cCustomerService::class)->create($storeA, [
            'name' => 'Wizard Buyer A',
            'email' => 'wizard-a@example.test',
        ]);
        app(B2cCustomerService::class)->create($storeB, [
            'name' => 'Wizard Buyer B',
            'email' => 'wizard-b@example.test',
        ]);

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertOk()
            ->assertSee('data-new-order-open', false)
            ->assertSee('data-new-order-modal', false)
            ->assertSee('data-wizard-step="0"', false)
            ->assertSee('data-wizard-step="5"', false)
            ->assertSee('WIZARD-STORE-A')
            ->assertSee('Wizard Buyer A')
            ->assertSee('WIZARD-A-SKU')
            ->assertDontSee('WIZARD-STORE-B')
            ->assertDontSee('Wizard Buyer B')
            ->assertDontSee('WIZARD-B-SKU');

        $payload = [
            'channel' => 'b2c',
            'store_id' => $storeA,
            'customer_id' => $customerA->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [
                ['product_id' => $productA, 'quantity' => 2],
            ],
        ];

        $quote = $this->actingAs($admin)
            ->postJson('/admin/operations/orders/quote', $payload)
            ->assertOk()
            ->json('data');

        $this->assertSame(9.0, (float) $quote['subtotal']);
        $this->assertFalse((bool) $quote['has_unavailable_items']);

        DB::table('store_products')
            ->where('store_id', $storeA)
            ->where('product_id', $productA)
            ->update(['price' => 7.000, 'updated_at' => now()]);

        $response = $this->actingAs($admin)->post('/admin/operations/orders', $payload);
        $response->assertRedirect();

        $order = DB::table('orders')
            ->where('store_id', $storeA)
            ->where('channel', 'b2c')
            ->latest('id')
            ->first();

        $this->assertNotNull($order);
        $this->assertSame(14.0, (float) $order->subtotal);
        $this->assertSame(2.0, (float) DB::table('inventories')->where('id', $inventoryA)->value('reserved_quantity'));
        $response->assertRedirect(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'store_id' => $storeA,
            'status' => 'pending',
            'order' => $order->id,
        ]));
    }

    public function test_central_new_order_endpoint_rejects_invalid_and_foreign_store_submissions_without_partial_order(): void
    {
        $storeA = $this->store('B2C', 'WIZARD-SCOPE-A');
        $storeB = $this->store('B2C', 'WIZARD-SCOPE-B');
        [$productA] = $this->product($storeA, 'b2c', 'WIZARD-SCOPE-A-SKU', 3.000, 10);
        $admin = $this->storeAdmin($storeA, 'wizard-scope@example.test');
        $customerA = app(B2cCustomerService::class)->create($storeA, ['name' => 'Wizard Scope Buyer']);

        $this->actingAs($admin)->post('/admin/operations/orders', [
            'channel' => 'b2c',
            'store_id' => $storeA,
            'customer_id' => $customerA->id,
            'items' => [['product_id' => $productA, 'quantity' => 1]],
        ])->assertSessionHasErrors('payment_method');

        $this->assertDatabaseCount('orders', 0);

        $this->actingAs($admin)->post('/admin/operations/orders', [
            'channel' => 'b2c',
            'store_id' => $storeB,
            'customer_id' => $customerA->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $productA, 'quantity' => 1]],
        ])->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
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

    /** @return array{0:int,1:int} */
    private function product(int $storeId, string $channel, string $sku, float $price, float $stock): array
    {
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'cat-'.strtolower($sku),
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unit = (int) DB::table('units')->insertGetId([
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'EA-'.$sku,
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unit,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $product,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventory = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => $stock,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$product, $inventory];
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Store Admin',
            'email' => $email,
            'password' => 'password',
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

    private function globalAdmin(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
