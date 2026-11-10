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

class V2CatalogImageContentAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_retail_catalog_image_and_content_controls_are_scoped_in_both_locales(): void
    {
        $storeA = $this->store('V2-IMAGE-CONTENT-A', 'B2C');
        $storeB = $this->store('V2-IMAGE-CONTENT-B', 'B2C');
        $own = $this->catalogFixture($storeA, 'Own');
        $foreign = $this->catalogFixture($storeB, 'Foreign');
        $manager = $this->storeManager($storeA, 'v2-content-reader@example.test');

        foreach (['en', 'ar'] as $locale) {
            $manager->forceFill(['locale' => $locale])->save();

            $this->actingAs($manager)
                ->get(route('admin.catalog.index', ['tab' => 'products', 'store_id' => $storeA]))
                ->assertOk()
                ->assertSee($own['product_name'])
                ->assertSee($own['product_image'])
                ->assertSee('data-grid-image-form', false)
                ->assertSee($locale === 'ar' ? 'إدارة الصور' : 'Manage images')
                ->assertDontSee($foreign['product_name']);

            $this->actingAs($manager)
                ->get(route('admin.catalog.index', ['tab' => 'categories', 'store_id' => $storeA]))
                ->assertOk()
                ->assertSee($own['category_name'])
                ->assertSee($own['category_image'])
                ->assertSee('catalog-categories-table', false)
                ->assertDontSee($foreign['category_name']);
        }
    }

    public function test_image_and_content_mutations_reject_foreign_store_records_without_changing_files(): void
    {
        $storeA = $this->store('V2-IMAGE-MUTATE-A', 'B2C');
        $storeB = $this->store('V2-IMAGE-MUTATE-B', 'B2C');
        $own = $this->catalogFixture($storeA, 'Owned');
        $foreign = $this->catalogFixture($storeB, 'Foreign');
        $manager = $this->storeManager($storeA, 'v2-content-editor@example.test');

        $this->actingAs($manager)
            ->patch(route('admin.catalog.products.grid-image', $own['product_id']), [
                'image' => UploadedFile::fake()->image('allowed-product.png', 500, 500),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)
            ->patch(route('admin.catalog.categories.grid-image', $own['category_id']), [
                'image' => UploadedFile::fake()->image('allowed-category.png', 500, 500),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotSame(
            $own['category_image'],
            (string) DB::table('categories')->where('id', $own['category_id'])->value('image_path'),
        );
        $this->assertNotSame(
            $own['product_image'],
            (string) DB::table('product_images')->where('product_id', $own['product_id'])->where('is_primary', true)->value('path'),
        );

        $this->actingAs($manager)
            ->patch(route('admin.catalog.products.update', $own['product_id']), [
                'name' => $own['product_name'],
                'description' => 'Updated authorized product content',
                'unit_id' => $own['unit_id'],
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $own['product_id'],
            'description' => 'Updated authorized product content',
        ]);

        $productDeny = $this->actingAs($manager)
            ->patch(route('admin.catalog.products.grid-image', $foreign['product_id']), [
                'image' => UploadedFile::fake()->image('forbidden-product.png', 500, 500),
                'support_access' => 1,
            ]);
        $this->assertContains($productDeny->getStatusCode(), [403, 404]);

        $categoryDeny = $this->actingAs($manager)
            ->patch(route('admin.catalog.categories.grid-image', $foreign['category_id']), [
                'image' => UploadedFile::fake()->image('forbidden-category.png', 500, 500),
                'support_access' => 1,
            ]);
        $this->assertContains($categoryDeny->getStatusCode(), [403, 404]);

        $contentDeny = $this->actingAs($manager)
            ->patch(route('admin.catalog.products.update', $foreign['product_id']), [
                'name' => 'Forbidden content override',
                'description' => 'Should not persist',
                'unit_id' => $foreign['unit_id'],
                'is_active' => 1,
                'support_access' => 1,
            ]);
        $this->assertContains($contentDeny->getStatusCode(), [403, 404]);

        $this->assertDatabaseHas('products', [
            'id' => $foreign['product_id'],
            'name' => $foreign['product_name'],
            'description' => $foreign['description'],
        ]);
        $this->assertDatabaseHas('categories', [
            'id' => $foreign['category_id'],
            'image_path' => $foreign['category_image'],
        ]);
        $this->assertDatabaseHas('product_images', [
            'product_id' => $foreign['product_id'],
            'path' => $foreign['product_image'],
        ]);
        Storage::disk('public')->assertExists(substr($foreign['category_image'], strlen('storage/')));
        Storage::disk('public')->assertExists(substr($foreign['product_image'], strlen('storage/')));
    }

    public function test_unprivileged_and_cross_channel_personas_cannot_access_foreign_catalog_content(): void
    {
        $retail = $this->store('V2-IMAGE-RETAIL', 'B2C');
        $wholesale = $this->store('V2-IMAGE-WHOLESALE', 'B2B');
        $retailFixture = $this->catalogFixture($retail, 'Retail');
        $wholesaleFixture = $this->catalogFixture($wholesale, 'Wholesale');
        $manager = $this->storeManager($retail, 'v2-content-inactive@example.test');
        $b2bAdmin = $this->globalUser('B2B_ADMIN', 'v2-content-wholesale@example.test');
        $unprivileged = $this->user('v2-content-unprivileged@example.test');

        $unprivilegedResponse = $this->actingAs($unprivileged)->get(
            route('admin.catalog.index', ['tab' => 'products', 'store_id' => $retail]),
        );
        $this->assertContains($unprivilegedResponse->getStatusCode(), [403, 404]);

        $wholesaleResponse = $this->actingAs($b2bAdmin)->get(
            route('admin.catalog.index', ['tab' => 'products', 'store_id' => $retail]),
        );
        $this->assertContains($wholesaleResponse->getStatusCode(), [403, 404]);

        $wholesaleMutation = $this->actingAs($b2bAdmin)
            ->patch(route('admin.catalog.products.grid-image', $retailFixture['product_id']), [
                'image' => UploadedFile::fake()->image('b2b-denied.png'),
            ]);
        $this->assertContains($wholesaleMutation->getStatusCode(), [403, 404]);

        $retailMutation = $this->actingAs($manager)
            ->patch(route('admin.catalog.products.grid-image', $wholesaleFixture['product_id']), [
                'image' => UploadedFile::fake()->image('b2c-denied.png'),
            ]);
        $this->assertContains($retailMutation->getStatusCode(), [403, 404]);

        $this->assertDatabaseHas('product_images', [
            'product_id' => $retailFixture['product_id'],
            'path' => $retailFixture['product_image'],
        ]);
        $this->assertDatabaseHas('product_images', [
            'product_id' => $wholesaleFixture['product_id'],
            'path' => $wholesaleFixture['product_image'],
        ]);
    }

    /** @return array{product_id:int,category_id:int,unit_id:int,product_name:string,category_name:string,description:string,product_image:string,category_image:string} */
    private function catalogFixture(int $storeId, string $label): array
    {
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId);
        $unitId = (int) DB::table('units')->where('is_active', true)->value('id');
        $categoryName = 'V2 '.$label.' category';
        $productName = 'V2 '.$label.' product';
        $description = 'V2 '.$label.' description';

        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalog->id,
            'name' => $categoryName,
            'slug' => 'v2-image-'.strtolower($label).'-'.$storeId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'V2-IMG-'.$storeId,
            'name' => $productName,
            'description' => $description,
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

        $images = app(CatalogImageService::class);
        $categoryImage = $images->replaceCategoryImage(
            $categoryId,
            UploadedFile::fake()->image('category-'.$storeId.'.png', 400, 400),
        );
        $images->addProductImages($productId, [
            UploadedFile::fake()->image('product-'.$storeId.'.png', 400, 400),
        ]);
        $productImage = (string) DB::table('product_images')
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->value('path');

        return [
            'product_id' => $productId,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'product_name' => $productName,
            'category_name' => $categoryName,
            'description' => $description,
            'product_image' => $productImage,
            'category_image' => $categoryImage,
        ];
    }

    private function store(string $code, string $type): int
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

    private function storeManager(int $storeId, string $email): User
    {
        $user = $this->user($email);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = $this->user($email);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }
}
