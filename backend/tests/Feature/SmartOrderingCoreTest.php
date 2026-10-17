<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\BranchPolicyService;
use App\Domain\SmartOrdering\Contracts\OrderNotificationBridge;
use App\Domain\SmartOrdering\Contracts\RestaurantPaymentProvider;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantPaymentProviderResult;
use App\Domain\SmartOrdering\RestaurantPaymentWebhookResult;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\SmartOrderingOrderContext;
use App\Models\User;
use App\Services\B2cCustomerService;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        $this->enableRestaurantSaasForTestStore($this->storeId);

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

    public function test_branch_timezone_is_selected_from_supported_lookup_and_invalid_values_fail_closed(): void
    {
        $admin = $this->storeAdmin($this->storeId);

        app()->setLocale('en');
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'branches']))
            ->assertOk()
            ->assertSee('data-timezone-search', false)
            ->assertSee('Asia/Kuwait')
            ->assertSee('UTC+03:00')
            ->assertSee('Changing it affects future schedule interpretation.');

        $payload = [
            'name' => 'Kuwait City Branch',
            'timezone' => 'Mars/Olympus',
            'is_active' => 1,
            'accepting_orders' => 1,
            'supports_dine_in' => 1,
            'supports_pickup' => 1,
            'supports_delivery' => 1,
            'allow_scheduled' => 1,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
        ];

        $this->actingAs($admin)
            ->put(route('admin.smart-ordering.branches.update', $this->branch->public_id), $payload)
            ->assertSessionHasErrors('timezone');

        $this->assertSame('Asia/Kuwait', $this->branch->fresh()->timezone);

        $payload['timezone'] = 'Europe/London';
        $this->actingAs($admin)
            ->put(route('admin.smart-ordering.branches.update', $this->branch->public_id), $payload)
            ->assertRedirect();

        $this->assertSame('Europe/London', $this->branch->fresh()->timezone);

        $admin->locale = 'ar';
        $admin->save();
        app()->setLocale('ar');
        $this->actingAs($admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'branches']))
            ->assertOk()
            ->assertSee('ابحث بالمدينة أو المنطقة أو فرق التوقيت أو اسم IANA')
            ->assertSee('Europe/London');
    }

    public function test_branch_delivery_zone_editor_is_map_first_and_geometry_remains_server_authoritative(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        $zonePublicId = (string) Str::uuid();

        DB::table('smart_ordering_delivery_zones')->insert([
            'public_id' => $zonePublicId,
            'branch_id' => $this->branch->id,
            'name' => 'Central Coverage',
            'center_latitude' => 29.3759000,
            'center_longitude' => 47.9774000,
            'radius_km' => 5,
            'delivery_fee' => 0.750,
            'minimum_order' => 2.000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->setLocale('en');
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'branches']))
            ->assertOk()
            ->assertSee('/assets/leaflet/1.9.4/leaflet.css', false)
            ->assertSee('/assets/leaflet/1.9.4/leaflet.js', false)
            ->assertSee('/assets/admin/smart-ordering-zone-map.js', false)
            ->assertSee('data-zone-map-editor', false)
            ->assertSee('data-current-zone="'.$zonePublicId.'"', false)
            ->assertSee('data-zone-location-search', false)
            ->assertSee('data-zone-map-canvas tabindex="0" role="application"', false)
            ->assertSee('Advanced coordinates')
            ->assertSee('Keyboard: arrow keys move the center');

        $this->actingAs($admin)
            ->put(route('admin.smart-ordering.zones.update', [$this->branch->public_id, $zonePublicId]), [
                'name' => 'Central Coverage',
                'center_latitude' => 91,
                'center_longitude' => 47.9774,
                'radius_km' => 5,
                'delivery_fee' => 0.750,
                'minimum_order' => 2.000,
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('center_latitude');

        $this->assertDatabaseHas('smart_ordering_delivery_zones', [
            'public_id' => $zonePublicId,
            'center_latitude' => 29.3759000,
            'center_longitude' => 47.9774000,
        ]);

        $admin->locale = 'ar';
        $admin->save();
        app()->setLocale('ar');
        $this->actingAs($admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'branches']))
            ->assertOk()
            ->assertSee('خريطة نطاق التغطية')
            ->assertSee('لوحة المفاتيح: استخدم الأسهم لتحريك المركز');
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
        $this->enableRestaurantSaasForTestStore($foreignStore);

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

    public function test_public_menu_cart_bridge_resolves_public_item_and_modifier_ids_server_side(): void
    {
        $menuItemPublicId = $this->menuItem($this->productId, false);
        $itemId = (int) DB::table('smart_ordering_menu_items')
            ->where('public_id', $menuItemPublicId)
            ->value('id');
        $revisionId = (int) DB::table('smart_ordering_revisions')
            ->where('site_id', $this->branch->site_id)
            ->where('public_id', $this->publishedRevisionPublicId)
            ->value('id');

        $groupPublicId = (string) Str::uuid();
        $groupId = (int) DB::table('smart_ordering_modifier_groups')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $this->branch->site_id,
            'item_id' => $itemId,
            'public_id' => $groupPublicId,
            'name_en' => 'Add-on',
            'kind' => 'modifier',
            'selection_type' => 'single',
            'is_required' => true,
            'min_selections' => 1,
            'max_selections' => 1,
            'is_visible' => true,
            'sort_order' => 0,
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
            'is_default' => false,
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$cart, $token] = $this->cart();

        $this->withHeader('X-Smart-Cart-Token', $token)
            ->postJson('/api/smart-ordering/carts/'.$cart.'/menu-items/'.$menuItemPublicId, [
                'quantity' => 1,
                'selections' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('selections.'.$groupPublicId);

        $response = $this->withHeader('X-Smart-Cart-Token', $token)
            ->postJson('/api/smart-ordering/carts/'.$cart.'/menu-items/'.$menuItemPublicId, [
                'quantity' => 1,
                'selections' => [[
                    'group_id' => $groupPublicId,
                    'option_ids' => [$optionPublicId],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.item.menu_item_public_id', $menuItemPublicId);

        $this->assertSame(1.75, (float) $response->json('data.quote.subtotal'));
        $this->assertDatabaseHas('smart_ordering_cart_items', [
            'cart_id' => DB::table('smart_ordering_carts')->where('public_id', $cart)->value('id'),
            'product_id' => $this->productId,
            'menu_item_public_id' => $menuItemPublicId,
        ]);

        $selection = json_decode((string) DB::table('smart_ordering_cart_items')
            ->where('menu_item_public_id', $menuItemPublicId)
            ->value('selection'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($groupPublicId, $selection['menu_selections'][0]['group_id']);
        $this->assertSame($optionPublicId, $selection['menu_selections'][0]['option_ids'][0]);
        $this->assertSame($this->extraProductId, $selection['modifiers'][0]['product_id']);
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

    public function test_checkout_capacity_throttles_overlapping_active_orders_and_persists_promise_time(): void
    {
        $this->branch->update([
            'max_active_orders' => 1,
            'capacity_window_minutes' => 60,
            'pickup_promise_minutes' => 25,
        ]);

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        $first = $this->checkout($cart, $token, 'smart-capacity-first-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();

        $scheduled = CarbonImmutable::parse((string) $first->json('data.scheduled_for'));
        $promised = CarbonImmutable::parse((string) $first->json('data.promised_for'));
        $this->assertSame(25.0, $scheduled->diffInMinutes($promised));

        [$blockedCart, $blockedToken] = $this->cart();
        $this->addItem($blockedCart, $blockedToken, 1);
        $this->checkout($blockedCart, $blockedToken, 'smart-capacity-blocked-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertStatus(409);

        DB::table('smart_ordering_order_contexts')
            ->where('public_reference', (string) $first->json('data.reference'))
            ->update(['lifecycle_status' => 'COMPLETED', 'updated_at' => now()]);

        [$releasedCart, $releasedToken] = $this->cart();
        $this->addItem($releasedCart, $releasedToken, 1);
        $this->checkout($releasedCart, $releasedToken, 'smart-capacity-released-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();
    }

    public function test_capacity_is_branch_scoped_and_zero_remains_unlimited(): void
    {
        $this->branch->update([
            'max_active_orders' => 1,
            'capacity_window_minutes' => 60,
        ]);

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);
        $this->checkout($cart, $token, 'smart-capacity-primary-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();

        $other = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $this->branch->site_id,
            'code' => 'kw-secondary',
            'name_ar' => 'فرع ثانوي',
            'name_en' => 'Secondary Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'max_active_orders' => 0,
            'capacity_window_minutes' => 60,
            'pickup_promise_minutes' => 10,
            'service_charge' => 0,
        ]);

        $cartResponse = $this->postJson('/api/smart-ordering/carts', [
            'branch_public_id' => (string) $other->public_id,
        ])->assertCreated();
        $otherCart = (string) $cartResponse->json('cart.public_id');
        $otherToken = (string) $cartResponse->json('cart.token');

        $this->withHeader('X-Smart-Cart-Token', $otherToken)
            ->postJson('/api/smart-ordering/carts/'.$otherCart.'/items', [
                'product_id' => $this->productId,
                'quantity' => 1,
            ])
            ->assertCreated();

        $this->checkout($otherCart, $otherToken, 'smart-capacity-secondary-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();
    }

    public function test_branch_editor_exposes_localized_capacity_and_promise_policy(): void
    {
        $admin = $this->storeAdmin($this->storeId);

        app()->setLocale('en');
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'branches']))
            ->assertOk()
            ->assertSee('Active-order capacity')
            ->assertSee('Capacity window (minutes)')
            ->assertSee('Pickup promise (minutes)');

        $payload = [
            'name' => 'Kuwait City Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => 1,
            'accepting_orders' => 1,
            'supports_dine_in' => 1,
            'supports_pickup' => 1,
            'supports_delivery' => 1,
            'allow_scheduled' => 1,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'max_active_orders' => 12,
            'capacity_window_minutes' => 20,
            'dine_in_promise_minutes' => 15,
            'pickup_promise_minutes' => 25,
            'delivery_promise_minutes' => 50,
            'service_charge' => 0,
        ];

        $this->actingAs($admin)
            ->put(route('admin.smart-ordering.branches.update', $this->branch->public_id), $payload)
            ->assertRedirect();

        $this->assertSame(12, $this->branch->fresh()->max_active_orders);
        $this->assertSame(25, $this->branch->fresh()->pickup_promise_minutes);

        $admin->forceFill(['locale' => 'ar'])->save();
        app()->setLocale('ar');
        $this->actingAs($admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'branches']))
            ->assertOk()
            ->assertSee('سعة الطلبات النشطة')
            ->assertSee('زمن وعد الاستلام بالدقائق');
    }

    public function test_restaurant_payment_operations_are_idempotent_and_refund_status_is_not_double_counted(): void
    {
        SmartOrderingTestPaymentProvider::reset();
        config(['restaurant_payments.providers.testpay' => SmartOrderingTestPaymentProvider::class]);

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);
        $this->checkout($cart, $token, 'smart-ordering-payment-lifecycle-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $payment->forceFill([
            'provider' => 'testpay',
            'provider_reference' => 'provider-'.$payment->getKey(),
        ])->save();

        $admin = $this->storeAdmin($this->storeId);
        $amount = number_format((float) $payment->amount, 3, '.', '');

        $this->actingAs($admin)->post(
            route('admin.smart-ordering.payments.operation', [$payment->getKey(), 'authorize']),
            ['idempotency_key' => 'authorize-restaurant-payment-0001', 'amount' => $amount],
        )->assertRedirect();

        $this->actingAs($admin)->post(
            route('admin.smart-ordering.payments.operation', [$payment->getKey(), 'authorize']),
            ['idempotency_key' => 'authorize-restaurant-payment-0001', 'amount' => $amount],
        )->assertRedirect();

        $this->assertSame(1, collect(SmartOrderingTestPaymentProvider::$calls)
            ->where('operation', 'authorize')
            ->count());
        $this->assertDatabaseCount('restaurant_payment_operations', 1);

        $this->actingAs($admin)->post(
            route('admin.smart-ordering.payments.operation', [$payment->getKey(), 'authorize']),
            ['idempotency_key' => 'authorize-restaurant-payment-0001', 'amount' => '0.500'],
        )->assertSessionHasErrors('idempotency_key');

        $this->actingAs($admin)->post(
            route('admin.smart-ordering.payments.operation', [$payment->getKey(), 'capture']),
            ['idempotency_key' => 'capture-restaurant-payment-0001', 'amount' => $amount],
        )->assertRedirect();

        $this->assertSame('captured', (string) $payment->fresh()->status);

        $this->actingAs($admin)->post(
            route('admin.smart-ordering.payments.operation', [$payment->getKey(), 'refund']),
            ['idempotency_key' => 'refund-restaurant-payment-0001', 'amount' => '0.500'],
        )->assertRedirect();

        $this->assertSame('partially_refunded', (string) $payment->fresh()->status);

        $remaining = number_format(max(0, (float) $payment->amount - 0.500), 3, '.', '');
        $this->actingAs($admin)->post(
            route('admin.smart-ordering.payments.operation', [$payment->getKey(), 'refund']),
            ['idempotency_key' => 'refund-restaurant-payment-0002', 'amount' => $remaining],
        )->assertRedirect();

        $this->assertSame('refunded', (string) $payment->fresh()->status);
        $this->assertSame(2, DB::table('restaurant_payment_operations')
            ->where('payment_id', $payment->getKey())
            ->where('operation', 'refund')
            ->where('status', 'succeeded')
            ->count());
    }

    public function test_restaurant_payment_webhook_rejects_invalid_signature_and_deduplicates_events(): void
    {
        SmartOrderingTestPaymentProvider::reset();
        config(['restaurant_payments.providers.testpay' => SmartOrderingTestPaymentProvider::class]);

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);
        $this->checkout($cart, $token, 'smart-ordering-payment-webhook-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $payment->forceFill([
            'provider' => 'testpay',
            'provider_reference' => 'webhook-provider-'.$payment->getKey(),
            'status' => 'captured',
        ])->save();

        $payload = [
            'event_id' => 'evt-restaurant-payment-0001',
            'provider_reference' => (string) $payment->provider_reference,
            'operation' => 'refund',
            'payment_status' => 'partially_refunded',
            'amount' => '0.250',
        ];

        $this->withHeader('X-Test-Payment-Signature', 'invalid')
            ->postJson('/api/smart-ordering/payments/webhooks/testpay', $payload)
            ->assertUnauthorized();

        $this->assertDatabaseMissing('restaurant_payment_operations', [
            'provider_event_id' => 'evt-restaurant-payment-0001',
        ]);

        $this->withHeader('X-Test-Payment-Signature', 'valid')
            ->postJson('/api/smart-ordering/payments/webhooks/testpay', $payload)
            ->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('payment.status', 'partially_refunded');

        $this->withHeader('X-Test-Payment-Signature', 'valid')
            ->postJson('/api/smart-ordering/payments/webhooks/testpay', $payload)
            ->assertOk();

        $this->assertSame(1, DB::table('restaurant_payment_operations')
            ->where('provider', 'testpay')
            ->where('provider_event_id', 'evt-restaurant-payment-0001')
            ->count());
        $this->assertSame('partially_refunded', (string) $payment->fresh()->status);
    }

    public function test_restaurant_payments_workspace_is_localized_and_unconfigured_provider_fails_closed(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);
        $this->checkout($cart, $token, 'smart-ordering-payment-workspace-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $admin = $this->storeAdmin($this->storeId);
        $amount = number_format((float) $payment->amount, 3, '.', '');

        $this->actingAs($admin)->post(
            route('admin.smart-ordering.payments.operation', [$payment->getKey(), 'authorize']),
            ['idempotency_key' => 'missing-provider-0001', 'amount' => $amount],
        )->assertSessionHasErrors('provider');

        $this->assertDatabaseCount('restaurant_payment_operations', 0);

        app()->setLocale('en');
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'payments']))
            ->assertOk()
            ->assertSee('Payments &amp; reconciliation', false)
            ->assertSee('Cash on delivery')
            ->assertSee('Provider not configured');

        $admin->forceFill(['locale' => 'ar'])->save();
        app()->setLocale('ar');
        $this->actingAs($admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'payments']))
            ->assertOk()
            ->assertSee('المدفوعات والتسوية')
            ->assertSee('الدفع عند الاستلام')
            ->assertSee('مزود الدفع غير مهيأ');
    }

    /** @param array<string,mixed> $overrides */
    public function test_restaurant_table_workspace_uses_canonical_occupancy_and_rejects_cross_scope_mutations(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        $floorPublicId = (string) Str::uuid();
        $floorId = (int) DB::table('smart_ordering_floor_areas')->insertGetId([
            'public_id' => $floorPublicId,
            'branch_id' => $this->branch->id,
            'name_en' => 'Main Dining Room',
            'name_ar' => 'الصالة الرئيسية',
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $table = $this->restaurantTable('T-21', 'table-21-token', $floorId);

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);
        $this->checkout($cart, $token, 'smart-ordering-floor-table-0001', [
            'fulfillment_mode' => 'dine_in',
            'table_public_id' => $table->public_id,
            'table_token' => 'table-21-token',
        ])->assertCreated();

        app()->setLocale('en');
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'tables',
                'branch' => $this->branch->public_id,
            ]))
            ->assertOk()
            ->assertSee('Tables &amp; floor plan', false)
            ->assertSee('Main Dining Room')
            ->assertSee('T-21')
            ->assertSee('Occupied')
            ->assertSee('data-so-floor-canvas', false)
            ->assertSee('/assets/admin/smart-ordering-tables.js', false)
            ->assertDontSee('table_id', false);

        $this->actingAs($admin)
            ->put(route('admin.smart-ordering.tables.update', [$this->branch->public_id, $table->public_id]), [
                'floor_public_id' => $floorPublicId,
                'label' => 'T-21',
                'capacity' => 4,
                'position_x' => 140,
                'position_y' => 180,
                'visual_width' => 180,
                'visual_height' => 120,
                'shape' => 'rectangle',
                'is_active' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('smart_ordering_tables', [
            'id' => $table->id,
            'is_active' => false,
            'capacity' => 4,
        ]);

        [$inactiveCart, $inactiveToken] = $this->cart();
        $this->addItem($inactiveCart, $inactiveToken, 1);
        $this->checkout($inactiveCart, $inactiveToken, 'smart-ordering-floor-table-inactive-0001', [
            'fulfillment_mode' => 'dine_in',
            'table_public_id' => $table->public_id,
            'table_token' => 'table-21-token',
        ])->assertNotFound();

        $siblingBranch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $this->branch->site_id,
            'code' => 'kw-city-sibling',
            'name_ar' => 'فرع داخلي ثان',
            'name_en' => 'Sibling Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => false,
            'allow_scheduled' => false,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
        ]);
        $siblingTable = $this->restaurantTable('S-01', 'sibling-token', null, $siblingBranch);

        $this->actingAs($admin)
            ->put(route('admin.smart-ordering.tables.update', [$this->branch->public_id, $siblingTable->public_id]), [
                'floor_public_id' => null,
                'label' => 'S-01',
                'capacity' => 2,
                'position_x' => 60,
                'position_y' => 60,
                'visual_width' => 160,
                'visual_height' => 120,
                'shape' => 'rectangle',
                'is_active' => 1,
            ])
            ->assertNotFound();

        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $foreignStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'SMART-ORDERING-TABLE-FOREIGN',
            'name' => 'Foreign Table Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($foreignStoreId);

        $foreignSiteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $foreignStoreId,
            'slug' => 'foreign-table-'.Str::lower(Str::random(8)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignBranch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $foreignSiteId,
            'code' => 'foreign-table-branch',
            'name_ar' => 'فرع خارجي',
            'name_en' => 'Foreign Table Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.tables.store', $foreignBranch->public_id), [
                'floor_public_id' => null,
                'label' => 'F-01',
                'capacity' => 2,
                'position_x' => 60,
                'position_y' => 60,
                'visual_width' => 160,
                'visual_height' => 120,
                'shape' => 'rectangle',
                'is_active' => 1,
            ])
            ->assertNotFound();
    }

    public function test_table_combine_split_is_auditable_without_rewriting_order_identity(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        $primary = $this->restaurantTable('C-01', 'combine-primary-token');
        $member = $this->restaurantTable('C-02', 'combine-member-token');

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);
        $checkout = $this->checkout($cart, $token, 'smart-ordering-combine-0001', [
            'fulfillment_mode' => 'dine_in',
            'table_public_id' => $primary->public_id,
            'table_token' => 'combine-primary-token',
        ])->assertCreated();
        $reference = (string) $checkout->json('data.reference');
        $context = SmartOrderingOrderContext::query()->where('public_reference', $reference)->firstOrFail();
        $this->assertSame((int) $primary->id, (int) $context->table_id);

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.tables.combine', [$this->branch->public_id, $primary->public_id]), [
                'other_table_public_id' => $member->public_id,
                'note' => 'Family seating',
            ])
            ->assertRedirect();

        $combination = DB::table('smart_ordering_table_combinations')
            ->where('primary_table_id', $primary->id)
            ->where('member_table_id', $member->id)
            ->whereNull('split_at')
            ->first();
        $this->assertNotNull($combination);
        $this->assertSame($admin->id, (int) $combination->combined_by);
        $this->assertSame((int) $primary->id, (int) $context->fresh()->table_id);

        $this->actingAs($admin)
            ->delete(route('admin.smart-ordering.tables.split', [$this->branch->public_id, $combination->public_id]))
            ->assertRedirect();

        $split = DB::table('smart_ordering_table_combinations')->where('id', $combination->id)->first();
        $this->assertNotNull($split->split_at);
        $this->assertSame($admin->id, (int) $split->split_by);
        $this->assertSame((int) $primary->id, (int) $context->fresh()->table_id);
    }

    public function test_table_qr_binding_reuses_canonical_dynamic_qr_and_trusted_reference(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        $table = $this->restaurantTable('QR-01', 'legacy-table-token');

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.tables.qr.bind', [$this->branch->public_id, $table->public_id]))
            ->assertRedirect();

        $bound = DB::table('smart_ordering_tables')->where('id', $table->id)->first();
        $this->assertNotNull($bound->qr_code_id);
        $this->assertNotNull($bound->qr_reference);

        $qr = DB::table('smart_ordering_qr_codes')->where('id', $bound->qr_code_id)->first();
        $this->assertNotNull($qr);
        $this->assertSame('table', $qr->type);
        $this->assertSame($this->branch->code, $qr->branch_reference);
        $this->assertSame($bound->qr_reference, $qr->table_reference);
        $this->assertTrue((bool) $qr->is_enabled);

        $resolved = app(BranchPolicyService::class)
            ->trustedTableReference($this->branch, (string) $bound->qr_reference);
        $this->assertSame((int) $table->id, (int) $resolved->id);

        $this->app['auth']->guard()->logout();

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);
        $this->checkout($cart, $token, 'smart-ordering-legacy-table-token-0001', [
            'fulfillment_mode' => 'dine_in',
            'table_public_id' => $table->public_id,
            'table_token' => 'legacy-table-token',
        ])->assertCreated();

        $this->actingAs($admin)
            ->delete(route('admin.smart-ordering.tables.qr.unbind', [$this->branch->public_id, $table->public_id]))
            ->assertRedirect();

        $this->assertDatabaseHas('smart_ordering_tables', [
            'id' => $table->id,
            'qr_code_id' => null,
        ]);
        $this->assertDatabaseHas('smart_ordering_qr_codes', [
            'id' => $qr->id,
            'is_enabled' => false,
        ]);
    }

    public function test_table_workspace_is_localized_for_arabic_and_english(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        $floorId = (int) DB::table('smart_ordering_floor_areas')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'name_en' => 'Family Room',
            'name_ar' => 'صالة العائلات',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->restaurantTable('L-01', 'localized-table-token', $floorId);

        app()->setLocale('en');
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'tables']))
            ->assertOk()
            ->assertSee('Tables &amp; floor plan', false)
            ->assertSee('Family Room')
            ->assertSee('Available');

        $admin->locale = 'ar';
        $admin->save();
        app()->setLocale('ar');
        $this->actingAs($admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'tables']))
            ->assertOk()
            ->assertSee('الطاولات ومخطط الصالة')
            ->assertSee('صالة العائلات')
            ->assertSee('متاحة');
    }

    public function test_restaurant_workforce_reuses_store_identity_rejects_overlap_and_cross_scope(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        $staff = $this->workforceUser($this->storeId, 'Host Team Member', true);

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.workforce.staff.store', $this->branch->public_id), [
                'user_id' => $staff->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('restaurant_staff_assignments', [
            'branch_id' => $this->branch->id,
            'user_id' => $staff->id,
            'is_active' => true,
        ]);

        app()->setLocale('en');
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'workforce',
                'branch' => $this->branch->public_id,
            ]))
            ->assertOk()
            ->assertSee('Restaurant workforce')
            ->assertSee('Host Team Member')
            ->assertSee('Retail Store Admin')
            ->assertDontSee('B2C_STORE_ADMIN');

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.workforce.shifts.store', $this->branch->public_id), [
                'user_id' => $staff->id,
                'starts_at' => '2026-10-18T10:00',
                'ends_at' => '2026-10-18T18:00',
                'notes' => 'Lunch and dinner coverage',
            ])
            ->assertRedirect();

        $shift = DB::table('restaurant_shifts')->where('user_id', $staff->id)->first();
        $this->assertNotNull($shift);
        $this->assertSame('2026-10-18 07:00', CarbonImmutable::parse((string) $shift->starts_at, 'UTC')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-18 15:00', CarbonImmutable::parse((string) $shift->ends_at, 'UTC')->format('Y-m-d H:i'));
        $this->assertSame('DRAFT', $shift->status);

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.workforce.shifts.store', $this->branch->public_id), [
                'user_id' => $staff->id,
                'starts_at' => '2026-10-18T12:00',
                'ends_at' => '2026-10-18T14:00',
            ])
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(1, DB::table('restaurant_shifts')->where('user_id', $staff->id)->count());

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.workforce.shifts.transition', [
                $this->branch->public_id,
                $shift->public_id,
            ]), ['action' => 'publish'])
            ->assertRedirect();

        $this->assertDatabaseHas('restaurant_shifts', [
            'id' => $shift->id,
            'status' => 'PUBLISHED',
        ]);

        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $foreignStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'WORKFORCE-FOREIGN',
            'name' => 'Foreign Workforce Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($foreignStoreId);

        $foreignSiteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $foreignStoreId,
            'slug' => 'foreign-workforce-'.Str::lower(Str::random(8)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignBranch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $foreignSiteId,
            'code' => 'foreign-workforce-branch',
            'name_ar' => 'فرع فريق خارجي',
            'name_en' => 'Foreign Workforce Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.workforce.staff.store', $foreignBranch->public_id), [
                'user_id' => $staff->id,
            ])
            ->assertNotFound();

        $admin->locale = 'ar';
        $admin->save();
        app()->setLocale('ar');
        $this->actingAs($admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'workforce',
                'branch' => $this->branch->public_id,
            ]))
            ->assertOk()
            ->assertSee('فريق عمل المطعم')
            ->assertSee('مسؤول متجر التجزئة')
            ->assertDontSee('B2C_STORE_ADMIN');
    }

    public function test_restaurant_time_clock_transitions_use_server_time_and_manager_correction_is_audited(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        $staff = $this->workforceUser($this->storeId, 'Clock Team Member');

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.workforce.staff.store', $this->branch->public_id), [
                'user_id' => $staff->id,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.workforce.staff.store', $this->branch->public_id), [
                'user_id' => $admin->id,
            ])
            ->assertRedirect();

        $this->actingAs($staff)
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'workforce',
                'branch' => $this->branch->public_id,
            ]))
            ->assertOk()
            ->assertSee('Clock Team Member')
            ->assertSee('data-workforce-user-id="'.$staff->id.'"', false)
            ->assertDontSee('data-workforce-user-id="'.$admin->id.'"', false);

        $this->actingAs($staff)
            ->post(route('admin.smart-ordering.workforce.clock', $this->branch->public_id), [
                'action' => 'clock_in',
            ])
            ->assertRedirect();

        $session = DB::table('restaurant_time_clock_sessions')->where('user_id', $staff->id)->first();
        $this->assertNotNull($session);
        $this->assertSame('OPEN', $session->status);
        $this->assertNotNull($session->clocked_in_at);

        $this->actingAs($staff)
            ->post(route('admin.smart-ordering.workforce.clock', $this->branch->public_id), [
                'action' => 'clock_in',
            ])
            ->assertSessionHasErrors('action');

        $this->actingAs($staff)
            ->post(route('admin.smart-ordering.workforce.clock', $this->branch->public_id), [
                'action' => 'break_start',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('restaurant_time_clock_sessions', [
            'id' => $session->id,
            'status' => 'ON_BREAK',
        ]);

        $this->actingAs($staff)
            ->post(route('admin.smart-ordering.workforce.clock', $this->branch->public_id), [
                'action' => 'clock_out',
            ])
            ->assertSessionHasErrors('action');

        $this->actingAs($staff)
            ->post(route('admin.smart-ordering.workforce.clock', $this->branch->public_id), [
                'action' => 'break_end',
            ])
            ->assertRedirect();

        $this->actingAs($staff)
            ->post(route('admin.smart-ordering.workforce.clock', $this->branch->public_id), [
                'action' => 'clock_out',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('restaurant_time_clock_sessions', [
            'id' => $session->id,
            'status' => 'CLOSED',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.smart-ordering.workforce.sessions.correct', [
                $this->branch->public_id,
                $session->public_id,
            ]), [
                'clocked_in_at' => '2026-10-18T10:00',
                'clocked_out_at' => '2026-10-18T18:00',
                'total_break_minutes' => 600,
                'reason' => 'Invalid correction must be rejected.',
            ])
            ->assertSessionHasErrors('total_break_minutes');

        $this->actingAs($admin)
            ->put(route('admin.smart-ordering.workforce.sessions.correct', [
                $this->branch->public_id,
                $session->public_id,
            ]), [
                'clocked_in_at' => '2026-10-18T10:00',
                'clocked_out_at' => '2026-10-18T18:00',
                'total_break_minutes' => 30,
                'reason' => 'Manager corrected approved paper timesheet.',
            ])
            ->assertRedirect();

        $corrected = DB::table('restaurant_time_clock_sessions')->where('id', $session->id)->first();
        $this->assertSame('2026-10-18 07:00', CarbonImmutable::parse((string) $corrected->clocked_in_at, 'UTC')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-18 15:00', CarbonImmutable::parse((string) $corrected->clocked_out_at, 'UTC')->format('Y-m-d H:i'));
        $this->assertSame(1800, (int) $corrected->total_break_seconds);
        $this->assertSame($admin->id, (int) $corrected->corrected_by_user_id);
        $this->assertSame('Manager corrected approved paper timesheet.', $corrected->correction_note);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'smart_ordering.workforce.time_clock.corrected',
        ]);
    }

    private function workforceUser(int $storeId, string $name, bool $manager = false): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => 'workforce-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        if ($manager) {
            $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        } else {
            $role = Role::query()->firstOrCreate(
                ['code' => 'TEST_RESTAURANT_STAFF'],
                [
                    'name' => 'Test Restaurant Staff',
                    'scope' => 'store',
                    'is_system' => false,
                    'is_active' => true,
                ],
            );
            $ordersView = (int) DB::table('permissions')->where('code', 'orders.view')->value('id');
            $role->permissions()->syncWithoutDetaching([$ordersView]);
        }

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function restaurantTable(
        string $label,
        string $token,
        ?int $floorId = null,
        ?SmartOrderingBranch $branch = null,
        bool $active = true,
    ): object {
        $branch ??= $this->branch;
        $id = (int) DB::table('smart_ordering_tables')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'floor_area_id' => $floorId,
            'label' => $label,
            'capacity' => 2,
            'position_x' => 60,
            'position_y' => 60,
            'visual_width' => 160,
            'visual_height' => 120,
            'shape' => 'rectangle',
            'qr_reference' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $token),
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('smart_ordering_tables')->where('id', $id)->firstOrFail();
    }

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

    public function test_kitchen_board_routes_canonical_orders_and_ticket_actions_are_idempotent(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        foreach (['kitchen_display', 'kitchen_routing'] as $module) {
            DB::table('smart_ordering_entitlements')->updateOrInsert(
                [
                    'site_id' => $this->branch->site_id,
                    'branch_id' => null,
                    'scope_key' => 'site',
                    'module' => $module,
                ],
                [
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
        $this->branch->forceFill(['pickup_promise_minutes' => 2])->save();

        $this->actingAs($admin)->post(
            route('admin.smart-ordering.kitchen.stations.store', $this->branch->public_id),
            [
                'name_en' => 'Grill',
                'name_ar' => 'الشواية',
                'capacity_limit' => 12,
                'sort_order' => 10,
                'is_active' => 1,
            ],
        )->assertRedirect();

        $station = DB::table('smart_ordering_kitchen_stations')
            ->where('branch_id', $this->branch->id)
            ->where('name_en', 'Grill')
            ->firstOrFail();

        $this->actingAs($admin)->post(
            route('admin.smart-ordering.kitchen.rules.store', $this->branch->public_id),
            [
                'station_public_id' => $station->public_id,
                'match_type' => 'product',
                'product_id' => $this->productId,
                'priority' => 10,
                'is_active' => 1,
            ],
        )->assertRedirect();

        $this->app['auth']->guard()->logout();

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 2);
        $checkout = $this->checkout($cart, $token, 'smart-ordering-kitchen-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();
        $reference = (string) $checkout->json('data.reference');

        $context = DB::table('smart_ordering_order_contexts')
            ->where('public_reference', $reference)
            ->firstOrFail();
        DB::table('smart_ordering_order_contexts')
            ->where('id', $context->id)
            ->update(['lifecycle_status' => 'CONFIRMED', 'updated_at' => now()]);
        DB::table('orders')
            ->where('id', $context->order_id)
            ->update(['created_at' => now()->subMinutes(5), 'updated_at' => now()]);

        $lineId = (int) DB::table('order_items')
            ->where('order_id', $context->order_id)
            ->orderBy('id')
            ->value('id');

        app()->setLocale('en');
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'kitchen',
                'branch' => $this->branch->public_id,
            ]))
            ->assertOk()
            ->assertSee('Kitchen display &amp; routing', false)
            ->assertSee('Grill')
            ->assertSee('Meal')
            ->assertSee('Queued')
            ->assertSee('Over SLA')
            ->assertDontSee('order_context_id', false);

        $itemOperationKey = (string) Str::uuid();
        $itemActionUrl = route('admin.smart-ordering.kitchen.item-action', [
            $this->branch->public_id,
            $reference,
            $lineId,
        ]);
        $itemPayload = ['action' => 'bump', 'operation_key' => $itemOperationKey];

        $this->actingAs($admin)->post($itemActionUrl, $itemPayload)->assertRedirect();
        $this->actingAs($admin)->post($itemActionUrl, $itemPayload)->assertRedirect();

        $itemState = DB::table('smart_ordering_kitchen_item_states')
            ->where('order_item_id', $lineId)
            ->firstOrFail();
        $this->assertSame('preparing', $itemState->status);
        $this->assertSame(
            1,
            DB::table('smart_ordering_kitchen_ticket_events')
                ->where('order_item_id', $lineId)
                ->where('operation_key', $itemOperationKey)
                ->count(),
        );

        $operationKey = (string) Str::uuid();
        $actionUrl = route('admin.smart-ordering.kitchen.ticket-action', [
            $this->branch->public_id,
            $reference,
        ]);
        $payload = ['action' => 'bump', 'operation_key' => $operationKey];

        $this->actingAs($admin)->post($actionUrl, $payload)->assertRedirect();
        $this->actingAs($admin)->post($actionUrl, $payload)->assertRedirect();

        $state = DB::table('smart_ordering_kitchen_ticket_states')
            ->where('order_context_id', $context->id)
            ->firstOrFail();
        $this->assertSame('preparing', $state->status);
        $this->assertSame(
            1,
            DB::table('smart_ordering_kitchen_ticket_events')
                ->where('ticket_state_id', $state->id)
                ->whereNull('order_item_id')
                ->where('operation_key', $operationKey)
                ->count(),
        );

        $siblingBranch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $this->branch->site_id,
            'code' => 'kw-kitchen-sibling',
            'name_ar' => 'فرع مطبخ ثان',
            'name_en' => 'Kitchen Sibling',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
        ]);
        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.kitchen.ticket-action', [$siblingBranch->public_id, $reference]), [
                'action' => 'bump',
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertNotFound();

        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $foreignStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'SMART-ORDERING-KITCHEN-FOREIGN',
            'name' => 'Foreign Kitchen Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($foreignStoreId);

        $foreignSiteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $foreignStoreId,
            'slug' => 'foreign-kitchen-'.Str::lower(Str::random(8)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignBranch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $foreignSiteId,
            'code' => 'foreign-kitchen-branch',
            'name_ar' => 'مطبخ خارجي',
            'name_en' => 'Foreign Kitchen',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
        ]);
        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.kitchen.stations.store', $foreignBranch->public_id), [
                'name_en' => 'Forbidden Station',
                'capacity_limit' => 1,
                'is_active' => 1,
            ])
            ->assertNotFound();

        DB::table('smart_ordering_entitlements')
            ->where('site_id', $this->branch->site_id)
            ->where('module', 'kitchen_routing')
            ->update(['enabled' => false, 'updated_at' => now()]);

        $this->actingAs($admin)
            ->post(route('admin.smart-ordering.kitchen.rules.store', $this->branch->public_id), [
                'station_public_id' => $station->public_id,
                'match_type' => 'all',
                'priority' => 20,
                'is_active' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'kitchen',
                'branch' => $this->branch->public_id,
            ]))
            ->assertOk()
            ->assertSee('Kitchen routing is disabled')
            ->assertSee('Unrouted')
            ->assertSee('Preparing');

        $admin->forceFill(['locale' => 'ar'])->save();
        app()->setLocale('ar');
        $this->actingAs($admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'kitchen',
                'branch' => $this->branch->public_id,
            ]))
            ->assertOk()
            ->assertSee('شاشة المطبخ والتوجيه')
            ->assertSee('الشواية')
            ->assertSee('بدون توجيه');

        DB::table('smart_ordering_entitlements')
            ->where('site_id', $this->branch->site_id)
            ->where('module', 'kitchen_display')
            ->update(['enabled' => false, 'updated_at' => now()]);

        $this->actingAs($admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'kitchen',
                'branch' => $this->branch->public_id,
            ]))
            ->assertOk()
            ->assertSee('وحدة شاشة المطبخ غير مفعلة');

        $this->assertDatabaseHas('smart_ordering_order_contexts', [
            'public_reference' => $reference,
            'lifecycle_status' => 'CONFIRMED',
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

final class SmartOrderingTestPaymentProvider implements RestaurantPaymentProvider
{
    /** @var list<array{operation:string,amount:string,currency:string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['authorize', 'capture', 'void', 'refund'], true);
    }

    public function execute(
        string $operation,
        Payment $payment,
        string $amount,
        string $currency,
        array $context = [],
    ): RestaurantPaymentProviderResult {
        self::$calls[] = [
            'operation' => $operation,
            'amount' => $amount,
            'currency' => $currency,
        ];

        return new RestaurantPaymentProviderResult(
            succeeded: true,
            providerReference: (string) ($payment->provider_reference ?: 'testpay-'.$payment->getKey()),
            providerStatus: 'accepted',
            safeMetadata: ['trace' => 'test-safe', 'token' => 'must-not-persist'],
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        return $request->header('X-Test-Payment-Signature') === 'valid';
    }

    public function webhook(Request $request): RestaurantPaymentWebhookResult
    {
        return new RestaurantPaymentWebhookResult(
            eventId: (string) $request->input('event_id'),
            providerReference: (string) $request->input('provider_reference'),
            operation: (string) $request->input('operation'),
            paymentStatus: (string) $request->input('payment_status'),
            amount: $request->input('amount') === null ? null : (string) $request->input('amount'),
            providerStatus: 'accepted',
            safeMetadata: ['trace' => 'webhook-safe', 'token' => 'must-not-persist'],
        );
    }
}
