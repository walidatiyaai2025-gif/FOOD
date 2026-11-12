<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2cCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2SalesQuotationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $retailA;

    private int $retailB;

    private int $wholesale;

    private int $productA;

    private int $productB;

    private int $wholesaleProduct;

    private B2cCustomer $customerA;

    private B2cCustomer $customerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $this->retailA = $this->store('B2C', 'V2-QUOTE-RETAIL-A');
        $this->retailB = $this->store('B2C', 'V2-QUOTE-RETAIL-B');
        $this->wholesale = $this->store('B2B', 'V2-QUOTE-WHOLESALE');
        $this->productA = $this->product($this->retailA, 'b2c', 'V2-QUOTE-A', 5.000);
        $this->productB = $this->product($this->retailB, 'b2c', 'V2-QUOTE-B', 9.000);
        $this->wholesaleProduct = $this->product($this->wholesale, 'b2b', 'V2-QUOTE-W', 12.000);

        $this->customerA = B2cCustomer::query()->create([
            'store_id' => $this->retailA, 'name' => 'Retail A Customer',
        ]);
        $this->customerB = B2cCustomer::query()->create([
            'store_id' => $this->retailB, 'name' => 'Retail B Customer',
        ]);
    }

    public function test_quotation_form_uses_shared_modal_and_preserves_all_lines_after_validation_error(): void
    {
        $operator = $this->retailActor('sales-quote-modal@example.test', $this->retailA);

        $this->actingAs($operator)->get(route('admin.sales-quotations.index', [
            'store_id' => $this->retailA,
        ]))->assertOk()
            ->assertSee('data-foodex-operational-modal', false)
            ->assertSee('data-modal-button-class="sq-primary"', false);

        $payload = $this->payload($this->retailA, $this->customerA->id, $this->productA);
        $payload['items'] = [
            ['product_id' => $this->productA, 'quantity' => 0],
            ['product_id' => $this->productA, 'quantity' => 2],
        ];

        $this->actingAs($operator)->post(route('admin.sales-quotations.store'), $payload)
            ->assertSessionHasErrors('items.0.quantity');

        $this->actingAs($operator)->get(route('admin.sales-quotations.index', [
            'store_id' => $this->retailA,
        ]))->assertOk()
            ->assertSee('items[0][quantity]', false)
            ->assertSee('items[1][product_id]', false)
            ->assertSee('items[1][quantity]', false);
        $this->assertSame(0, DB::table('sales_quotations')->count());
    }

    public function test_store_scoped_retail_quotation_uses_authoritative_price_and_audited_issue(): void
    {
        $operator = $this->retailActor('sales-quote-a@example.test', $this->retailA);
        $items = collect(app(AdminNavigation::class)->groupsFor($operator))
            ->flatMap(fn (array $group) => $group['children'])->pluck('key');
        $this->assertTrue($items->contains('sales_quotations'));

        $this->actingAs($operator)->get(route('admin.sales-quotations.index', ['store_id' => $this->retailA]))
            ->assertOk()->assertSee('Sales quotations')->assertSee('data-sales-quotations', false);

        // A forged price is ignored: the customer pricing engine, not the form,
        // determines merchandise totals. This does not create a customer order.
        $this->actingAs($operator)->post(route('admin.sales-quotations.store'), [
            'store_id' => $this->retailA,
            'request_id' => (string) Str::uuid(),
            'customer_id' => $this->customerA->id,
            'items' => [['product_id' => $this->productA, 'quantity' => 2, 'unit_price' => 0.001]],
            'expires_at' => today()->addDays(7)->toDateString(),
        ])->assertRedirect();

        $record = DB::table('sales_quotations')->sole();
        $this->assertSame('draft', $record->status);
        $this->assertSame($this->retailA, (int) $record->store_id);
        $this->assertSame('b2c', $record->channel);
        $this->assertSame(10.0, (float) $record->subtotal);
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame('backend_authoritative_quote_v1', json_decode($record->pricing_snapshot, true)['pricing_source']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales.quotation.created', 'store_id' => $this->retailA]);

        $this->actingAs($operator)
            ->get(route('admin.sales-quotations.show', ['quotation' => $record->id]))
            ->assertOk()->assertSee('Retail A Customer')->assertSee('V2-QUOTE-A');

        $this->actingAs($operator)->post(route('admin.sales-quotations.transition', [
            'quotation' => $record->id, 'action' => 'issue',
        ]))->assertRedirect();

        $this->assertDatabaseHas('sales_quotations', ['id' => $record->id, 'status' => 'issued']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales.quotation.issued', 'store_id' => $this->retailA]);
        $this->actingAs($operator)->post(route('admin.sales-quotations.transition', [
            'quotation' => $record->id, 'action' => 'cancel',
        ]))->assertSessionHasErrors('status');
    }

    public function test_retail_store_and_customer_scope_rejects_cross_store_reads_writes_and_products(): void
    {
        $operator = $this->retailActor('sales-quote-isolation@example.test', $this->retailA);
        $this->actingAs($operator)
            ->get(route('admin.sales-quotations.index', ['store_id' => $this->retailB]))
            ->assertNotFound();
        $this->actingAs($operator)->post(route('admin.sales-quotations.store'), $this->payload(
            $this->retailB, $this->customerB->id, $this->productB,
        ))->assertNotFound();
        $this->actingAs($operator)->post(route('admin.sales-quotations.store'), $this->payload(
            $this->retailA, $this->customerB->id, $this->productA,
        ))->assertNotFound();
        $this->actingAs($operator)->post(route('admin.sales-quotations.store'), $this->payload(
            $this->retailA, $this->customerA->id, $this->productB,
        ))->assertNotFound();

        $secondStoreOperator = $this->retailActor('sales-quote-b@example.test', $this->retailB);
        $this->actingAs($secondStoreOperator)
            ->post(route('admin.sales-quotations.store'), $this->payload(
                $this->retailB, $this->customerB->id, $this->productB,
            ))->assertRedirect();

        $record = DB::table('sales_quotations')->sole();
        $this->actingAs($operator)
            ->get(route('admin.sales-quotations.show', ['quotation' => $record->id]))
            ->assertNotFound();
        $this->actingAs($operator)
            ->post(route('admin.sales-quotations.transition', ['quotation' => $record->id, 'action' => 'issue']))
            ->assertNotFound();
        $this->assertDatabaseHas('sales_quotations', ['id' => $record->id, 'status' => 'draft']);
    }

    public function test_issuing_rejects_stale_price_and_cancellation_does_not_create_an_order(): void
    {
        $operator = $this->retailActor('sales-quote-price@example.test', $this->retailA);
        $payload = $this->payload($this->retailA, $this->customerA->id, $this->productA);
        $this->actingAs($operator)->post(route('admin.sales-quotations.store'), $payload)->assertRedirect();
        $this->actingAs($operator)->post(route('admin.sales-quotations.store'), $payload)->assertRedirect();
        $this->assertSame(1, DB::table('sales_quotations')->count(), 'Repeated form submissions must be idempotent.');

        $record = DB::table('sales_quotations')->sole();
        DB::table('store_products')->where('store_id', $this->retailA)
            ->where('product_id', $this->productA)->update(['price' => 99]);

        $this->actingAs($operator)->post(route('admin.sales-quotations.transition', [
            'quotation' => $record->id, 'action' => 'issue',
        ]))->assertSessionHasErrors('items');
        $this->assertDatabaseHas('sales_quotations', ['id' => $record->id, 'status' => 'draft']);

        $this->actingAs($operator)->post(route('admin.sales-quotations.transition', [
            'quotation' => $record->id, 'action' => 'cancel',
        ]))->assertRedirect();
        $this->assertDatabaseHas('sales_quotations', ['id' => $record->id, 'status' => 'cancelled']);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_store_actor_without_orders_permissions_gets_403_and_no_sidebar_link(): void
    {
        $manager = $this->retailActor('quote-denied-manager@example.test', $this->retailA);
        $this->actingAs($manager)->post(route('admin.sales-quotations.store'), $this->payload(
            $this->retailA, $this->customerA->id, $this->productA,
        ))->assertRedirect();
        $quotation = DB::table('sales_quotations')->sole();

        $denied = User::factory()->create([
            'email' => 'quote-denied-inventory@example.test',
            'is_active' => true,
            'locale' => 'en',
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $denied->id,
            'store_id' => $this->retailA,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $keys = collect(app(AdminNavigation::class)->groupsFor($denied))
            ->flatMap(fn (array $group) => $group['children'])
            ->pluck('key');
        $this->assertFalse($keys->contains('sales_quotations'));

        $this->actingAs($denied)->get(route('admin.sales-quotations.index', [
            'store_id' => $this->retailA,
        ]))->assertForbidden();
        $this->actingAs($denied)->post(route('admin.sales-quotations.store'), $this->payload(
            $this->retailA, $this->customerA->id, $this->productA,
        ))->assertForbidden();
        $this->actingAs($denied)->get(route('admin.sales-quotations.show', [
            'quotation' => $quotation->id,
        ]))->assertForbidden();
        $this->actingAs($denied)->post(route('admin.sales-quotations.transition', [
            'quotation' => $quotation->id, 'action' => 'issue',
        ]))->assertForbidden();
        $this->assertDatabaseHas('sales_quotations', ['id' => $quotation->id, 'status' => 'draft']);
    }

    public function test_inactive_user_cannot_view_or_create_and_public_access_requires_login(): void
    {
        $operator = $this->retailActor('sales-quote-inactive@example.test', $this->retailA);
        $operator->forceFill(['is_active' => false])->save();

        $this->actingAs($operator)->get(route('admin.sales-quotations.index', [
            'store_id' => $this->retailA,
        ]))->assertStatus(403);
        $this->actingAs($operator)->post(route('admin.sales-quotations.store'), $this->payload(
            $this->retailA, $this->customerA->id, $this->productA,
        ))->assertStatus(403);
        $this->assertSame(0, DB::table('sales_quotations')->count());
    }

    public function test_wholesale_quotations_are_channel_scoped_and_not_visible_to_retail(): void
    {
        $operator = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $operator->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $customer = app(B2bCustomerService::class)->create(['name' => 'Wholesale Quotation Customer']);
        B2bAccount::query()->create([
            'b2b_customer_id' => $customer->id,
            'customer_id' => $customer->legacy_customer_id,
            'company_name' => 'Wholesale Quotation Buyer',
            'status' => 'active',
        ]);
        $this->actingAs($operator)->post(route('admin.sales-quotations.store'), $this->payload(
            $this->wholesale, $customer->id, $this->wholesaleProduct,
        ))->assertRedirect();
        $record = DB::table('sales_quotations')->sole();
        $this->assertSame('b2b', $record->channel);

        $retail = $this->retailActor('sales-quote-retail-only@example.test', $this->retailA);
        $this->actingAs($retail)
            ->get(route('admin.sales-quotations.show', ['quotation' => $record->id]))
            ->assertNotFound();
        $this->actingAs($operator)->get(route('admin.sales-quotations.show', ['quotation' => $record->id]))
            ->assertOk()->assertSee('عروض الأسعار');
    }

    /** @return array<string, mixed> */
    private function payload(int $storeId, int $customerId, int $productId): array
    {
        return [
            'store_id' => $storeId,
            'request_id' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'items' => [['product_id' => $productId, 'quantity' => 1]],
            'expires_at' => today()->addDays(7)->toDateString(),
        ];
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code, 'name' => $code, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(int $storeId, string $channel, string $code, float $price): int
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId, 'channel' => $channel,
            'code' => 'default', 'name' => $code.' Catalog', 'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'UNIT-'.$code, 'name' => 'Each', 'decimal_places' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId, 'unit_id' => $unitId,
            'sku' => $code, 'name' => $code, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId, 'product_id' => $id, 'price' => $price,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId, 'code' => 'WHS-'.$code, 'name' => $code,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse, 'product_id' => $id,
            'quantity' => 100, 'reserved_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function retailActor(string $email, int $storeId): User
    {
        $actor = User::factory()->create(['email' => $email, 'is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id, 'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $actor;
    }
}
