<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Contracts\OrderNotificationBridge;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Models\Order;
use App\Models\Role;
use App\Models\SmartOrderingOrderContext;
use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmartOrderingCoreTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $productId;

    private int $extraProductId;

    private int $warehouseId;

    private string $publishedRevisionPublicId;

    private SmartOrderingBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-SMART-ORDERING',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'SMART-ORDERING-B2C',
            'name' => 'Smart Ordering Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $this->storeId,
            'channel' => 'b2c',
            'code' => 'smart-ordering',
            'name' => 'Smart Ordering Menu',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Meals',
            'slug' => 'smart-ordering-meals',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = $this->product($catalogId, $categoryId, $unitId, 'SO-MEAL', 'Meal', 1.250);
        $this->extraProductId = $this->product($catalogId, $categoryId, $unitId, 'SO-EXTRA', 'Extra', 0.500);

        $this->warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $this->storeId,
            'code' => 'SO-WH',
            'name' => 'Smart Ordering Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$this->productId, $this->extraProductId] as $productId) {
            DB::table('inventories')->insert([
                'warehouse_id' => $this->warehouseId,
                'product_id' => $productId,
                'quantity' => 50,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $this->storeId,
            'slug' => 'smart-ordering-test-'.Str::lower(Str::random(8)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('smart_ordering_entitlements')->insert(
            collect(['ordering', 'dine_in', 'pickup', 'delivery'])
                ->map(fn (string $module): array => [
                    'site_id' => $siteId,
                    'branch_id' => null,
                    'scope_key' => 'site',
                    'module' => $module,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all(),
        );

        $this->publishedRevisionPublicId = (string) Str::uuid();
        $revisionPayload = json_encode([
            'schema_version' => 1,
            'site' => ['slug' => 'smart-ordering-test', 'name_en' => 'Smart Ordering Restaurant'],
        ], JSON_THROW_ON_ERROR);
        DB::table('smart_ordering_revisions')->insert([
            'public_id' => $this->publishedRevisionPublicId,
            'site_id' => $siteId,
            'status' => 'published',
            'schema_version' => 1,
            'payload' => $revisionPayload,
            'checksum' => hash('sha256', $revisionPayload),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => 'kw-city',
            'name_ar' => 'فرع مدينة الكويت',
            'name_en' => 'Kuwait City Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
        ]);
    }

    public function test_cart_is_pinned_to_published_revision_and_rejects_stale_revision(): void
    {
        $response = $this->postJson('/api/smart-ordering/carts', [
            'branch_public_id' => (string) $this->branch->public_id,
        ])->assertCreated();

        $this->withHeader('X-Smart-Cart-Token', (string) $response->json('cart.token'))
            ->getJson('/api/smart-ordering/carts/'.(string) $response->json('cart.public_id'))
            ->assertOk()
            ->assertJsonPath('data.revision_public_id', $this->publishedRevisionPublicId);

        $this->postJson('/api/smart-ordering/carts', [
            'branch_public_id' => (string) $this->branch->public_id,
            'revision_public_id' => (string) Str::uuid(),
        ])->assertConflict();
    }

    public function test_checkout_uses_authoritative_server_prices_and_server_priced_extras(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 2, [
            'extras' => [['product_id' => $this->extraProductId, 'quantity' => 1, 'price' => 0.001]],
            'client_modifier_total' => 0.001,
        ]);

        $response = $this->checkout($cart, $token, 'smart-ordering-authority-0001', [
            'fulfillment_mode' => 'pickup',
            'subtotal' => 0.001,
            'grand_total' => 0.001,
        ])->assertCreated();

        $this->assertSame(3.5, (float) $response->json('data.subtotal'));
        $this->assertSame(3.5, (float) $response->json('data.grand_total'));
        $this->assertDatabaseHas('order_items', [
            'product_id' => $this->productId,
            'quantity' => 2,
            'unit_price' => 1.250,
        ]);
        $this->assertDatabaseHas('order_items', [
            'product_id' => $this->extraProductId,
            'quantity' => 2,
            'unit_price' => 0.500,
        ]);
    }

    public function test_public_storefront_cart_resolves_public_menu_and_modifier_identities(): void
    {
        $revisionId = (int) DB::table('smart_ordering_revisions')
            ->where('site_id', $this->branch->site_id)
            ->where('public_id', $this->publishedRevisionPublicId)
            ->value('id');

        $menuId = (int) DB::table('smart_ordering_menus')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $this->branch->site_id,
            'public_id' => (string) Str::uuid(),
            'name_en' => 'Public menu',
            'is_default' => true,
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('smart_ordering_menu_categories')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $this->branch->site_id,
            'menu_id' => $menuId,
            'public_id' => (string) Str::uuid(),
            'name_en' => 'Meals',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemPublicId = (string) Str::uuid();
        $itemId = (int) DB::table('smart_ordering_menu_items')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $this->branch->site_id,
            'category_id' => $categoryId,
            'public_id' => $itemPublicId,
            'source_product_id' => $this->productId,
            'name_en' => 'Public meal',
            'is_visible' => true,
            'sold_out' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $groupPublicId = (string) Str::uuid();
        $groupId = (int) DB::table('smart_ordering_modifier_groups')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $this->branch->site_id,
            'item_id' => $itemId,
            'public_id' => $groupPublicId,
            'name_en' => 'Extra',
            'kind' => 'modifier',
            'selection_type' => 'single',
            'is_required' => true,
            'min_selections' => 1,
            'max_selections' => 1,
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $optionPublicId = (string) Str::uuid();
        DB::table('smart_ordering_modifier_options')->insert([
            'group_id' => $groupId,
            'public_id' => $optionPublicId,
            'source_product_id' => $this->extraProductId,
            'name_en' => 'Extra',
            'price_delta' => 0.500,
            'is_visible' => true,
            'sold_out' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$cart, $token] = $this->cart();
        $response = $this->withHeader('X-Smart-Cart-Token', $token)
            ->postJson('/api/smart-ordering/carts/'.$cart.'/items', [
                'menu_item_public_id' => $itemPublicId,
                'quantity' => 2,
                'selection' => [
                    'menu_modifiers' => [[
                        'group_public_id' => $groupPublicId,
                        'option_public_ids' => [$optionPublicId],
                    ]],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.item.product_id', $this->productId)
            ->assertJsonPath('data.item.menu_item_public_id', $itemPublicId)
            ->assertJsonPath('data.item.selection.menu_modifiers.0.group_public_id', $groupPublicId)
            ->assertJsonPath('data.item.selection.menu_modifiers.0.option_public_ids.0', $optionPublicId);

        $this->assertSame(3.5, (float) $response->json('data.quote.grand_total'));

        [$invalidCart, $invalidToken] = $this->cart();
        $this->withHeader('X-Smart-Cart-Token', $invalidToken)
            ->postJson('/api/smart-ordering/carts/'.$invalidCart.'/items', [
                'menu_item_public_id' => $itemPublicId,
                'quantity' => 1,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('smart_ordering_cart_items', 1);
    }

    public function test_checkout_rejects_closed_branch_and_revalidates_operational_policy(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        DB::table('smart_ordering_branch_hours')->insert([
            'branch_id' => $this->branch->id,
            'day_of_week' => now('Asia/Kuwait')->dayOfWeek,
            'is_closed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->checkout($cart, $token, 'smart-ordering-closed-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertConflict();

        $this->assertDatabaseCount('orders', 0);

        DB::table('smart_ordering_branch_hours')->delete();
        $this->branch->update(['accepting_orders' => false]);

        $this->checkout($cart, $token, 'smart-ordering-paused-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertConflict();
    }

    public function test_delivery_zone_fee_and_minimum_are_enforced(): void
    {
        DB::table('smart_ordering_delivery_zones')->insert([
            'public_id' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'name' => 'Central Kuwait',
            'center_latitude' => 29.3759000,
            'center_longitude' => 47.9774000,
            'radius_km' => 5,
            'delivery_fee' => 0.750,
            'minimum_order' => 2.000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$smallCart, $smallToken] = $this->cart();
        $this->addItem($smallCart, $smallToken, 1);
        $this->checkout($smallCart, $smallToken, 'smart-ordering-zone-min-0001', [
            'fulfillment_mode' => 'delivery',
            'delivery_address' => 'Kuwait City',
            'delivery_latitude' => 29.3759,
            'delivery_longitude' => 47.9774,
        ])->assertConflict();

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 2);
        $this->checkout($cart, $token, 'smart-ordering-zone-ok-0001', [
            'fulfillment_mode' => 'delivery',
            'delivery_address' => 'Kuwait City',
            'delivery_latitude' => 29.3759,
            'delivery_longitude' => 47.9774,
        ])
            ->assertCreated()
            ->assertJsonPath('data.delivery_total', 0.75)
            ->assertJsonPath('data.grand_total', 3.25);

        [$outsideCart, $outsideToken] = $this->cart();
        $this->addItem($outsideCart, $outsideToken, 2);
        $this->checkout($outsideCart, $outsideToken, 'smart-ordering-zone-out-0001', [
            'fulfillment_mode' => 'delivery',
            'delivery_address' => 'Outside zone',
            'delivery_latitude' => 29.0,
            'delivery_longitude' => 48.5,
        ])->assertConflict();
    }

    public function test_dine_in_requires_valid_branch_table_token(): void
    {
        $tablePublicId = (string) Str::uuid();
        DB::table('smart_ordering_tables')->insert([
            'public_id' => $tablePublicId,
            'branch_id' => $this->branch->id,
            'label' => 'T12',
            'token_hash' => hash('sha256', 'valid-table-token'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        $this->checkout($cart, $token, 'smart-ordering-table-bad-0001', [
            'fulfillment_mode' => 'dine_in',
            'table_public_id' => $tablePublicId,
            'table_token' => 'wrong-table-token',
        ])->assertNotFound();

        $this->checkout($cart, $token, 'smart-ordering-table-ok-0001', [
            'fulfillment_mode' => 'dine_in',
            'table_public_id' => $tablePublicId,
            'table_token' => 'valid-table-token',
        ])->assertCreated();
    }

    public function test_duplicate_checkout_replays_same_order_without_duplicate_rows(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);
        $key = 'smart-ordering-idempotent-0001';

        $first = $this->checkout($cart, $token, $key, ['fulfillment_mode' => 'pickup'])
            ->assertCreated();
        $reference = (string) $first->json('data.reference');

        $this->checkout($cart, $token, $key, ['fulfillment_mode' => 'pickup'])
            ->assertOk()
            ->assertJsonPath('data.reference', $reference)
            ->assertJsonPath('replayed', true);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('smart_ordering_order_contexts', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_checkout_rejects_stale_sold_out_cart(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 2);

        DB::table('inventories')
            ->where('warehouse_id', $this->warehouseId)
            ->where('product_id', $this->productId)
            ->update(['reserved_quantity' => 50]);

        $this->checkout($cart, $token, 'smart-ordering-stale-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertConflict();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_lifecycle_validates_transitions_is_idempotent_and_consumes_stock_on_completion(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 2);
        $checkout = $this->checkout($cart, $token, 'smart-ordering-lifecycle-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();
        $reference = (string) $checkout->json('data.reference');

        $admin = $this->storeAdmin($this->storeId);

        $this->actingAs($admin)->post(route('admin.smart-ordering.orders.transition', $reference), [
            'status' => 'PREPARING',
            'transition_key' => 'invalid-direct-preparing',
        ])->assertSessionHasErrors('status');

        foreach (['CONFIRMED', 'PREPARING', 'READY', 'COMPLETED'] as $index => $status) {
            $this->actingAs($admin)->post(route('admin.smart-ordering.orders.transition', $reference), [
                'status' => $status,
                'transition_key' => 'valid-transition-'.$index,
            ])->assertRedirect();
        }

        $this->assertDatabaseHas('smart_ordering_order_contexts', [
            'public_reference' => $reference,
            'lifecycle_status' => 'COMPLETED',
        ]);
        $context = SmartOrderingOrderContext::query()->where('public_reference', $reference)->firstOrFail();
        $this->assertSame('delivered', (string) Order::query()->findOrFail($context->order_id)->status);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->warehouseId,
            'product_id' => $this->productId,
            'quantity' => 48,
            'reserved_quantity' => 0,
        ]);

        $eventCount = DB::table('smart_ordering_order_events')->where('order_context_id', $context->id)->count();
        $this->actingAs($admin)->post(route('admin.smart-ordering.orders.transition', $reference), [
            'status' => 'COMPLETED',
            'transition_key' => 'duplicate-terminal',
        ])->assertRedirect();
        $this->assertSame($eventCount, DB::table('smart_ordering_order_events')->where('order_context_id', $context->id)->count());
    }

    public function test_admin_cannot_manage_foreign_tenant_branch(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $foreignStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'SMART-ORDERING-FOREIGN',
            'name' => 'Foreign Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignSite = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $foreignStore,
            'slug' => 'smart-ordering-foreign-'.Str::lower(Str::random(8)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignBranch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $foreignSite,
            'code' => 'foreign',
            'name_ar' => 'Foreign Branch',
            'name_en' => 'Foreign Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'supports_pickup' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
        ]);

        $this->actingAs($admin)->put(route('admin.smart-ordering.branches.update', $foreignBranch->public_id), [
            'name' => 'Hacked Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => 1,
            'accepting_orders' => 1,
            'supports_pickup' => 1,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
        ])->assertNotFound();

        $this->assertDatabaseHas('smart_ordering_branches', [
            'id' => $foreignBranch->id,
            'name_en' => 'Foreign Branch',
        ]);
    }

    public function test_notification_failure_never_rolls_back_valid_order(): void
    {
        $this->app->bind(OrderNotificationBridge::class, function () {
            return new class implements OrderNotificationBridge
            {
                public function notify(Order $order, SmartOrderingOrderContext $context): void
                {
                    throw new \RuntimeException('Simulated downstream WhatsApp failure.');
                }
            };
        });

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        $response = $this->checkout($cart, $token, 'smart-ordering-notify-fail-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();

        $this->assertDatabaseHas('smart_ordering_order_contexts', [
            'public_reference' => $response->json('data.reference'),
            'notification_status' => 'failed',
        ]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_public_tracking_exposes_safe_reference_and_timeline_without_customer_pii(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        $checkout = $this->checkout($cart, $token, 'smart-ordering-track-0001', [
            'fulfillment_mode' => 'pickup',
            'name' => 'Tracking Secret Name',
            'phone' => '+96555555555',
        ])->assertCreated();

        $reference = (string) $checkout->json('data.reference');
        $tracking = $this->getJson('/api/smart-ordering/orders/'.$reference)
            ->assertOk()
            ->assertJsonPath('data.reference', $reference)
            ->assertJsonPath('data.status', 'NEW')
            ->assertJsonPath('data.fulfillment_mode', 'pickup');

        $body = $tracking->getContent();
        $this->assertStringNotContainsString('Tracking Secret Name', $body);
        $this->assertStringNotContainsString('+96555555555', $body);
        $this->assertStringNotContainsString('"customer_id"', $body);
    }

    public function test_merchandising_precedence_and_cart_recalculation_are_server_authoritative(): void
    {
        $winner = $this->offer([
            'name' => 'Priority winner',
            'type' => 'fixed',
            'priority' => 10,
            'stack_group' => 'basket',
            'exclusive' => true,
            'minimum_basket' => 2.000,
            'placements' => ['cart_upsell'],
            'effect' => ['value' => 1.000],
        ]);
        $this->offer([
            'name' => 'Lower precedence',
            'type' => 'fixed',
            'priority' => 20,
            'stack_group' => 'basket',
            'exclusive' => true,
            'minimum_basket' => 2.000,
            'effect' => ['value' => 2.000],
        ]);
        $this->offer([
            'name' => 'Future offer',
            'type' => 'fixed',
            'priority' => 1,
            'stack_group' => 'basket',
            'exclusive' => true,
            'effect' => ['value' => 9.000],
            'starts_at' => now()->addDay(),
        ]);

        [$cart, $token] = $this->cart();
        $added = $this->withHeader('X-Smart-Cart-Token', $token)
            ->postJson('/api/smart-ordering/carts/'.$cart.'/items', [
                'product_id' => $this->productId,
                'quantity' => 2,
            ])->assertCreated();

        $this->assertSame(1.0, (float) $added->json('data.quote.discount_total'));
        $this->assertSame($winner, $added->json('data.quote.merchandising.applied_offers.0.id'));

        $item = (string) $added->json('data.item.public_id');
        $lowered = $this->withHeader('X-Smart-Cart-Token', $token)
            ->patchJson('/api/smart-ordering/carts/'.$cart.'/items/'.$item, ['quantity' => 1])
            ->assertOk();
        $this->assertSame(0.0, (float) $lowered->json('data.quote.discount_total'));

        $restored = $this->withHeader('X-Smart-Cart-Token', $token)
            ->patchJson('/api/smart-ordering/carts/'.$cart.'/items/'.$item, ['quantity' => 2])
            ->assertOk();
        $this->assertSame(1.0, (float) $restored->json('data.quote.discount_total'));
    }

    public function test_offer_usage_limit_is_consumed_once_and_second_checkout_reprices_safely(): void
    {
        $offer = $this->offer([
            'name' => 'One redemption only',
            'type' => 'fixed',
            'effect' => ['value' => 1.000],
            'usage_limit_total' => 1,
        ]);

        [$firstCart, $firstToken] = $this->cart();
        $this->addItem($firstCart, $firstToken, 1);
        $first = $this->checkout($firstCart, $firstToken, 'smart-offer-limit-first-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();
        $this->assertSame(1.0, (float) $first->json('data.discount_total'));
        $this->assertSame(0.25, (float) $first->json('data.grand_total'));

        [$secondCart, $secondToken] = $this->cart();
        $this->addItem($secondCart, $secondToken, 1);
        $second = $this->checkout($secondCart, $secondToken, 'smart-offer-limit-second-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();
        $this->assertSame(0.0, (float) $second->json('data.discount_total'));
        $this->assertSame(1.25, (float) $second->json('data.grand_total'));

        $this->assertDatabaseHas('smart_ordering_offer_rules', [
            'public_id' => $offer,
            'used_count' => 1,
        ]);
        $this->assertDatabaseCount('smart_ordering_offer_redemptions', 1);
    }

    public function test_forged_qr_campaign_context_is_rejected(): void
    {
        $this->postJson('/api/smart-ordering/carts', [
            'branch_public_id' => (string) $this->branch->public_id,
            'attribution_context' => 'v1.Zm9yZ2Vk.deadbeef',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('smart_ordering_carts', 0);
    }

    public function test_sold_out_recommendation_is_suppressed_and_add_conversion_is_idempotent(): void
    {
        $menuItem = $this->menuItem($this->extraProductId, true);
        $recommendation = (string) Str::uuid();
        DB::table('smart_ordering_recommendation_rules')->insert([
            'public_id' => $recommendation,
            'site_id' => $this->branch->site_id,
            'branch_id' => $this->branch->id,
            'name' => 'Add a side',
            'placement' => 'cart_upsell',
            'trigger_product_id' => $this->productId,
            'recommended_product_id' => $this->extraProductId,
            'priority' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        $this->withHeader('X-Smart-Cart-Token', $token)
            ->getJson('/api/smart-ordering/carts/'.$cart.'/merchandising?placement=cart_upsell&trigger_product_id='.$this->productId)
            ->assertOk()
            ->assertJsonCount(0, 'data.recommendations');

        DB::table('smart_ordering_menu_items')->where('public_id', $menuItem)->update([
            'sold_out' => false,
            'updated_at' => now(),
        ]);

        $this->withHeader('X-Smart-Cart-Token', $token)
            ->getJson('/api/smart-ordering/carts/'.$cart.'/merchandising?placement=cart_upsell&trigger_product_id='.$this->productId)
            ->assertOk()
            ->assertJsonPath('data.recommendations.0.id', $recommendation)
            ->assertJsonPath('data.recommendations.0.product.product_id', $this->extraProductId);

        $this->withHeader('X-Smart-Cart-Token', $token)
            ->postJson('/api/smart-ordering/carts/'.$cart.'/items', [
                'product_id' => $this->extraProductId,
                'quantity' => 1,
            ])->assertCreated();

        foreach ([1, 2] as $attempt) {
            $this->withHeaders([
                'X-Smart-Cart-Token' => $token,
                'Idempotency-Key' => 'reco-add-event-0001',
            ])->postJson('/api/smart-ordering/carts/'.$cart.'/merchandising/events', [
                'event_type' => 'add',
                'placement' => 'cart_upsell',
                'recommendation_id' => $recommendation,
            ])->assertAccepted();
        }

        $this->assertSame(1, DB::table('smart_ordering_merchandising_events')
            ->where('event_type', 'add')
            ->where('recommendation_rule_id', DB::table('smart_ordering_recommendation_rules')->where('public_id', $recommendation)->value('id'))
            ->count());

        $checkout = $this->checkout($cart, $token, 'smart-reco-conversion-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();

        $conversion = DB::table('smart_ordering_merchandising_events')
            ->where('event_type', 'conversion')
            ->whereNotNull('recommendation_rule_id')
            ->first();
        $this->assertNotNull($conversion);
        $this->assertSame((float) $checkout->json('data.grand_total'), (float) $conversion->revenue);
    }

    public function test_coupon_wrapper_enforces_customer_eligibility_and_redeems_once(): void
    {
        $user = User::query()->create([
            'name' => 'Coupon Customer',
            'email' => 'coupon-customer-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $customer = app(B2cCustomerService::class)->create($this->storeId, [
            'name' => 'Coupon Customer',
            'phone' => '+96551111111',
            'email' => $user->email,
        ], $user);

        $couponId = (int) DB::table('marketing_coupons')->insertGetId([
            'scope_key' => 'b2c:'.$this->storeId,
            'channel' => 'b2c',
            'store_id' => $this->storeId,
            'code' => 'SMART25',
            'name_ar' => 'خصم سمارت',
            'name_en' => 'Smart discount',
            'discount_type' => 'fixed',
            'discount_value' => 0.250,
            'usage_limit_total' => 5,
            'usage_limit_per_user' => 2,
            'used_count' => 0,
            'first_order_only' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wrapper = $this->offer([
            'name' => 'Smart coupon eligibility',
            'type' => 'coupon',
            'marketing_coupon_id' => $couponId,
            'stack_group' => 'coupon',
            'usage_limit_total' => 1,
            'conditions' => [
                'customer_ids' => [(int) $customer->legacy_customer_id],
                'branch_public_ids' => [(string) $this->branch->public_id],
            ],
        ]);

        $this->actingAs($user);
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        $first = $this->checkout($cart, $token, 'smart-coupon-redemption-0001', [
            'fulfillment_mode' => 'pickup',
            'coupon_code' => 'SMART25',
            'name' => 'Coupon Customer',
            'phone' => '+96551111111',
            'email' => $user->email,
        ])->assertCreated();

        $this->assertSame(0.25, (float) $first->json('data.discount_total'));
        $this->assertSame(1.0, (float) $first->json('data.grand_total'));

        $this->checkout($cart, $token, 'smart-coupon-redemption-0001', [
            'fulfillment_mode' => 'pickup',
            'coupon_code' => 'SMART25',
            'name' => 'Coupon Customer',
            'phone' => '+96551111111',
            'email' => $user->email,
        ])->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame(1, DB::table('marketing_coupon_redemptions')->where('coupon_id', $couponId)->count());
        $this->assertSame(1, (int) DB::table('marketing_coupons')->where('id', $couponId)->value('used_count'));
        $this->assertDatabaseHas('smart_ordering_offer_rules', ['public_id' => $wrapper, 'used_count' => 1]);
        $this->assertDatabaseCount('smart_ordering_offer_redemptions', 1);
    }

    private function product(
        int $catalogId,
        int $categoryId,
        int $unitId,
        string $sku,
        string $name,
        float $price,
    ): int {
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $this->storeId,
            'product_id' => $productId,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    /** @param array<string,mixed> $overrides */
    private function offer(array $overrides = []): string
    {
        $publicId = (string) Str::uuid();
        $defaults = [
            'branch_id' => $this->branch->id,
            'promotion_id' => null,
            'marketing_coupon_id' => null,
            'name' => 'Test offer',
            'type' => 'fixed',
            'priority' => 100,
            'stack_group' => null,
            'exclusive' => true,
            'minimum_basket' => null,
            'usage_limit_total' => null,
            'used_count' => 0,
            'placements' => [],
            'conditions' => [],
            'effect' => ['value' => 0.100],
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
        $data = [...$defaults, ...$overrides];

        foreach (['placements', 'conditions', 'effect'] as $json) {
            $data[$json] = $data[$json] === []
                ? null
                : json_encode($data[$json], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        DB::table('smart_ordering_offer_rules')->insert([
            'public_id' => $publicId,
            'site_id' => $this->branch->site_id,
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function menuItem(int $productId, bool $soldOut): string
    {
        $revisionId = (int) DB::table('smart_ordering_revisions')
            ->where('site_id', $this->branch->site_id)
            ->where('public_id', $this->publishedRevisionPublicId)
            ->value('id');

        $menuId = (int) DB::table('smart_ordering_menus')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $this->branch->site_id,
            'public_id' => (string) Str::uuid(),
            'name_en' => 'Recommendation menu',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('smart_ordering_menu_categories')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $this->branch->site_id,
            'menu_id' => $menuId,
            'public_id' => (string) Str::uuid(),
            'name_en' => 'Recommendation category',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $publicId = (string) Str::uuid();
        DB::table('smart_ordering_menu_items')->insert([
            'revision_id' => $revisionId,
            'site_id' => $this->branch->site_id,
            'category_id' => $categoryId,
            'public_id' => $publicId,
            'source_product_id' => $productId,
            'name_en' => 'Recommended extra',
            'is_visible' => true,
            'sold_out' => $soldOut,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    /** @return array{0:string,1:string} */
    private function cart(): array
    {
        $response = $this->postJson('/api/smart-ordering/carts', [
            'branch_public_id' => (string) $this->branch->public_id,
        ])->assertCreated();

        return [(string) $response->json('cart.public_id'), (string) $response->json('cart.token')];
    }

    /** @param array<string,mixed> $selection */
    private function addItem(string $cart, string $token, float $quantity, array $selection = []): void
    {
        $this->withHeader('X-Smart-Cart-Token', $token)
            ->postJson('/api/smart-ordering/carts/'.$cart.'/items', [
                'product_id' => $this->productId,
                'quantity' => $quantity,
                'selection' => $selection,
            ])
            ->assertCreated();
    }

    /** @param array<string,mixed> $overrides */
    private function checkout(string $cart, string $token, string $key, array $overrides)
    {
        return $this->withHeaders([
            'X-Smart-Cart-Token' => $token,
            'Idempotency-Key' => $key,
        ])->postJson('/api/smart-ordering/carts/'.$cart.'/checkout', [
            'fulfillment_mode' => 'pickup',
            'name' => 'Smart Guest',
            'phone' => '+96550000000',
            'email' => 'smart-guest@example.test',
            ...$overrides,
        ]);
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Smart Ordering Admin',
            'email' => 'smart-admin-'.Str::lower(Str::random(8)).'@example.test',
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
}
