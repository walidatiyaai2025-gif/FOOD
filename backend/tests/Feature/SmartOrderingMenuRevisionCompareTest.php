<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenu;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuCategory;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierGroup;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierOption;
use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionCompareService;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionService;
use App\Domain\SmartOrdering\SmartOrderingMenuWriteService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SmartOrderingMenuRevisionCompareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_identical_cloned_revision_comparison_is_empty_and_deterministic(): void
    {
        $fixture = $this->fixture('CMP-SAME', 5.000);
        $tree = $this->publishedTree($fixture);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $draft = $revisions->draft($fixture['admin'], $fixture['site']->fresh());
        $compare = app(SmartOrderingMenuRevisionCompareService::class);

        $first = $compare->compare($fixture['site']->fresh(), $tree['published']->fresh(), $draft->fresh());
        $second = $compare->compare($fixture['site']->fresh(), $tree['published']->fresh(), $draft->fresh());

        $this->assertSame([
            'added' => 0,
            'removed' => 0,
            'modified' => 0,
            'total' => 0,
        ], $first['summary']);
        $this->assertSame([], $first['changes']);
        $this->assertSame(
            json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($second, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_comparison_reports_only_public_identity_and_field_name_deltas(): void
    {
        $fixture = $this->fixture('CMP-DELTA', 6.000);
        $tree = $this->publishedTree($fixture);
        $writer = app(SmartOrderingMenuWriteService::class);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $draft = $revisions->draft($fixture['admin'], $fixture['site']->fresh());

        $menu = SmartOrderingMenu::query()
            ->where('revision_id', $draft->id)
            ->where('public_id', $tree['menu']->public_id)
            ->firstOrFail();
        $item = SmartOrderingMenuItem::query()
            ->where('revision_id', $draft->id)
            ->where('public_id', $tree['item']->public_id)
            ->firstOrFail();
        $category = SmartOrderingMenuCategory::query()
            ->where('revision_id', $draft->id)
            ->where('public_id', $tree['category']->public_id)
            ->firstOrFail();
        $group = SmartOrderingModifierGroup::query()
            ->where('revision_id', $draft->id)
            ->where('public_id', $tree['group']->public_id)
            ->firstOrFail();
        $option = SmartOrderingModifierOption::query()
            ->where('group_id', $group->id)
            ->where('public_id', $tree['option']->public_id)
            ->firstOrFail();

        $writer->updateItem(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $item,
            ['name_en' => 'Comparison Burger v2'],
        );
        $writer->setItemBranchOverride(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $item->fresh(),
            $tree['branch_a']->fresh(),
            ['price_override' => 7.250],
        );
        $writer->setCategoryBranchOverride(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $category->fresh(),
            $tree['branch_a']->fresh(),
            ['sold_out' => true],
        );
        $writer->updateMenu(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $menu->fresh(),
            ['branch_ids' => [$tree['branch_a']->id, $tree['branch_b']->id]],
        );
        $addedCategory = $writer->createCategory(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $menu->fresh(),
            [
                'name_en' => 'Seasonal',
                'is_visible' => true,
                'sold_out' => false,
                'sort_order' => 10,
            ],
        );
        $writer->deleteModifierOption(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $option->fresh(),
        );

        $result = app(SmartOrderingMenuRevisionCompareService::class)->compare(
            $fixture['site']->fresh(),
            $tree['published']->fresh(),
            $draft->fresh(),
        );

        $itemChange = $this->change($result['changes'], 'menu_item', (string) $tree['item']->public_id);
        $this->assertSame('modified', $itemChange['change']);
        $this->assertContains('name_en', $itemChange['fields']);

        $itemOverride = $this->change(
            $result['changes'],
            'item_branch_override',
            (string) $tree['item']->public_id,
            (string) $tree['branch_a']->public_id,
        );
        $this->assertSame('modified', $itemOverride['change']);
        $this->assertSame(['price_override'], $itemOverride['fields']);

        $categoryOverride = $this->change(
            $result['changes'],
            'category_branch_override',
            (string) $tree['category']->public_id,
            (string) $tree['branch_a']->public_id,
        );
        $this->assertSame('modified', $categoryOverride['change']);
        $this->assertSame(['sold_out'], $categoryOverride['fields']);

        $menuScope = $this->change(
            $result['changes'],
            'menu_branch_scope',
            (string) $tree['menu']->public_id,
            (string) $tree['branch_b']->public_id,
        );
        $this->assertSame('added', $menuScope['change']);
        $this->assertSame(['membership'], $menuScope['fields']);

        $added = $this->change($result['changes'], 'menu_category', (string) $addedCategory->public_id);
        $this->assertSame('added', $added['change']);

        $removedOption = $this->change(
            $result['changes'],
            'modifier_option',
            (string) $tree['option']->public_id,
        );
        $this->assertSame('removed', $removedOption['change']);

        $removedOverride = $this->change(
            $result['changes'],
            'modifier_option_branch_override',
            (string) $tree['option']->public_id,
            (string) $tree['branch_a']->public_id,
        );
        $this->assertSame('removed', $removedOverride['change']);

        $this->assertGreaterThanOrEqual(1, $result['summary']['added']);
        $this->assertGreaterThanOrEqual(2, $result['summary']['removed']);
        $this->assertGreaterThanOrEqual(3, $result['summary']['modified']);
        $this->assertNoInternalKeys($result);
    }

    public function test_comparison_fails_closed_when_either_revision_is_from_another_site(): void
    {
        $a = $this->fixture('CMP-A', 4.000);
        $b = $this->fixture('CMP-B', 4.000);
        $publishedA = $this->publishedTree($a)['published'];
        $publishedB = $this->publishedTree($b)['published'];

        try {
            app(SmartOrderingMenuRevisionCompareService::class)->compare(
                $a['site']->fresh(),
                $publishedA->fresh(),
                $publishedB->fresh(),
            );
            $this->fail('Cross-site revision comparison must fail closed.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    /**
     * @param array{store_id:int,product_id:int,admin:User,site:SmartOrderingSite} $fixture
     * @return array{
     *   published:SmartOrderingRevision,
     *   menu:SmartOrderingMenu,
     *   category:SmartOrderingMenuCategory,
     *   item:SmartOrderingMenuItem,
     *   group:SmartOrderingModifierGroup,
     *   option:SmartOrderingModifierOption,
     *   branch_a:SmartOrderingBranch,
     *   branch_b:SmartOrderingBranch
     * }
     */
    private function publishedTree(array $fixture): array
    {
        $writer = app(SmartOrderingMenuWriteService::class);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $branchA = $this->branch($fixture['site']->id, 'A');
        $branchB = $this->branch($fixture['site']->id, 'B');
        $draft = $revisions->draft($fixture['admin'], $fixture['site']->fresh());

        $menu = $writer->createMenu($fixture['admin'], $fixture['site']->fresh(), $draft, [
            'name_en' => 'Comparison Menu',
            'description_en' => 'Stable comparison menu',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
            'branch_ids' => [$branchA->id],
        ]);
        $category = $writer->createCategory(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $menu->fresh(),
            [
                'name_en' => 'Meals',
                'is_visible' => true,
                'sold_out' => false,
                'sort_order' => 0,
            ],
        );
        $item = $writer->createItem(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $category->fresh(),
            [
                'source_product_id' => $fixture['product_id'],
                'name_en' => 'Comparison Burger',
                'price_override' => 6.000,
                'is_visible' => true,
                'sold_out' => false,
                'spicy_level' => 0,
                'allow_customer_notes' => true,
                'sort_order' => 0,
            ],
        );
        $group = $writer->createModifierGroup(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $item->fresh(),
            [
                'name_en' => 'Size',
                'kind' => 'size',
                'selection_type' => 'single',
                'is_required' => false,
                'min_selections' => 0,
                'max_selections' => 1,
                'is_visible' => true,
                'sort_order' => 0,
            ],
        );
        $option = $writer->createModifierOption(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $group->fresh(),
            [
                'name_en' => 'Large',
                'price_delta' => 1.000,
                'is_default' => false,
                'is_visible' => true,
                'sold_out' => false,
                'sort_order' => 0,
            ],
        );

        $writer->setCategoryBranchOverride(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $category->fresh(),
            $branchA,
            ['sold_out' => false],
        );
        $writer->setItemBranchOverride(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $item->fresh(),
            $branchA,
            ['price_override' => 6.250],
        );
        $writer->setOptionBranchOverride(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $option->fresh(),
            $branchA,
            ['price_delta' => 1.250],
        );

        $published = $revisions->publish(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
        );

        return [
            'published' => $published,
            'menu' => $menu,
            'category' => $category,
            'item' => $item,
            'group' => $group,
            'option' => $option,
            'branch_a' => $branchA,
            'branch_b' => $branchB,
        ];
    }

    private function branch(int $siteId, string $suffix): SmartOrderingBranch
    {
        return SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => 'CMP-'.$suffix,
            'name_en' => 'Comparison Branch '.$suffix,
            'timezone' => 'Asia/Kuwait',
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    /** @return array{store_id:int,product_id:int,admin:User,site:SmartOrderingSite} */
    private function fixture(string $suffix, float $price): array
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
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'SKU-'.$suffix,
            'barcode' => 'BAR-'.$suffix,
            'name' => 'Product '.$suffix,
            'description' => 'Revision comparison source product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->storeAdmin($storeId, $suffix);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);

        return [
            'store_id' => $storeId,
            'product_id' => $productId,
            'admin' => $admin,
            'site' => $site,
        ];
    }

    private function storeAdmin(int $storeId, string $suffix): User
    {
        $user = User::query()->create([
            'name' => 'Comparison Admin '.$suffix,
            'email' => strtolower('comparison-'.$suffix).'@example.test',
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

    /**
     * @param list<array{entity:string,public_id:string,branch_public_id:?string,change:string,fields:list<string>}> $changes
     * @return array{entity:string,public_id:string,branch_public_id:?string,change:string,fields:list<string>}
     */
    private function change(array $changes, string $entity, string $publicId, ?string $branchPublicId = null): array
    {
        foreach ($changes as $change) {
            if ($change['entity'] === $entity
                && $change['public_id'] === $publicId
                && $change['branch_public_id'] === $branchPublicId) {
                return $change;
            }
        }

        $this->fail('Expected revision comparison change was not found: '.$entity.' '.$publicId);

        return [];
    }

    /** @param array<mixed> $value */
    private function assertNoInternalKeys(array $value): void
    {
        $forbidden = [
            'id', 'revision_id', 'site_id', 'menu_id', 'category_id', 'item_id',
            'group_id', 'option_id', 'branch_id', 'source_product_id',
            'parent_revision_id', 'created_by', 'published_by', 'scheduled_by',
            'created_at', 'updated_at', 'published_at', 'scheduled_at',
        ];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbidden);
            }

            if (is_array($item)) {
                $this->assertNoInternalKeys($item);
            }
        }
    }
}
