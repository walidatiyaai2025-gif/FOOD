<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogZipImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_sample_package_previews_then_imports_idempotently_with_images(): void
    {
        $storeId = $this->store('ZIP-STORE');
        $admin = $this->superAdmin();
        $this->globalUnit('PCS');

        $sample = $this->actingAs($admin)->get(route('admin.catalog.import.sample', [
            'store_id' => $storeId,
            'support_access' => 1,
        ]));
        $sample->assertOk()->assertHeader('content-type', 'application/zip');
        $package = $sample->getContent();
        $this->assertIsString($package);
        $this->assertStringStartsWith("PK", $package);

        $previewResponse = $this->actingAs($admin)->post(route('admin.catalog.import.preview'), [
            'support_access' => 1,
            'store_id' => $storeId,
            'catalog_zip' => UploadedFile::fake()->createWithContent('catalog.zip', $package),
        ]);
        $previewResponse->assertRedirect()->assertSessionHas('catalog_import_preview');
        $preview = $this->app['session']->get('catalog_import_preview');

        $this->assertIsArray($preview);
        $this->assertSame([], $preview['errors']);
        $this->assertNotEmpty($preview['token']);
        $this->assertSame(1, $preview['counts']['products']);
        $this->assertSame(1, $preview['counts']['categories']);
        $this->assertSame(1, $preview['counts']['brands']);
        $this->assertSame(3, $preview['counts']['images']);
        $this->assertDatabaseMissing('products', ['sku' => 'PROD-001']);

        $commitResponse = $this->actingAs($admin)->post(route('admin.catalog.import.commit'), [
            'support_access' => 1,
            'preview_token' => $preview['token'],
        ]);
        $commitResponse->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('catalog_import_result');

        $catalogId = (int) DB::table('catalogs')->where('store_id', $storeId)->where('code', 'default')->value('id');
        $categoryId = (int) DB::table('categories')->where('catalog_id', $catalogId)->where('slug', 'cat-001')->value('id');
        $brandId = (int) DB::table('brands')->where('scope_key', 'store:'.$storeId)->where('slug', 'brand-001')->value('id');
        $productId = (int) DB::table('products')->where('catalog_id', $catalogId)->where('sku', 'PROD-001')->value('id');

        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $brandId);
        $this->assertGreaterThan(0, $productId);
        $this->assertDatabaseHas('store_products', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'is_active' => true,
        ]);
        $this->assertStringContainsString('storage/catalog/categories/'.$categoryId.'/import-', (string) DB::table('categories')->where('id', $categoryId)->value('image_path'));
        $this->assertStringContainsString('storage/catalog/brands/'.$brandId.'/import-', (string) DB::table('brands')->where('id', $brandId)->value('image_path'));
        $this->assertSame(1, DB::table('product_images')->where('product_id', $productId)->count());
        $productImage = (string) DB::table('product_images')->where('product_id', $productId)->value('path');
        $this->assertStringContainsString('storage/catalog/products/'.$productId.'/import-', $productImage);
        $this->assertTrue(Storage::disk('public')->exists(substr($productImage, strlen('storage/'))));

        $secondPreviewResponse = $this->actingAs($admin)->post(route('admin.catalog.import.preview'), [
            'support_access' => 1,
            'store_id' => $storeId,
            'catalog_zip' => UploadedFile::fake()->createWithContent('catalog.zip', $package),
        ]);
        $secondPreviewResponse->assertRedirect()->assertSessionHas('catalog_import_preview');
        $secondPreview = $this->app['session']->get('catalog_import_preview');
        $this->assertSame([], $secondPreview['errors']);

        $secondCommitResponse = $this->actingAs($admin)->post(route('admin.catalog.import.commit'), [
            'support_access' => 1,
            'preview_token' => $secondPreview['token'],
        ]);
        $secondCommitResponse->assertRedirect()->assertSessionHasNoErrors();
        $result = $this->app['session']->get('catalog_import_result');

        $this->assertSame(0, $result['counts']['products_created']);
        $this->assertSame(1, $result['counts']['products_updated']);
        $this->assertSame(0, $result['counts']['categories_created']);
        $this->assertSame(1, $result['counts']['categories_updated']);
        $this->assertSame(0, $result['counts']['brands_created']);
        $this->assertSame(1, $result['counts']['brands_updated']);
        $this->assertSame(1, DB::table('products')->where('catalog_id', $catalogId)->where('sku', 'PROD-001')->count());
        $this->assertSame(1, DB::table('categories')->where('catalog_id', $catalogId)->where('slug', 'cat-001')->count());
        $this->assertSame(1, DB::table('brands')->where('scope_key', 'store:'.$storeId)->where('slug', 'brand-001')->count());
        $this->assertSame(1, DB::table('product_images')->where('product_id', $productId)->count());
    }

    public function test_preview_rejects_zip_path_traversal_before_any_catalog_mutation(): void
    {
        $storeId = $this->store('ZIP-TRAVERSAL');
        $admin = $this->superAdmin();
        $this->globalUnit('PCS');

        $sample = $this->actingAs($admin)->get(route('admin.catalog.import.sample', [
            'store_id' => $storeId,
            'support_access' => 1,
        ]))->getContent();

        $malicious = str_replace('catalog.xlsx', '../evil.xlsx', $sample);
        $this->assertSame(strlen($sample), strlen($malicious));

        $response = $this->actingAs($admin)->post(route('admin.catalog.import.preview'), [
            'support_access' => 1,
            'store_id' => $storeId,
            'catalog_zip' => UploadedFile::fake()->createWithContent('catalog.zip', $malicious),
        ]);

        $response->assertRedirect()->assertSessionHas('catalog_import_preview');
        $preview = $this->app['session']->get('catalog_import_preview');
        $this->assertIsArray($preview);
        $this->assertNull($preview['token']);
        $this->assertNotEmpty($preview['errors']);
        $this->assertStringContainsString('Unsafe ZIP path traversal', implode("\n", $preview['errors']));
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('categories')->count());
        $this->assertSame(0, DB::table('brands')->count());
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
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
            'name' => 'Catalog ZIP Admin',
            'email' => 'catalog-zip@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function globalUnit(string $code): void
    {
        DB::table('units')->insert([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => $code,
            'name' => 'Piece',
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
