<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\CatalogOwnership;
use App\Services\ReportExportService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetailAdminProductionDefectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_open_direct_retail_catalog_and_canonical_category_url_and_save_category(): void
    {
        $storeId = $this->retailStore('DIRECT-CATALOG');
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get('/admin/catalog?tab=products&store_id='.$storeId)
            ->assertOk()
            ->assertSee('Inventory Management');

        $this->actingAs($admin)
            ->get('/admin/catalog/categories?store_id='.$storeId)
            ->assertRedirect('/admin/catalog?tab=categories&store_id='.$storeId);

        $this->actingAs($admin)
            ->post('/admin/catalog/categories', [
                'store_id' => $storeId,
                'name' => 'مشروبات',
                'slug' => 'retail-beverages',
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'name' => 'مشروبات',
            'slug' => 'retail-beverages',
        ]);
    }

    public function test_retail_admin_can_create_warehouse_and_initial_inventory_balance(): void
    {
        $storeId = $this->retailStore('INV-STORE');
        $admin = $this->storeAdmin($storeId, 'inventory-retail@example.test');
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId, 'b2c');
        $unitId = (int) DB::table('units')->where('scope', 'global')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => 'INV-PROD-1',
            'name' => 'Inventory Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/b2c/warehouses', [
            'store_id' => $storeId,
            'code' => 'RET-WH-1',
            'name' => 'Retail Main Warehouse',
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $warehouseId = (int) DB::table('warehouses')->where('store_id', $storeId)->where('code', 'RET-WH-1')->value('id');

        $this->actingAs($admin)->post('/admin/b2c/inventory', [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 25,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 25,
        ]);

        $this->actingAs($admin)
            ->get('/admin/b2c/inventory')
            ->assertOk()
            ->assertSee('Retail Main Warehouse')
            ->assertSee('Inventory Product');
    }

    public function test_retail_customer_image_is_saved_and_missing_image_uses_neutral_avatar(): void
    {
        Storage::fake('public');
        $storeId = $this->retailStore('AVATAR-STORE');
        $admin = $this->storeAdmin($storeId, 'avatar-retail@example.test');

        $this->actingAs($admin)->post('/admin/business/customers', [
            'type' => 'b2c',
            'store_id' => $storeId,
            'name' => 'Customer With Photo',
            'email' => 'with-photo@example.test',
            'customer_image' => UploadedFile::fake()->image('customer.png', 256, 256),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $withPhoto = DB::table('b2c_customers')->where('store_id', $storeId)->where('email', 'with-photo@example.test')->first();
        $this->assertNotNull($withPhoto);
        $this->assertStringStartsWith('storage/customers/'.$storeId.'/', (string) $withPhoto->image_path);

        DB::table('b2c_customers')->insert([
            'store_id' => $storeId,
            'name' => 'Customer Without Photo',
            'email' => 'without-photo@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/b2c/customers')
            ->assertOk()
            ->assertSee('Customer With Photo')
            ->assertSee('Customer Without Photo')
            ->assertSee('Default customer avatar');
    }

    public function test_banner_targets_retail_product_or_category_instead_of_free_form_url_and_accepts_small_valid_image(): void
    {
        Storage::fake('public');
        $storeId = $this->retailStore('BANNER-TARGET');
        $admin = $this->storeAdmin($storeId, 'banner-target@example.test');
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId, 'b2c');
        $unitId = (int) DB::table('units')->where('scope', 'global')->value('id');
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalog->id,
            'name' => 'Banner Category',
            'slug' => 'banner-category',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'BANNER-PROD',
            'name' => 'Banner Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/business/banners', [
            'store_id' => $storeId,
            'title' => 'Product Banner',
            'banner_image' => UploadedFile::fake()->image('banner.jpg', 320, 120),
            'target_ref' => 'product:'.$productId,
            'sort_order' => 1,
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('banners', [
            'store_id' => $storeId,
            'title' => 'Product Banner',
            'target_type' => 'product',
            'target_id' => $productId,
            'target_url' => '/products/'.$productId,
        ]);

        $this->actingAs($admin)
            ->get('/admin/b2c/content')
            ->assertOk()
            ->assertSee('Product · Banner Product')
            ->assertSee('Category · Banner Category')
            ->assertDontSee('name="target_url"', false);
    }

    public function test_arabic_pdf_export_uses_unicode_type0_font_instead_of_winansi_mojibake(): void
    {
        app()->setLocale('ar');
        $export = app(ReportExportService::class)->build([
            'report' => 'orders',
            'generated_at' => now()->toIso8601String(),
            'filters' => ['from' => '2026-09-01', 'to' => '2026-09-30'],
            'kpis' => ['orders' => 1],
            'columns' => ['order_number', 'customer_name'],
            'rows' => [[
                'order_number' => 'ORD-1',
                'customer_name' => 'وليد عطية',
            ]],
        ], 'pdf', 'ar');

        $this->assertSame('application/pdf', $export['mime']);
        $this->assertStringStartsWith('%PDF-', $export['content']);
        $this->assertStringContainsString('/Subtype /Type0', $export['content']);
        $this->assertStringContainsString('dejavusans', strtolower($export['content']));
        $this->assertGreaterThan(10000, strlen($export['content']));
    }

    private function retailStore(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-retail-hotfix@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Retail Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $user->roles()->attach($role);
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
