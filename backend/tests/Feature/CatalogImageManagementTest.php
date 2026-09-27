<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\CatalogImageService;
use App\Services\CatalogOwnership;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogImageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_admin_can_upload_category_and_product_images_and_mobile_api_returns_them(): void
    {
        $storeId = $this->store('B2C', 'IMG-STORE');
        $admin = $this->globalAdmin('SUPER_ADMIN', 'images@example.test');
        $unitId = (int) DB::table('units')->where('is_active', true)->value('id');

        $this->actingAs($admin)->post(route('admin.catalog.categories.store'), [
            'support_access' => 1,
            'store_id' => $storeId,
            'name' => 'Fresh Food',
            'slug' => 'fresh-food',
            'is_active' => 1,
            'category_image' => UploadedFile::fake()->image('category.jpg', 400, 400),
        ])->assertRedirect();

        $category = DB::table('categories')->where('slug', 'fresh-food')->first();
        $this->assertNotNull($category);
        $this->assertStringStartsWith('storage/catalog/categories/', (string) $category->image_path);

        $this->actingAs($admin)->post(route('admin.catalog.products.store'), [
            'support_access' => 1,
            'store_id' => $storeId,
            'sku' => 'IMG-001',
            'name' => 'Image Product',
            'description' => 'Product with gallery',
            'category_id' => $category->id,
            'unit_id' => $unitId,
            'price' => 3.250,
            'is_active' => 1,
            'images' => [
                UploadedFile::fake()->image('one.jpg', 600, 600),
                UploadedFile::fake()->image('two.png', 600, 600),
            ],
        ])->assertRedirect();

        $product = DB::table('products')->where('sku', 'IMG-001')->first();
        $this->assertNotNull($product);
        $this->assertSame(2, DB::table('product_images')->where('product_id', $product->id)->count());
        $this->assertSame(1, DB::table('product_images')->where('product_id', $product->id)->where('is_primary', true)->count());

        $categoryResponse = $this->getJson("/api/v1/stores/{$storeId}/categories")
            ->assertOk()
            ->json('data.0.image_url');
        $this->assertIsString($categoryResponse);
        $this->assertStringContainsString('/storage/catalog/categories/', $categoryResponse);

        $productSummary = $this->getJson("/api/v1/stores/{$storeId}/products")
            ->assertOk()
            ->json('data.0.image_url');
        $this->assertIsString($productSummary);
        $this->assertStringContainsString('/storage/catalog/products/', $productSummary);

        $detail = $this->getJson("/api/v1/products/{$product->id}?store={$storeId}")
            ->assertOk()
            ->json('images');
        $this->assertCount(2, $detail);
        $this->assertStringContainsString('/storage/catalog/products/', $detail[0]);
    }

    public function test_product_gallery_primary_order_and_delete_are_managed_inside_owner_tenant(): void
    {
        $storeId = $this->store('B2C', 'IMG-MANAGE');
        $admin = $this->globalAdmin('SUPER_ADMIN', 'gallery@example.test');
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId, 'b2c');
        $unitId = (int) DB::table('units')->where('is_active', true)->value('id');

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => 'GALLERY-1',
            'name' => 'Gallery Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CatalogImageService::class)->addProductImages($productId, [
            UploadedFile::fake()->image('first.jpg', 200, 200),
            UploadedFile::fake()->image('second.jpg', 200, 200),
        ]);

        $images = DB::table('product_images')->where('product_id', $productId)->orderBy('id')->get();
        $first = $images[0];
        $second = $images[1];

        $this->actingAs($admin)->patch(route('admin.catalog.products.images.update', [$productId, $second->id]), [
            'support_access' => 1,
            'sort_order' => 0,
            'is_primary' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('product_images', [
            'id' => $second->id,
            'product_id' => $productId,
            'sort_order' => 0,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('product_images', [
            'id' => $first->id,
            'product_id' => $productId,
            'is_primary' => false,
        ]);

        $this->actingAs($admin)->delete(route('admin.catalog.products.images.destroy', [$productId, $second->id]), [
            'support_access' => 1,
        ])->assertRedirect();

        $this->assertDatabaseMissing('product_images', ['id' => $second->id]);
        $this->assertDatabaseHas('product_images', [
            'id' => $first->id,
            'product_id' => $productId,
            'is_primary' => true,
        ]);
    }

    public function test_store_admin_cannot_mutate_foreign_store_product_images(): void
    {
        $storeA = $this->store('B2C', 'IMG-A');
        $storeB = $this->store('B2C', 'IMG-B');
        $catalogA = app(CatalogOwnership::class)->defaultCatalogForStore($storeA, 'b2c');
        app(CatalogOwnership::class)->defaultCatalogForStore($storeB, 'b2c');
        $unitId = (int) DB::table('units')->where('is_active', true)->value('id');

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogA->id,
            'unit_id' => $unitId,
            'sku' => 'FOREIGN-IMAGE',
            'name' => 'Foreign Image Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(CatalogImageService::class)->addProductImages($productId, [
            UploadedFile::fake()->image('foreign.jpg', 200, 200),
        ]);
        $imageId = (int) DB::table('product_images')->where('product_id', $productId)->value('id');

        $manager = $this->storeAdmin($storeB, 'foreign-image@example.test');

        $response = $this->actingAs($manager)->patch(
            route('admin.catalog.products.images.update', [$productId, $imageId]),
            ['sort_order' => 5, 'is_primary' => 1],
        );

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertDatabaseHas('product_images', [
            'id' => $imageId,
            'product_id' => $productId,
            'sort_order' => 1,
        ]);
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

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Store Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
