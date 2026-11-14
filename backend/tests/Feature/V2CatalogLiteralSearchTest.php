<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\CatalogOwnership;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2CatalogLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_product_and_category_searches_treat_wildcards_as_literal_with_bilingual_store_scope(): void
    {
        $storeA = $this->store('V2-CAT-LITERAL-A');
        $storeB = $this->store('V2-CAT-LITERAL-B');
        $manager = $this->retailManager($storeA);
        $catalogA = (int) app(CatalogOwnership::class)->defaultCatalogForStore($storeA)->id;
        $catalogB = (int) app(CatalogOwnership::class)->defaultCatalogForStore($storeB)->id;
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'V2-CAT-LIT-UNIT',
            'name' => 'Literal Each',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catAr = $this->category($catalogA, 'category-ar', 'تصنيف %_! العربية');
        $catEn = $this->category($catalogA, 'category-en', 'Category %_! English');
        $catBang = $this->category($catalogA, 'category-bang', 'Flag!_Category');
        $this->category($catalogA, 'category-lookalike', 'CategoryXY! unrelated');
        $catInactive = $this->category($catalogA, 'category-inactive', 'Inactive %_! Group', false);
        $this->category($catalogB, 'category-foreign', 'Foreign %_! Group');

        $prodAr = $this->product($catalogA, $storeA, $unitId, 'LIT-SKU-AR', 'منتج %_! العربية');
        $prodEn = $this->product($catalogA, $storeA, $unitId, 'LIT-SKU-EN', 'Product %_! English');
        $prodBang = $this->product($catalogA, $storeA, $unitId, 'LIT-SKU-BANG', 'Flag!_Product');
        $prodSku = $this->product($catalogA, $storeA, $unitId, 'SKU_TEST', 'Ordinary product');
        $this->product($catalogA, $storeA, $unitId, 'LIT-SKU-LOOK', 'ProductXY! unrelated');
        $prodInactive = $this->product($catalogA, $storeA, $unitId, 'LIT-SKU-INACTIVE', 'Inactive %_! product', false);
        $this->product($catalogB, $storeB, $unitId, 'LIT-SKU-FOREIGN', 'Foreign %_! Product');

        $productsBefore = DB::table('products')->count();
        $categoriesBefore = DB::table('categories')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach ([
            ['locale' => 'ar', 'q' => '%_!', 'status' => 'active', 'expected' => [$prodAr, $prodEn]],
            ['locale' => 'en', 'q' => '%_!', 'status' => 'inactive', 'expected' => [$prodInactive]],
            ['locale' => 'en', 'q' => '%', 'status' => 'active', 'expected' => [$prodAr, $prodEn]],
            ['locale' => 'ar', 'q' => '!_', 'status' => 'active', 'expected' => [$prodBang]],
            ['locale' => 'en', 'q' => 'SKU_', 'status' => 'active', 'expected' => [$prodSku]],
            ['locale' => 'ar', 'q' => 'العربية', 'status' => 'active', 'expected' => [$prodAr]],
            ['locale' => 'en', 'q' => 'English', 'status' => 'active', 'expected' => [$prodEn]],
            ['locale' => 'en', 'q' => 'absent%_!', 'status' => 'active', 'expected' => []],
        ] as $case) {
            $manager->forceFill(['locale' => $case['locale']])->save();
            app()->setLocale($case['locale']);

            $response = $this->actingAs($manager->fresh())->get(route('admin.catalog.index', [
                'tab' => 'products',
                'store_id' => $storeA,
                'product_q' => $case['q'],
                'product_status' => $case['status'],
            ]))->assertOk();

            $response->assertSee('<html lang="'.$case['locale'].'" dir="'.($case['locale'] === 'ar' ? 'rtl' : 'ltr').'">', false);
            $this->assertPageIds($response->viewData('products'), $case['expected']);
        }

        foreach ([
            ['locale' => 'ar', 'q' => '%_!', 'status' => 'active', 'expected' => [$catAr, $catEn]],
            ['locale' => 'en', 'q' => '%_!', 'status' => 'inactive', 'expected' => [$catInactive]],
            ['locale' => 'en', 'q' => '%', 'status' => 'active', 'expected' => [$catAr, $catEn]],
            ['locale' => 'ar', 'q' => '!_', 'status' => 'active', 'expected' => [$catBang]],
            ['locale' => 'ar', 'q' => 'العربية', 'status' => 'active', 'expected' => [$catAr]],
            ['locale' => 'en', 'q' => 'English', 'status' => 'active', 'expected' => [$catEn]],
            ['locale' => 'en', 'q' => 'absent%_!', 'status' => 'active', 'expected' => []],
        ] as $case) {
            $manager->forceFill(['locale' => $case['locale']])->save();
            app()->setLocale($case['locale']);

            $response = $this->actingAs($manager->fresh())->get(route('admin.catalog.index', [
                'tab' => 'categories',
                'store_id' => $storeA,
                'category_q' => $case['q'],
                'category_status' => $case['status'],
            ]))->assertOk();

            $response->assertSee('<html lang="'.$case['locale'].'" dir="'.($case['locale'] === 'ar' ? 'rtl' : 'ltr').'">', false);
            $this->assertPageIds($response->viewData('categoryRows'), $case['expected']);
        }

        $this->actingAs($manager->fresh())
            ->get(route('admin.catalog.index', ['tab' => 'categories', 'store_id' => $storeB, 'category_q' => '%_!']))
            ->assertForbidden();
        $this->actingAs($manager->fresh())
            ->get(route('admin.catalog.index', ['tab' => 'products', 'store_id' => $storeB, 'product_q' => '%_!']))
            ->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.catalog.index', ['tab' => 'categories', 'store_id' => $storeA, 'category_q' => '%_!']))
            ->assertForbidden();

        $this->assertSame($productsBefore, DB::table('products')->count());
        $this->assertSame($categoriesBefore, DB::table('categories')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_category_literal_search_retains_pagination_and_scoped_query(): void
    {
        $store = $this->store('V2-CAT-LITERAL-PAGE');
        $actor = $this->retailManager($store);
        $catalog = (int) app(CatalogOwnership::class)->defaultCatalogForStore($store)->id;

        for ($index = 0; $index < 26; $index++) {
            $this->category($catalog, 'batch-literal-'.(string) $index, 'Batch! category '.(string) $index);
        }
        $this->category($catalog, 'batch-lookalike', 'BatchX category');

        $filters = ['tab' => 'categories', 'store_id' => $store, 'category_q' => 'Batch!', 'category_status' => 'active'];
        $first = $this->actingAs($actor)
            ->get(route('admin.catalog.index', $filters))
            ->assertOk()->viewData('categoryRows');

        $this->assertSame(26, $first->total());
        $this->assertCount(25, $first->items());

        parse_str((string) parse_url((string) $first->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('Batch!', $query['category_q'] ?? null);
        $this->assertSame((string) $store, $query['store_id'] ?? null);
        $this->assertSame('active', $query['category_status'] ?? null);

        $second = $this->actingAs($actor)
            ->get(route('admin.catalog.index', [...$filters, 'category_page' => 2]))
            ->assertOk()->viewData('categoryRows');
        $this->assertSame(26, $second->total());
        $this->assertCount(1, $second->items());
    }

    /** @param list<int> $expected */
    private function assertPageIds($page, array $expected): void
    {
        $actual = $page->getCollection()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailManager(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function category(int $catalogId, string $slug, string $name, bool $active = true): int
    {
        return (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => $name,
            'slug' => $slug,
            'parent_id' => null,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $catalogId, int $storeId, int $unitId, string $sku, string $name, bool $active = true): int
    {
        $id = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $name,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $id,
            'price' => 5,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
