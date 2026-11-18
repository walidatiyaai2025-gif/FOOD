<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\CatalogOwnership;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class B2bAdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2b_admin_gets_rtl_workspace_without_wholesale_store_module(): void
    {
        $user = $this->user('B2B_ADMIN', 'ar');

        $this->actingAs($user)->get('/admin/b2b/products')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('كتالوج الجملة')
            ->assertSee('FOODEX · إدارة الجملة')
            ->assertDontSee('فروع الجملة');

        $this->actingAs($user)->get('/admin/b2b/stores')->assertNotFound();
    }

    public function test_pricing_workspace_is_hidden_until_tiers_are_enabled(): void
    {
        $admin = $this->user('B2B_ADMIN', 'en');

        $this->actingAs($admin)
            ->get('/admin/b2b/pricing')
            ->assertNotFound();

        $this->actingAs($admin)
            ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => '1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->assertDatabaseHas('settings', [
            'store_id' => $storeId,
            'key' => 'b2b.pricing_tiers_enabled',
            'value' => 'true',
        ]);

        $this->actingAs($admin)
            ->get('/admin/b2b/pricing')
            ->assertOk();
    }

    public function test_wholesale_price_rule_help_reuses_authorized_pricing_workspace_in_both_locales(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $admin = $this->user('B2B_ADMIN', $locale);
            $this->actingAs($admin)
                ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => '1'])
                ->assertRedirect();

            $this->actingAs($admin)->get('/admin/b2b/pricing')
                ->assertOk()
                ->assertSee('data-v2-stage2-price-rule-setup-help', false)
                ->assertSee('data-readiness-state="partial-review"', false)
                ->assertSee(__('b2b_pricing_setup.rule_title'))
                ->assertSee(__('b2b_pricing_setup.rule_source'))
                ->assertSee(__('b2b_pricing_setup.rule_example'))
                ->assertSee(__('b2b_pricing_setup.rule_default'))
                ->assertSee(__('b2b_pricing_setup.rule_validation'))
                ->assertSee(__('b2b_pricing_setup.rule_permissions'))
                ->assertSee(__('b2b_pricing_setup.rule_test_help'));

            // Existing settings remain the only read-only mode precheck.
            $this->actingAs($admin)->get('/admin/b2b/settings')
                ->assertOk()
                ->assertDontSee('data-v2-stage2-price-rule-setup-help', false);
        }
    }

    public function test_price_rule_candidate_test_is_read_only_and_save_uses_same_authority(): void
    {
        $admin = $this->user('B2B_ADMIN', 'en');
        $store = app(WholesalePrincipal::class)->storeId();
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($store, 'b2b');
        $unit = $this->unit();
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unit,
            'sku' => 'PRECHECK-SANDBOX',
            'name' => 'Disposable price candidate',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'PRECHECK-SANDBOX',
            'name' => 'Disposable tier',
            'priority' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($admin)->put('/admin/b2b/settings/pricing-tiers', [
            'enabled' => '1',
            'setup_action' => 'save',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $candidate = [
            'price_tier_id' => $tier,
            'product_id' => $product,
            'unit_price' => '10.000',
            'minimum_quantity' => '1.000',
            'ordering_increment' => '1.000',
            'replace_quantity_price_breaks' => '1',
        ];
        $auditBefore = DB::table('audit_logs')->count();

        $this->actingAs($admin)->get('/admin/b2b/pricing')
            ->assertOk()
            ->assertSee(__('b2b_pricing_setup.rule_test'));
        $this->actingAs($admin)->post('/admin/b2b/pricing', $candidate + [
            'setup_action' => 'test',
        ])->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('b2b_pricing_setup.rule_test_valid'));
        $this->assertDatabaseCount('b2b_price_rules', 0);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        $this->actingAs($admin)->post('/admin/b2b/pricing', array_replace($candidate, [
            'unit_price' => '-1',
            'setup_action' => 'test',
        ]))->assertSessionHasErrors('unit_price');
        $this->assertDatabaseCount('b2b_price_rules', 0);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        $this->actingAs($admin)->post('/admin/b2b/pricing', $candidate + [
            'setup_action' => 'delete',
        ])->assertSessionHasErrors('setup_action');
        $this->assertDatabaseCount('b2b_price_rules', 0);

        $otherStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'PRICE-CROSS-STORE',
            'name' => 'Disposable other wholesale store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCatalog = app(CatalogOwnership::class)->defaultCatalogForStore($otherStore, 'b2b');
        $otherProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $otherCatalog->id,
            'unit_id' => $unit,
            'sku' => 'CROSS-STORE-CANDIDATE',
            'name' => 'Unauthorized other store product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($admin)->post('/admin/b2b/pricing', array_replace($candidate, [
            'product_id' => $otherProduct,
            'setup_action' => 'test',
        ]))->assertStatus(422);
        $this->assertDatabaseCount('b2b_price_rules', 0);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        $this->actingAs($admin)->post('/admin/b2b/pricing', $candidate + [
            'setup_action' => 'save',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('b2b_price_rules', [
            'store_id' => $store,
            'product_id' => $product,
            'price_tier_id' => $tier,
            'unit_price' => '10.000',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.price_rule.saved']);

        // An omitted break schedule must still be checked against the saved
        // schedule, exactly as Save does under its final transaction lock.
        DB::table('b2b_price_rules')
            ->where('store_id', $store)
            ->where('price_tier_id', $tier)
            ->where('product_id', $product)
            ->update(['quantity_price_breaks' => json_encode([
                ['minimum_quantity' => 5.0, 'unit_price' => 9.0],
            ], JSON_THROW_ON_ERROR)]);
        $savedRule = DB::table('b2b_price_rules')
            ->where('store_id', $store)
            ->where('price_tier_id', $tier)
            ->where('product_id', $product)
            ->first();
        $auditAfterSave = DB::table('audit_logs')->count();

        $this->actingAs($admin)->post('/admin/b2b/pricing', array_replace($candidate, [
            'unit_price' => '8.000',
            'replace_quantity_price_breaks' => '0',
            'setup_action' => 'test',
        ]))->assertSessionHasErrors('quantity_price_breaks');
        $this->assertDatabaseCount('b2b_price_rules', 1);
        $this->assertSame($savedRule->quantity_price_breaks, DB::table('b2b_price_rules')
            ->where('store_id', $store)
            ->where('price_tier_id', $tier)
            ->where('product_id', $product)
            ->value('quantity_price_breaks'));
        $this->assertSame($auditAfterSave, DB::table('audit_logs')->count());

        $this->actingAs($admin)->post('/admin/b2b/pricing', array_replace($candidate, [
            'replace_quantity_price_breaks' => '0',
            'setup_action' => 'test',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($auditAfterSave, DB::table('audit_logs')->count());

        $retail = $this->user('B2C_STORE_ADMIN', 'ar');
        $this->actingAs($retail)->post('/admin/b2b/pricing', $candidate + [
            'setup_action' => 'test',
        ])->assertForbidden();
    }

    public function test_wholesale_pricing_mode_setup_help_and_readiness_are_localized(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $admin = $this->user('B2B_ADMIN', $locale);
            $this->actingAs($admin)->get('/admin/b2b/settings')
                ->assertOk()
                ->assertSee('data-v2-stage2-pricing-setup', false)
                ->assertSee('data-readiness-state="base-price"', false)
                ->assertSee(__('b2b_pricing_setup.title'))
                ->assertSee(__('b2b_pricing_setup.source'))
                ->assertSee(__('b2b_pricing_setup.example'))
                ->assertSee(__('b2b_pricing_setup.default'))
                ->assertSee(__('b2b_pricing_setup.test_help'))
                ->assertSee(__('b2b_pricing_setup.permissions'))
                ->assertSee($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"', false);
        }
    }

    public function test_wholesale_pricing_mode_test_does_not_save_or_audit_and_save_still_persists(): void
    {
        $admin = $this->user('B2B_ADMIN', 'en');
        $storeId = app(WholesalePrincipal::class)->storeId();

        $this->actingAs($admin)
            ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => '1', 'setup_action' => 'test'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('b2b_pricing_setup.test_missing'));

        $this->assertDatabaseMissing('settings', ['store_id' => $storeId, 'key' => 'b2b.pricing_tiers_enabled']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'b2b.pricing_mode.updated']);

        $this->actingAs($admin)
            ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => '1', 'setup_action' => 'save'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'store_id' => $storeId,
            'key' => 'b2b.pricing_tiers_enabled',
            'value' => 'true',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.pricing_mode.updated']);

        $auditCount = DB::table('audit_logs')->where('event', 'b2b.pricing_mode.updated')->count();
        $this->actingAs($admin)
            ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => '0', 'setup_action' => 'test'])
            ->assertRedirect()
            ->assertSessionHas('status', __('b2b_pricing_setup.test_base'));

        $this->assertDatabaseHas('settings', [
            'store_id' => $storeId,
            'key' => 'b2b.pricing_tiers_enabled',
            'value' => 'true',
        ]);
        $this->assertSame($auditCount, DB::table('audit_logs')->where('event', 'b2b.pricing_mode.updated')->count());

        $this->actingAs($admin)->get('/admin/b2b/settings')
            ->assertOk()
            ->assertSee('data-readiness-state="action-required"', false);
    }

    public function test_wholesale_pricing_mode_setup_rejects_invalid_actions_and_retail_roles(): void
    {
        $admin = $this->user('B2B_ADMIN', 'en');
        $this->actingAs($admin)
            ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => '1', 'setup_action' => 'delete'])
            ->assertSessionHasErrors('setup_action');
        $this->actingAs($admin)
            ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => 'perhaps', 'setup_action' => 'test'])
            ->assertSessionHasErrors('enabled');

        $retail = $this->user('B2C_STORE_ADMIN', 'ar');
        $this->actingAs($retail)
            ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => '1', 'setup_action' => 'test'])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => 'b2b.pricing_tiers_enabled']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'b2b.pricing_mode.updated']);
    }

    public function test_b2b_customer_master_data_guide_is_localized_and_requires_approval(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $admin = $this->user('B2B_ADMIN', $locale);

            $this->actingAs($admin)->get('/admin/b2b/clients')
                ->assertOk()
                ->assertSee('data-v2-stage2-customer-setup', false)
                ->assertSee('data-readiness-state="needs-approval"', false)
                ->assertSee(__('b2b_customer_setup.title'))
                ->assertSee(__('b2b_customer_setup.source'))
                ->assertSee(__('b2b_customer_setup.example'))
                ->assertSee(__('b2b_customer_setup.default'))
                ->assertSee(__('b2b_customer_setup.validation'))
                ->assertSee(__('b2b_customer_setup.permissions'))
                ->assertSee(__('b2b_customer_setup.test_help'))
                ->assertSee($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"', false);
        }
    }

    public function test_b2b_customer_master_data_test_is_read_only_and_save_is_pending(): void
    {
        $admin = $this->user('B2B_ADMIN', 'en');
        $details = [
            'company_name' => 'Illustrative Buyer Ltd',
            'name' => 'Example Buyer',
            'email' => 'customer-setup@example.invalid',
            'phone' => '',
            'tax_number' => '',
            'password' => 'Example-Test-Passphrase-123!',
        ];
        $before = [
            'users' => DB::table('users')->count(),
            'accounts' => DB::table('b2b_accounts')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        $this->actingAs($admin)->post('/admin/b2b/clients', $details + ['setup_action' => 'test'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('b2b_customer_setup.test_valid'));

        $this->assertSame($before['users'], DB::table('users')->count());
        $this->assertSame($before['accounts'], DB::table('b2b_accounts')->count());
        $this->assertSame($before['audit'], DB::table('audit_logs')->count());

        $this->actingAs($admin)->post('/admin/b2b/clients', $details + ['setup_action' => 'save'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('b2b_accounts', [
            'company_name' => 'Illustrative Buyer Ltd',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'customer-setup@example.invalid',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.account.created']);
    }

    public function test_b2b_customer_master_data_test_validates_and_denies_retail_roles(): void
    {
        $admin = $this->user('B2B_ADMIN', 'en');
        $details = [
            'company_name' => 'Illustrative Buyer Ltd',
            'name' => 'Example Buyer',
            'email' => 'invalid-email',
            'password' => 'short',
        ];

        $this->actingAs($admin)->post('/admin/b2b/clients', $details + ['setup_action' => 'test'])
            ->assertSessionHasErrors(['email', 'password']);

        $this->actingAs($admin)->post('/admin/b2b/clients', $details + ['setup_action' => 'delete'])
            ->assertSessionHasErrors('setup_action');

        $retail = $this->user('B2C_STORE_ADMIN', 'ar');
        $this->actingAs($retail)->post('/admin/b2b/clients', [
            'company_name' => 'Illustrative Retail Attack',
            'name' => 'Unauthorized',
            'email' => 'retail-setup@example.invalid',
            'password' => 'Example-Test-Passphrase-123!',
            'setup_action' => 'test',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'retail-setup@example.invalid']);
        $this->assertDatabaseMissing('b2b_accounts', ['company_name' => 'Illustrative Retail Attack']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'b2b.account.created']);
    }

    public function test_product_create_and_edit_own_price_stock_and_low_stock_threshold(): void
    {
        $admin = $this->user('B2B_ADMIN', 'en');
        $storeId = app(WholesalePrincipal::class)->storeId();
        $warehouseId = app(WholesalePrincipal::class)->warehouseId();
        $unitId = $this->unit();

        $this->actingAs($admin)->post('/admin/b2b/products', [
            'unit_id' => $unitId,
            'name' => 'Owned Stock Product',
            'description' => 'Created from product form',
            'price' => 10.500,
            'quantity' => 12,
            'low_stock_threshold' => 3,
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $createdProduct = DB::table('products')
            ->where('name', 'Owned Stock Product')
            ->first(['id', 'sku']);
        $this->assertNotNull($createdProduct);
        $productId = (int) $createdProduct->id;
        $generatedSku = (string) $createdProduct->sku;
        $this->assertMatchesRegularExpression('/^SKU-'.$storeId.'-[A-Z0-9]{10}$/', $generatedSku);
        $this->assertGreaterThan(0, $productId);
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'low_stock_threshold' => 3.000,
        ]);
        $this->assertDatabaseHas('store_products', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 10.500,
        ]);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 12.000,
            'reserved_quantity' => 0.000,
        ]);

        DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->update(['reserved_quantity' => 4, 'updated_at' => now()]);

        $this->actingAs($admin)
            ->patch('/admin/b2b/products/'.$productId, [
                'unit_id' => $unitId,
                'sku' => 'USER-CANNOT-OVERRIDE',
                'name' => 'Owned Stock Product Updated',
                'description' => 'Updated from product form',
                'price' => 11.750,
                'quantity' => 6,
                'low_stock_threshold' => 2,
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'sku' => $generatedSku,
            'name' => 'Owned Stock Product Updated',
            'low_stock_threshold' => 2.000,
        ]);
        $this->assertDatabaseHas('store_products', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 11.750,
        ]);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 6.000,
            'reserved_quantity' => 4.000,
        ]);

        $this->actingAs($admin)
            ->from('/admin/b2b/products')
            ->patch('/admin/b2b/products/'.$productId, [
                'unit_id' => $unitId,
                'name' => 'Owned Stock Product Updated',
                'price' => 11.750,
                'quantity' => 3,
                'low_stock_threshold' => 2,
                'is_active' => '1',
            ])
            ->assertRedirect('/admin/b2b/products')
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 6.000,
            'reserved_quantity' => 4.000,
        ]);
    }

    public function test_b2b_dashboard_accepts_open_date_ranges_beyond_31_days(): void
    {
        $admin = $this->user('B2B_ADMIN', 'ar');
        $to = now('Asia/Kuwait')->startOfDay();
        $from = $to->copy()->subDays(45);

        $this->actingAs($admin)
            ->get('/admin/b2b/dashboard?from='.$from->toDateString().'&to='.$to->toDateString())
            ->assertOk()
            ->assertSee('المبيعات')
            ->assertDontSee('المبيعات اليومية')
            ->assertSee('name="from"', false)
            ->assertSee('name="to"', false);

        $this->actingAs($admin)
            ->get('/admin/b2b/dashboard?to='.$to->toDateString())
            ->assertOk();
    }

    public function test_core_b2b_screens_use_the_single_principal_and_warehouses(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($store, 'b2b');
        $warehouse = $this->warehouse($store, 'WHOLESALE-WH', 'Wholesale Warehouse');
        $unit = $this->unit();
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unit,
            'sku' => 'B2B-SKU',
            'name' => 'B2B Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $store,
            'product_id' => $product,
            'price' => 12.500,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 20,
            'reserved_quantity' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$customer, $b2bCustomer] = $this->b2bCustomer('Buyer One', 'buyer-one@example.test', '50000000');
        DB::table('b2b_accounts')->insert([
            'customer_id' => $customer,
            'b2b_customer_id' => $b2bCustomer,
            'company_name' => 'Buyer Co',
            'tax_number' => 'TAX-1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'warehouse_id' => $warehouse,
            'customer_id' => $customer,
            'b2b_customer_id' => $b2bCustomer,
            'order_number' => 'B2B-ORDER-1',
            'channel' => 'b2b',
            'status' => 'processing',
            'currency' => 'EGP',
            'subtotal' => 12.5,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 12.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order,
            'product_id' => $product,
            'sku_snapshot' => 'B2B-SKU',
            'name_snapshot' => 'B2B Product',
            'quantity' => 2,
            'unit_price' => 6.25,
            'line_total' => 12.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wholeOrder = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'warehouse_id' => $warehouse,
            'customer_id' => $customer,
            'b2b_customer_id' => $b2bCustomer,
            'order_number' => 'B2B-ORDER-2',
            'channel' => 'b2b',
            'status' => 'processing',
            'currency' => 'EGP',
            'subtotal' => 12,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 12,
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);

        $admin = $this->user('B2B_ADMIN', 'en');

        $this->actingAs($admin)->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('#'.$order)
            ->assertSee('#'.$wholeOrder)
            ->assertSee('Buyer One')
            ->assertSee('Wholesale Warehouse')
            ->assertSee('B2B Product')
            ->assertSee('EGP 12.50')
            ->assertSee('EGP 12.00')
            ->assertSee('aria-label="EGP 24.50"', false);

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin)->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('dir="ltr">EGP 12.50', false)
            ->assertSee('dir="ltr">EGP 12.00', false);

        $this->actingAs($admin)->get('/admin/b2b/inventory')
            ->assertOk()
            ->assertSee('Wholesale Warehouse')
            ->assertDontSee('Wholesale store');

        $this->actingAs($admin)->get('/admin/b2b/clients')
            ->assertOk()
            ->assertSee('Buyer Co')
            ->assertSee('TAX-1');

        $this->actingAs($admin)->get('/admin/b2b/products')
            ->assertOk()
            ->assertSee('B2B Product')
            ->assertSee('17')
            ->assertSee('12.50 EGP')
            ->assertDontSee('Wholesale store');
    }

    public function test_b2b_operations_use_principal_warehouse_and_reject_driver_fulfillment(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($store, 'b2b');
        $warehouse = $this->warehouse($store, 'OPS-WH', 'Operations Warehouse');
        $unit = $this->unit();
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unit,
            'sku' => 'OPS-SKU',
            'name' => 'Operations Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $store,
            'product_id' => $product,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'OPS-GOLD',
            'name' => 'Operations Gold',
            'priority' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        [$customer, $b2bCustomer] = $this->b2bCustomer('Operations Buyer', 'ops-buyer@example.test');
        DB::table('b2b_accounts')->insert([
            'customer_id' => $customer,
            'b2b_customer_id' => $b2bCustomer,
            'price_tier_id' => $tier,
            'company_name' => 'Operations Buyer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tier,
            'store_id' => $store,
            'product_id' => $product,
            'unit_price' => 7.250,
            'minimum_quantity' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('settings')->updateOrInsert(
            [
                'store_id' => $store,
                'key' => 'b2b.pricing_tiers_enabled',
            ],
            [
                'value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $admin = $this->user('B2B_ADMIN', 'en');

        $driverResponse = $this->actingAs($admin)->post('/admin/b2b/drivers', [
            'name' => 'Principal Driver',
            'email' => 'principal-driver@example.test',
            'password' => 'password123',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $driverUserId = (int) User::query()->where('email', 'principal-driver@example.test')->value('id');
        $driver = Driver::query()->where('user_id', $driverUserId)->firstOrFail();
        $this->assertSame($store, (int) $driver->store_id);

        $driverUser = User::query()->findOrFail($driverUserId);
        $driverUser->createToken('driver-before-reset');
        $this->actingAs($admin)->patch("/admin/b2b/drivers/{$driver->id}/password", [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password-123', $driverUser->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $driverUserId,
        ]);

        $driverPage = $this->actingAs($admin)->get('/admin/b2b/drivers')->assertOk();
        $driverPage->assertSee('Reset driver password')->assertSee('principal-driver@example.test');

        $orderResponse = $this->actingAs($admin)->post('/admin/b2b/orders', [
            'warehouse_id' => $warehouse,
            'customer_id' => $b2bCustomer,
            'payment_method' => config('checkout.default_payment_method'),
            'discount_total' => 0,
            'delivery_total' => 0,
            'items' => [['product_id' => $product, 'quantity' => 2]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = DB::table('orders')->where('channel', 'b2b')->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($store, (int) $order->store_id);
        $this->assertSame($warehouse, (int) $order->warehouse_id);

        $this->actingAs($admin)->get('/admin/b2b/orders')
            ->assertOk()
            ->assertSee('Operations Warehouse')
            ->assertSee('Select source warehouse')
            ->assertDontSee('Select wholesale store');

        $this->actingAs($admin)->get('/admin/b2b/drivers')
            ->assertOk()
            ->assertSee('Principal Driver')
            ->assertDontSee('Wholesale store');

        $this->actingAs($admin)->post('/admin/b2b/drivers/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertConflict();
        $this->assertDatabaseMissing('driver_assignments', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ]);

        $this->actingAs($admin)->post('/admin/b2b/orders/'.$order->id.'/status', [
            'status' => 'confirmed',
            'note' => 'Legacy confirmation attempt',
        ])->assertConflict();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->post('/admin/b2b/drivers/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertConflict();

        $this->assertDatabaseMissing('driver_assignments', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ]);

        $this->actingAs($admin)->post('/admin/b2b/pricing', [
            'price_tier_id' => $tier,
            'product_id' => $product,
            'unit_price' => 7.500,
            'minimum_quantity' => 5,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('b2b_price_rules', [
            'price_tier_id' => $tier,
            'store_id' => $store,
            'product_id' => $product,
            'unit_price' => 7.500,
        ]);

        $orderResponse->assertSessionHasNoErrors();
        $driverResponse->assertSessionHasNoErrors();
    }

    public function test_b2b_operation_wrappers_reject_cross_channel_resources(): void
    {
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'code' => 'OPS-B2C',
            'name' => 'Retail Hidden',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Retail Buyer',
            'email' => 'retail-buyer@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $customer,
            'order_number' => 'OPS-B2C-1',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->user('B2B_ADMIN', 'en');

        $this->actingAs($admin)->post("/admin/b2b/orders/{$order}/status", ['status' => 'cancelled', 'note' => 'Cross-channel reject'])->assertNotFound();
    }

    public function test_b2b_reports_and_settings_are_principal_scoped(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$customer, $b2bCustomer] = $this->b2bCustomer('Wholesale Report Buyer', null);
        DB::table('orders')->insert([
            'store_id' => $store,
            'customer_id' => $customer,
            'b2b_customer_id' => $b2bCustomer,
            'order_number' => 'B2B-RPT-1',
            'channel' => 'b2b',
            'status' => 'delivered',
            'currency' => 'EGP',
            'subtotal' => 18,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 18,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            ['store_id' => $store, 'key' => 'wholesale.minimum_order', 'value' => json_encode(25), 'is_secret' => false, 'created_at' => now(), 'updated_at' => now()],
            ['store_id' => $store, 'key' => 'wholesale.private_token', 'value' => json_encode('never-show'), 'is_secret' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $admin = $this->user('B2B_ADMIN', 'en');
        $this->actingAs($admin)->get('/admin/b2b/reports')
            ->assertOk()
            ->assertSee('EGP 18.00')
            ->assertSee('Open reports')
            ->assertDontSee('Wholesale store');

        $this->actingAs($admin)->get('/admin/b2b/settings')
            ->assertOk()
            ->assertSee('wholesale.minimum_order')
            ->assertSee('25')
            ->assertDontSee('wholesale.private_token')
            ->assertDontSee('never-show');
    }

    public function test_retail_linked_status_is_store_managed_and_unpriced_accounts_are_not_order_options(): void
    {
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailType,
            'code' => 'INSPECT-RETAIL',
            'name' => 'Inspector Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$retailLegacy, $retailCustomer] = $this->b2bCustomer('Retail Linked Buyer', 'retail-linked@example.test');
        $retailAccount = (int) DB::table('b2b_accounts')->insertGetId([
            'customer_id' => $retailLegacy,
            'b2b_customer_id' => $retailCustomer,
            'price_tier_id' => null,
            'company_name' => 'Inspector Retail',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('retail_wholesale_accounts')->insert([
            'retail_store_id' => $retailStore,
            'b2b_customer_id' => $retailCustomer,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tier = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        [$approvedLegacy, $approvedCustomer] = $this->b2bCustomer('Approved Buyer', 'approved-buyer@example.test');
        $approvedAccount = (int) DB::table('b2b_accounts')->insertGetId([
            'customer_id' => $approvedLegacy,
            'b2b_customer_id' => $approvedCustomer,
            'price_tier_id' => $tier,
            'company_name' => 'Approved Company',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->user('B2B_ADMIN', 'en');

        $clients = $this->actingAs($admin)->get('/admin/b2b/clients')->assertOk();
        $clients->assertSee('Status is controlled by the linked Retail store');
        $this->assertStringNotContainsString(
            route('admin.b2b.clients.status', ['account' => $retailAccount], false),
            $clients->getContent(),
        );
        $this->assertStringContainsString(
            route('admin.b2b.clients.status', ['account' => $approvedAccount], false),
            $clients->getContent(),
        );

        $orders = $this->actingAs($admin)->get('/admin/b2b/orders')->assertOk();
        $orders
            ->assertDontSee('Approved Buyer')
            ->assertDontSee('Retail Linked Buyer')
            ->assertSee('data-lookup-url', false);

        $b2bStore = (int) DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2B')
            ->where('stores.is_active', true)
            ->value('stores.id');

        $lookup = $this->actingAs($admin)->getJson(
            '/admin/operations/orders/lookups/customer?channel=b2b&store_id='.$b2bStore.'&q=Buyer'
        );
        $lookup
            ->assertOk()
            ->assertJsonFragment([
                'id' => $approvedCustomer,
                'label' => 'Approved Company · Approved Buyer',
            ]);
        $this->assertStringNotContainsString('Retail Linked Buyer', $lookup->getContent());
    }

    public function test_reference_routes_and_permissions_remain_available(): void
    {
        $admin = $this->user('B2B_ADMIN', 'en');

        $this->actingAs($admin)
            ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => '1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/admin/b2b/pricing-approvals')->assertOk()->assertSee('Pricing & Approvals');
        $this->actingAs($admin)->get('/admin/b2b/settings-permissions')->assertOk()->assertSee('Wholesale Settings');

        $finance = $this->user('FINANCE', 'en');
        $this->actingAs($finance)->get('/admin/b2b/pricing-approvals')->assertForbidden();
        $this->actingAs($finance)->get('/admin/b2b/settings-permissions')->assertForbidden();
    }

    public function test_retail_only_role_is_forbidden_and_invalid_module_is_not_found(): void
    {
        $retailDriver = $this->user('B2C_DRIVER', 'en');
        $this->actingAs($retailDriver)->get('/admin/b2b/dashboard')->assertForbidden();

        $admin = $this->user('B2B_ADMIN', 'en');
        $this->actingAs($admin)->get('/admin/b2b/not-real')->assertNotFound();
    }

    public function test_english_locale_renders_only_approved_wholesale_modules(): void
    {
        $admin = $this->user('SUPER_ADMIN', 'en');

        $this->actingAs($admin)
            ->put('/admin/b2b/settings/pricing-tiers', ['enabled' => '1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($admin)->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertDontSee('Wholesale Stores');

        foreach (['Product Management', 'Orders', 'Drivers & Delivery', 'Finance & Invoices', 'Reports'] as $label) {
            $response->assertSee($label);
        }

        $response
            ->assertDontSee('Inventory Management')
            ->assertDontSee('System Settings / Lookups')
            ->assertDontSee('B2B Clients')
            ->assertDontSee('Pricing & Approvals');
    }

    public function test_b2b_workspace_inherits_premium_shared_visual_primitives(): void
    {
        $admin = $this->user('SUPER_ADMIN', 'en');

        $response = $this->actingAs($admin)->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('data-b2b-premium="v1"', false)
            ->assertSee('class="main foodex-admin-page"', false)
            ->assertSee('data-b2b-reference-dashboard="841x564"', false)
            ->assertSee('b2b-ref-kpis', false)
            ->assertSee('--b2b-kpi-columns:4', false)
            ->assertSee('b2b-ref-middle', false)
            ->assertSee('b2b-ref-bottom', false)
            ->assertSee('b2b-ref-chart-wrap', false)
            ->assertSee('b2b-ref-donut', false)
            ->assertDontSee('b2b-ref-header-tools', false)
            ->assertDontSee('data-foodex-dashboard-header-search-row', false);

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertSame(1, substr_count($html, 'data-foodex-global-header'));
        $this->assertSame(1, substr_count($html, '<section class="foodex-global-search foodex-global-search--header"'));
    }

    public function test_arabic_wholesale_order_warehouse_guards_are_localized_and_do_not_write(): void
    {
        $this->assertWarehouseGuardLocale(
            'ar',
            'يلزم اختيار مستودع جملة صالح.',
            'يجب أن يتبع المستودع عملية الجملة الرئيسية.',
        );
    }

    public function test_english_wholesale_order_warehouse_guards_remain_unchanged_and_do_not_write(): void
    {
        $this->assertWarehouseGuardLocale(
            'en',
            'A valid Wholesale warehouse is required.',
            'Warehouse must belong to the main Wholesale operation.',
        );
    }

    private function assertWarehouseGuardLocale(string $locale, string $missingMessage, string $foreignMessage): void
    {
        $admin = $this->user('B2B_ADMIN', $locale);
        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'WAREHOUSE-LOCALE-FOREIGN-'.strtoupper($locale),
            'name' => 'Foreign Retail Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignWarehouse = $this->warehouse($retailStore, 'WH-FOREIGN-'.$locale, 'Foreign Retail Warehouse');
        $retailAdmin = $this->user('B2C_STORE_ADMIN', $locale);

        $tables = ['orders', 'invoices', 'payments', 'inventories', 'audit_logs'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $contraryHeader = $locale === 'ar' ? 'en-US' : 'ar-KW';
        $this->actingAs($admin)
            ->withHeaders(['Accept-Language' => $contraryHeader])
            ->postJson('/admin/b2b/orders', ['warehouse_id' => 0])
            ->assertUnprocessable()
            ->assertJsonPath('message', $missingMessage);

        $this->actingAs($admin)
            ->withHeaders(['Accept-Language' => $contraryHeader])
            ->postJson('/admin/b2b/orders', ['warehouse_id' => $foreignWarehouse])
            ->assertUnprocessable()
            ->assertJsonPath('message', $foreignMessage);

        $this->actingAs($retailAdmin)
            ->postJson('/admin/b2b/orders', ['warehouse_id' => $foreignWarehouse])
            ->assertForbidden();

        $this->app['auth']->guard('web')->logout();
        $guestResponse = $this->postJson('/admin/b2b/orders', ['warehouse_id' => $foreignWarehouse]);
        $this->assertContains($guestResponse->getStatusCode(), [302, 401]);

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), $table.' must remain unchanged after rejected warehouse orders.');
        }
    }

    /** @return array{0:int,1:int} */
    private function b2bCustomer(string $name, ?string $email, ?string $phone = null): array
    {
        $legacy = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $b2b = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacy,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$legacy, $b2b];
    }

    private function unit(): int
    {
        $id = DB::table('units')->where('is_active', true)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('units')->insertGetId([
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'EA',
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(int $storeId, string $code, string $name): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(string $role, string $locale): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => strtolower($role).'-'.$locale.'-'.uniqid().'@workspace.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
