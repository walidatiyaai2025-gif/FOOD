<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LookupManagementCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_lookup_schema_is_scoped_and_legacy_compatible(): void
    {
        $this->assertTrue(Schema::hasColumns('brands', ['scope', 'scope_key', 'store_id', 'name_ar', 'name_en', 'image_path', 'is_active']));
        $this->assertTrue(Schema::hasColumns('units', ['scope', 'scope_key', 'store_id', 'name_ar', 'name_en', 'is_active']));

        $brandIndexes = array_column(Schema::getIndexes('brands'), 'name');
        $unitIndexes = array_column(Schema::getIndexes('units'), 'name');

        $this->assertContains('brands_scope_slug_unique', $brandIndexes);
        $this->assertContains('units_scope_code_unique', $unitIndexes);
    }

    public function test_b2c_store_admin_sees_only_selected_assigned_store_lookups(): void
    {
        $storeA = $this->store('B2C', 'LOOK-A');
        $storeB = $this->store('B2C', 'LOOK-B');
        $admin = $this->storeAdmin($storeA, 'lookup-a@example.test');

        $this->brand('global-visible', 'Global Visible', 'global', null);
        $this->brand('b2b-hidden', 'B2B Hidden', 'b2b', null);
        $this->brand('store-a-visible', 'Store A Visible', 'store', $storeA);
        $this->brand('store-b-hidden', 'Store B Hidden', 'store', $storeB);

        $this->actingAs($admin)->get('/admin/lookups?type=brands')
            ->assertOk()
            ->assertDontSee('Global Visible')
            ->assertSee('Store A Visible')
            ->assertDontSee('B2B Hidden')
            ->assertDontSee('Store B Hidden');
    }

    public function test_b2b_admin_sees_global_and_wholesale_but_not_retail_store_values(): void
    {
        $store = $this->store('B2C', 'LOOK-RETAIL');
        $admin = $this->globalRole('B2B_ADMIN', 'lookup-b2b@example.test');

        $this->brand('global-b2b-visible', 'Global Visible', 'global', null);
        $this->brand('wholesale-visible', 'Wholesale Visible', 'b2b', null);
        $this->brand('retail-hidden', 'Retail Hidden', 'store', $store);

        $this->actingAs($admin)->get('/admin/lookups?type=brands')
            ->assertOk()
            ->assertSee('Global Visible')
            ->assertSee('Wholesale Visible')
            ->assertDontSee('Retail Hidden');
    }

    public function test_store_scoped_duplicate_is_blocked_but_same_slug_is_allowed_in_another_store(): void
    {
        $storeA = $this->store('B2C', 'DUP-A');
        $storeB = $this->store('B2C', 'DUP-B');
        $adminA = $this->storeAdmin($storeA, 'dup-a@example.test');
        $adminB = $this->storeAdmin($storeB, 'dup-b@example.test');

        Storage::fake('public');
        $payloadA = [
            'scope' => 'store',
            'store_id' => $storeA,
            'name_ar' => 'علامة',
            'name_en' => 'Brand',
            'slug' => 'same-brand',
            'brand_image' => UploadedFile::fake()->image('brand-a.png', 512, 512)->size(100),
            'is_active' => 1,
        ];

        $this->actingAs($adminA)->post('/admin/lookups/brands', $payloadA)->assertSessionHasNoErrors();
        $this->actingAs($adminA)->post('/admin/lookups/brands', [
            ...$payloadA,
            'brand_image' => UploadedFile::fake()->image('brand-a-duplicate.png', 512, 512)->size(100),
        ])->assertSessionHasErrors('slug');

        $this->actingAs($adminB)->post('/admin/lookups/brands', [
            ...$payloadA,
            'store_id' => $storeB,
            'brand_image' => UploadedFile::fake()->image('brand-b.png', 512, 512)->size(100),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, DB::table('brands')->where('slug', 'same-brand')->count());
    }

    public function test_store_admin_cannot_create_lookup_for_foreign_store_or_b2b_scope(): void
    {
        $storeA = $this->store('B2C', 'AUTH-A');
        $storeB = $this->store('B2C', 'AUTH-B');
        $admin = $this->storeAdmin($storeA, 'auth-a@example.test');

        $this->actingAs($admin)->post('/admin/lookups/units', [
            'scope' => 'store',
            'store_id' => $storeB,
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'code' => 'PC-X',
            'decimal_places' => 0,
            'is_active' => 1,
        ])->assertNotFound();

        $this->actingAs($admin)->post('/admin/lookups/units', [
            'scope' => 'b2b',
            'name_ar' => 'كرتون',
            'name_en' => 'Carton',
            'code' => 'CT-X',
            'decimal_places' => 0,
            'is_active' => 1,
        ])->assertForbidden();
    }

    public function test_b2b_admin_manages_wholesale_scope_but_not_platform_global_scope(): void
    {
        $admin = $this->globalRole('B2B_ADMIN', 'b2b-manage@example.test');

        $this->actingAs($admin)->post('/admin/lookups/units', [
            'scope' => 'b2b',
            'name_ar' => 'كرتون',
            'name_en' => 'Carton',
            'code' => 'CT-B2B',
            'decimal_places' => 0,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('units', ['code' => 'CT-B2B', 'scope' => 'b2b', 'scope_key' => 'b2b']);

        $this->actingAs($admin)->post('/admin/lookups/units', [
            'scope' => 'global',
            'name_ar' => 'حبة',
            'name_en' => 'Each',
            'code' => 'EA-G',
            'decimal_places' => 0,
            'is_active' => 1,
        ])->assertForbidden();
    }

    public function test_referenced_unit_cannot_be_deleted_and_can_be_deactivated(): void
    {
        $admin = $this->globalRole('SUPER_ADMIN', 'owner-lookups@example.test');
        $unitId = (int) DB::table('units')->insertGetId([
            'scope' => 'global',
            'scope_key' => 'global',
            'store_id' => null,
            'code' => 'SAFE',
            'name' => 'Safe Unit',
            'name_ar' => 'وحدة آمنة',
            'name_en' => 'Safe Unit',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storeId = $this->store('B2B', 'LOOKUP-SAFE-B2B');
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'default',
            'name' => 'Safe Lookup Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'catalog_id' => $catalogId,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => $unitId,
            'sku' => 'LOOKUP-SAFE-PRODUCT',
            'name' => 'Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->delete('/admin/lookups/units/'.$unitId)
            ->assertSessionHasErrors('lookup');
        $this->assertDatabaseHas('units', ['id' => $unitId]);

        $this->actingAs($admin)->patch('/admin/lookups/units/'.$unitId.'/status')
            ->assertRedirect();

        $this->assertDatabaseHas('units', ['id' => $unitId, 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'lookup.unit.status_changed', 'auditable_id' => $unitId]);
    }

    public function test_super_admin_store_scope_requires_explicit_support_access_and_is_audited(): void
    {
        $store = $this->store('B2C', 'SUPPORT-LOOKUP');
        $admin = $this->globalRole('SUPER_ADMIN', 'support-lookups@example.test');
        Storage::fake('public');
        $payload = [
            'scope' => 'store',
            'store_id' => $store,
            'name_ar' => 'محلي',
            'name_en' => 'Local',
            'slug' => 'local-brand',
            'brand_image' => UploadedFile::fake()->image('local-brand.png', 512, 512)->size(100),
            'is_active' => 1,
        ];

        $this->actingAs($admin)->post('/admin/lookups/brands', $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('support_access');

        $this->actingAs($admin)->post('/admin/lookups/brands', [
            ...$payload,
            'brand_image' => UploadedFile::fake()->image('local-brand-support.png', 512, 512)->size(100),
            'support_access' => 1,
        ])->assertSessionHasNoErrors();

        $brandId = (int) DB::table('brands')->where('slug', 'local-brand')->value('id');
        $this->assertDatabaseHas('brands', [
            'id' => $brandId,
            'store_id' => $store,
            'scope' => 'store',
            'scope_key' => 'store:'.$store,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'tenant.support_access.entered', 'user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'lookup.brand.created', 'auditable_id' => $brandId]);
    }

    public function test_super_admin_store_lookup_mutations_return_support_validation_instead_of_raw_403(): void
    {
        $store = $this->store('B2C', 'SUPPORT-ACTIONS');
        $admin = $this->globalRole('SUPER_ADMIN', 'support-actions@example.test');
        $brandId = $this->brand('support-actions-brand', 'Support Actions Brand', 'store', $store);

        $this->actingAs($admin)->patch('/admin/lookups/brands/'.$brandId, [
            'scope' => 'store',
            'store_id' => $store,
            'name_ar' => 'علامة دعم',
            'name_en' => 'Support Brand',
            'slug' => 'support-actions-brand',
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasErrors('support_access');

        $this->actingAs($admin)->patch('/admin/lookups/brands/'.$brandId.'/status')
            ->assertRedirect()
            ->assertSessionHasErrors('support_access');

        $this->actingAs($admin)->delete('/admin/lookups/brands/'.$brandId)
            ->assertRedirect()
            ->assertSessionHasErrors('support_access');

        $this->assertDatabaseHas('brands', ['id' => $brandId, 'is_active' => true]);
    }

    public function test_cross_channel_lookup_id_tampering_is_rejected_server_side(): void
    {
        $wholesaleStore = $this->store('B2B', 'LOOKUP-PRODUCT-B2B');
        $retailStore = $this->store('B2C', 'LOOKUP-PRODUCT-RETAIL');
        DB::table('catalogs')->insert([
            'store_id' => $wholesaleStore,
            'channel' => 'b2b',
            'code' => 'default',
            'name' => 'Wholesale Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->globalRole('B2B_ADMIN', 'lookup-product-b2b@example.test');
        $foreignUnitId = (int) DB::table('units')->insertGetId([
            'store_id' => $retailStore,
            'scope' => 'store',
            'scope_key' => 'store:'.$retailStore,
            'code' => 'FOREIGN-PC',
            'name' => 'Foreign Piece',
            'name_ar' => 'قطعة أجنبية',
            'name_en' => 'Foreign Piece',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/catalog/products', [
            'store_id' => $wholesaleStore,
            'sku' => 'LOOKUP-TAMPER-001',
            'name' => 'Tampered Product',
            'unit_id' => $foreignUnitId,
            'is_active' => 1,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('products', ['sku' => 'LOOKUP-TAMPER-001']);
    }

    public function test_brand_requires_valid_image_and_grid_renders_thumbnail(): void
    {
        Storage::fake('public');
        $admin = $this->globalRole('B2B_ADMIN', 'brand-image@example.test');

        $this->actingAs($admin)->post('/admin/lookups/brands', [
            'scope' => 'b2b',
            'name_ar' => 'علامة بدون صورة',
            'name_en' => 'Missing Image',
            'slug' => 'missing-image',
            'is_active' => 1,
        ])->assertSessionHasErrors('brand_image');

        $this->actingAs($admin)->post('/admin/lookups/brands', [
            'scope' => 'b2b',
            'name_ar' => 'علامة صغيرة',
            'name_en' => 'Too Small',
            'slug' => 'too-small',
            'brand_image' => UploadedFile::fake()->image('small.png', 128, 128)->size(50),
            'is_active' => 1,
        ])->assertSessionHasErrors('brand_image');

        $this->actingAs($admin)->post('/admin/lookups/brands', [
            'scope' => 'b2b',
            'name_ar' => 'علامة بصورة',
            'name_en' => 'Brand With Image',
            'slug' => 'brand-with-image',
            'brand_image' => UploadedFile::fake()->image('brand.png', 512, 512)->size(100),
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $brand = DB::table('brands')->where('slug', 'brand-with-image')->first();
        $this->assertNotNull($brand);
        $this->assertNotNull($brand->image_path);
        Storage::disk('public')->assertExists(substr((string) $brand->image_path, strlen('storage/')));

        $this->actingAs($admin)->get('/admin/lookups?type=brands')
            ->assertOk()
            ->assertSee('brand-thumb', false)
            ->assertSee((string) $brand->image_path);
    }

    public function test_lookup_center_renders_arabic_rtl_and_english_ltr(): void
    {
        $ar = $this->globalRole('SUPER_ADMIN', 'lookup-ar@example.test', 'ar');
        $this->actingAs($ar)->get('/admin/lookups')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('العلامات والوحدات');

        $en = $this->globalRole('SUPER_ADMIN', 'lookup-en@example.test', 'en');
        $this->actingAs($en)->get('/admin/lookups')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Brands & Units');
    }

    private function store(string $type, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
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

    private function globalRole(string $roleCode, string $email, string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function brand(string $slug, string $name, string $scope, ?int $storeId): int
    {
        return (int) DB::table('brands')->insertGetId([
            'store_id' => $storeId,
            'scope' => $scope,
            'scope_key' => $scope === 'store' ? 'store:'.$storeId : $scope,
            'name' => $name,
            'name_ar' => $name,
            'name_en' => $name,
            'slug' => $slug,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
