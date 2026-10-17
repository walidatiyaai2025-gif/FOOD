<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingMenuReadService;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionService;
use App\Domain\SmartOrdering\SmartOrderingMenuWriteService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmartOrderingPublicMenuRevisionContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_public_reads_expose_stable_published_revision_metadata_without_draft_leakage(): void
    {
        $fixture = $this->fixture('REV', 1);
        $branchA = $this->branch($fixture['site']->id, 'A', true);
        $branchB = $this->branch($fixture['site']->id, 'B');
        $writer = app(SmartOrderingMenuWriteService::class);
        $revisions = app(SmartOrderingMenuRevisionService::class);

        $draft = $revisions->draft($fixture['admin'], $fixture['site']);
        $menu = $writer->createMenu($fixture['admin'], $fixture['site'], $draft, [
            'name_en' => 'Published Menu',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
        ]);
        $category = $writer->createCategory($fixture['admin'], $fixture['site'], $draft, $menu, [
            'name_en' => 'Meals',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
        ]);
        $item = $writer->createItem($fixture['admin'], $fixture['site'], $draft, $category, [
            'source_product_id' => $fixture['product_ids'][0],
            'name_en' => 'Burger V1',
            'is_visible' => true,
            'sold_out' => false,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 0,
        ]);

        $publishedV1 = $revisions->publish($fixture['admin'], $fixture['site'], $draft);
        $this->enablePublicMenu($fixture);

        $base = '/api/v1/smart-ordering/'.$fixture['site']->slug;
        $queryAEn = '?branch='.$branchA->public_id.'&locale=en';

        $menuResponse = $this->getJson($base.'/menu'.$queryAEn)
            ->assertOk()
            ->assertJsonPath('meta.revision.id', (string) $publishedV1->public_id)
            ->assertJsonPath('meta.revision.checksum', (string) $publishedV1->checksum)
            ->assertJsonPath('meta.branch_id', (string) $branchA->public_id)
            ->assertJsonPath('meta.locale', 'en')
            ->assertJsonPath('data.menus.0.categories.0.items.0.name', 'Burger V1');

        $readKeyV1 = (string) $menuResponse->json('meta.read_key');
        $this->assertSame(64, strlen($readKeyV1));
        $this->assertNotSame('', (string) $menuResponse->json('meta.revision.published_at'));

        $this->getJson($base.'/categories'.$queryAEn)
            ->assertOk()
            ->assertJsonPath('meta.read_key', $readKeyV1)
            ->assertJsonPath('meta.revision.id', (string) $publishedV1->public_id);

        $this->getJson($base.'/items'.$queryAEn)
            ->assertOk()
            ->assertJsonPath('meta.read_key', $readKeyV1)
            ->assertJsonPath('meta.revision.id', (string) $publishedV1->public_id)
            ->assertJsonPath('data.0.id', (string) $item->public_id);

        $this->getJson($base.'/items/'.$item->public_id.$queryAEn)
            ->assertOk()
            ->assertJsonPath('meta.read_key', $readKeyV1)
            ->assertJsonPath('meta.revision.id', (string) $publishedV1->public_id);

        $this->getJson($base.'/menu?branch='.$branchA->public_id.'&locale=ar')
            ->assertOk()
            ->assertJsonMissingExact(['read_key' => $readKeyV1]);

        $branchBResponse = $this->getJson($base.'/menu?branch='.$branchB->public_id.'&locale=en')
            ->assertOk();
        $this->assertNotSame($readKeyV1, (string) $branchBResponse->json('meta.read_key'));

        $nextDraft = $revisions->draft($fixture['admin'], $fixture['site']->fresh());
        $draftItem = SmartOrderingMenuItem::query()
            ->where('revision_id', $nextDraft->id)
            ->where('public_id', $item->public_id)
            ->firstOrFail();
        $writer->updateItem($fixture['admin'], $fixture['site']->fresh(), $nextDraft, $draftItem, [
            'name_en' => 'Burger V2 Draft',
        ]);

        $this->getJson($base.'/menu'.$queryAEn)
            ->assertOk()
            ->assertJsonPath('meta.read_key', $readKeyV1)
            ->assertJsonPath('meta.revision.id', (string) $publishedV1->public_id)
            ->assertJsonPath('data.menus.0.categories.0.items.0.name', 'Burger V1');

        $publishedV2 = $revisions->publish($fixture['admin'], $fixture['site']->fresh(), $nextDraft->fresh());

        $publishedResponse = $this->getJson($base.'/menu'.$queryAEn)
            ->assertOk()
            ->assertJsonPath('meta.revision.id', (string) $publishedV2->public_id)
            ->assertJsonPath('data.menus.0.categories.0.items.0.name', 'Burger V2 Draft');

        $this->assertNotSame($readKeyV1, (string) $publishedResponse->json('meta.read_key'));
    }

    public function test_public_item_search_is_bounded_to_fifty_rows(): void
    {
        $fixture = $this->publishedFixture('BOUND', 55);
        $menus = app(SmartOrderingMenuReadService::class);

        $result = $menus->searchItems(
            $fixture['site']->fresh(),
            'en',
            $fixture['branch']->fresh(),
            null,
            null,
            null,
            1,
            500,
        );

        $this->assertSame(50, $result['meta']['per_page']);
        $this->assertCount(50, $result['data']);

        $this->getJson('/api/v1/smart-ordering/'.$fixture['site']->slug.'/items?branch='.$fixture['branch']->public_id.'&per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_large_public_item_search_has_constant_query_growth(): void
    {
        $fixture = $this->publishedFixture('PERF', 40);
        $url = '/api/v1/smart-ordering/'.$fixture['site']->slug.'/items?branch='.$fixture['branch']->public_id.'&locale=en';

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson($url.'&per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data');
        $smallPageQueries = $queries;

        $queries = 0;
        $this->getJson($url.'&per_page=40')
            ->assertOk()
            ->assertJsonCount(40, 'data');
        $largePageQueries = $queries;

        $this->assertLessThanOrEqual(
            $smallPageQueries + 3,
            $largePageQueries,
            'Public menu search query count must not grow with the number of returned items.',
        );
        $this->assertLessThanOrEqual(
            40,
            $largePageQueries,
            'Representative 40-item public menu search exceeded the bounded query budget.',
        );
    }

    /**
     * @return array{
     *   store_id:int,
     *   product_ids:list<int>,
     *   admin:User,
     *   site:SmartOrderingSite,
     *   branch:SmartOrderingBranch
     * }
     */
    private function publishedFixture(string $suffix, int $itemCount): array
    {
        $fixture = $this->fixture($suffix, $itemCount);
        $branch = $this->branch($fixture['site']->id, $suffix, true);
        $writer = app(SmartOrderingMenuWriteService::class);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $draft = $revisions->draft($fixture['admin'], $fixture['site']);
        $menu = $writer->createMenu($fixture['admin'], $fixture['site'], $draft, [
            'name_en' => 'Large Menu',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
        ]);
        $category = $writer->createCategory($fixture['admin'], $fixture['site'], $draft, $menu, [
            'name_en' => 'Meals',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
        ]);

        foreach ($fixture['product_ids'] as $index => $productId) {
            $writer->createItem($fixture['admin'], $fixture['site'], $draft, $category, [
                'source_product_id' => $productId,
                'name_en' => 'Menu Item '.($index + 1),
                'is_visible' => true,
                'sold_out' => false,
                'spicy_level' => 0,
                'allow_customer_notes' => true,
                'sort_order' => $index,
            ]);
        }

        $revisions->publish($fixture['admin'], $fixture['site'], $draft);
        $this->enablePublicMenu($fixture);

        return $fixture + ['branch' => $branch];
    }

    /**
     * @return array{store_id:int,product_ids:list<int>,admin:User,site:SmartOrderingSite}
     */
    private function fixture(string $suffix, int $productCount): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'SO-'.$suffix,
            'name' => 'SO '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-'.$suffix,
            'name' => 'Each '.$suffix,
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Catalog '.$suffix,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Source '.$suffix,
            'slug' => strtolower('source-'.$suffix),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productIds = [];
        for ($index = 0; $index < $productCount; $index++) {
            $productId = (int) DB::table('products')->insertGetId([
                'catalog_id' => $catalogId,
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'sku' => 'SKU-'.$suffix.'-'.$index,
                'barcode' => 'BAR-'.$suffix.'-'.$index,
                'name' => 'Product '.$suffix.' '.$index,
                'description' => 'Smart Ordering source product',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('store_products')->insert([
                'store_id' => $storeId,
                'product_id' => $productId,
                'price' => 1.000 + ($index / 100),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $productIds[] = $productId;
        }

        $admin = $this->storeAdmin($storeId, $suffix);
        $this->enableRestaurantSaasForTestStore($storeId);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);

        return [
            'store_id' => $storeId,
            'product_ids' => $productIds,
            'admin' => $admin,
            'site' => $site,
        ];
    }

    private function enablePublicMenu(array $fixture): void
    {
        $service = app(SmartOrderingSiteService::class);
        $service->replaceEntitlements($fixture['site']->fresh(), ['public_menu' => true], $fixture['admin']);
        $service->updateRuntime($fixture['admin'], $fixture['site']->fresh(), 'ENABLED', true);
    }

    private function branch(int $siteId, string $code, bool $default = false): SmartOrderingBranch
    {
        if ($default) {
            SmartOrderingBranch::query()->where('site_id', $siteId)->update(['is_default' => false]);
        }

        return SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => $code,
            'name_en' => 'Branch '.$code,
            'timezone' => 'Asia/Kuwait',
            'is_default' => $default,
            'is_active' => true,
        ]);
    }

    private function storeAdmin(int $storeId, string $suffix): User
    {
        $user = User::query()->create([
            'name' => 'Restaurant Admin '.$suffix,
            'email' => strtolower('so-'.$suffix).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
