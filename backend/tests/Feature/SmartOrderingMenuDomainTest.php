<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenu;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuCategory;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\SmartOrderingAvailabilityService;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionService;
use App\Domain\SmartOrdering\SmartOrderingMenuWriteService;
use App\Domain\SmartOrdering\SmartOrderingModifierService;
use App\Domain\SmartOrdering\SmartOrderingPriceResolver;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SmartOrderingMenuDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_modifier_bounds_and_price_deltas_are_deterministic(): void
    {
        $fixture = $this->fixture('MOD', 5.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draft, $menu, $category, $item] = $this->draftItem($fixture, $writer);

        $group = $writer->createModifierGroup($fixture['admin'], $fixture['site'], $draft, $item, [
            'name_en' => 'Extras',
            'kind' => 'extra',
            'selection_type' => 'multiple',
            'is_required' => true,
            'min_selections' => 1,
            'max_selections' => 2,
            'is_visible' => true,
            'sort_order' => 0,
        ]);
        $one = $writer->createModifierOption($fixture['admin'], $fixture['site'], $draft, $group, [
            'name_en' => 'Cheese', 'price_delta' => 1.000, 'is_visible' => true, 'sort_order' => 0,
        ]);
        $two = $writer->createModifierOption($fixture['admin'], $fixture['site'], $draft, $group, [
            'name_en' => 'Sauce', 'price_delta' => 2.000, 'is_visible' => true, 'sort_order' => 1,
        ]);
        $three = $writer->createModifierOption($fixture['admin'], $fixture['site'], $draft, $group, [
            'name_en' => 'Avocado', 'price_delta' => 3.000, 'is_visible' => true, 'sort_order' => 2,
        ]);

        $quote = app(SmartOrderingModifierService::class)->quote(
            $fixture['site'],
            $item->fresh(),
            [(string) $group->public_id => [(string) $one->public_id, (string) $two->public_id]],
        );

        $this->assertSame(5.0, $quote['base_price']);
        $this->assertSame(3.0, $quote['modifier_delta']);
        $this->assertSame(8.0, $quote['total_price']);

        try {
            app(SmartOrderingModifierService::class)->quote($fixture['site'], $item->fresh(), []);
            $this->fail('Required modifier minimum must be enforced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selections.'.(string) $group->public_id, $exception->errors());
        }

        try {
            app(SmartOrderingModifierService::class)->quote($fixture['site'], $item->fresh(), [
                (string) $group->public_id => [
                    (string) $one->public_id,
                    (string) $two->public_id,
                    (string) $three->public_id,
                ],
            ]);
            $this->fail('Modifier maximum must be enforced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selections.'.(string) $group->public_id, $exception->errors());
        }

        $this->assertSame($menu->id, $category->menu_id);
    }

    public function test_branch_overrides_win_over_menu_item_and_modifier_prices(): void
    {
        $fixture = $this->fixture('BRPRICE', 5.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draft, , , $item] = $this->draftItem($fixture, $writer, ['price_override' => 6.000]);
        $branch = $this->branch($fixture['site']->id, 'KWT', true);

        $group = $writer->createModifierGroup($fixture['admin'], $fixture['site'], $draft, $item, [
            'name_en' => 'Size', 'kind' => 'size', 'selection_type' => 'single',
            'is_required' => false, 'min_selections' => 0, 'max_selections' => 1,
            'is_visible' => true, 'sort_order' => 0,
        ]);
        $option = $writer->createModifierOption($fixture['admin'], $fixture['site'], $draft, $group, [
            'name_en' => 'Large', 'price_delta' => 1.000, 'is_visible' => true, 'sort_order' => 0,
        ]);

        $writer->setItemBranchOverride($fixture['admin'], $fixture['site'], $draft, $item, $branch, [
            'price_override' => 7.000,
        ]);
        $writer->setOptionBranchOverride($fixture['admin'], $fixture['site'], $draft, $option, $branch, [
            'price_delta' => 2.500,
        ]);

        $prices = app(SmartOrderingPriceResolver::class);
        $this->assertSame(['amount' => 6.0, 'source' => 'item_override'], $prices->displayPrice($fixture['site'], $item->fresh()));
        $this->assertSame(['amount' => 7.0, 'source' => 'branch_override'], $prices->displayPrice($fixture['site'], $item->fresh(), $branch));
        $this->assertSame(1.0, $prices->modifierDelta($fixture['site'], $option->fresh()));
        $this->assertSame(2.5, $prices->modifierDelta($fixture['site'], $option->fresh(), $branch));
    }

    public function test_schedule_sold_out_and_branch_override_precedence_are_enforced(): void
    {
        $fixture = $this->fixture('SCHED', 4.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        $draft = app(SmartOrderingMenuRevisionService::class)->draft($fixture['admin'], $fixture['site']);
        $menu = $writer->createMenu($fixture['admin'], $fixture['site'], $draft, [
            'name_en' => 'Timed Menu', 'is_default' => true, 'is_visible' => true, 'sort_order' => 0,
        ]);
        $category = $writer->createCategory($fixture['admin'], $fixture['site'], $draft, $menu, [
            'name_en' => 'Breakfast',
            'is_visible' => true,
            'sold_out' => false,
            'availability_schedule' => ['windows' => [[
                'days' => [4],
                'start' => '06:00',
                'end' => '11:00',
            ]]],
            'sort_order' => 0,
        ]);

        $availability = app(SmartOrderingAvailabilityService::class);
        $inside = CarbonImmutable::parse('2026-10-15 08:30:00', 'Asia/Kuwait');
        $outside = CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Kuwait');

        $this->assertTrue($availability->category($category->fresh(), null, $inside)['available']);
        $this->assertSame('OUTSIDE_SCHEDULE', $availability->category($category->fresh(), null, $outside)['reason']);

        $branch = $this->branch($fixture['site']->id, 'SCHED-BR', true);
        $writer->setCategoryBranchOverride($fixture['admin'], $fixture['site'], $draft, $category, $branch, [
            'sold_out' => true,
        ]);

        $state = $availability->category($category->fresh(), $branch, $inside);
        $this->assertFalse($state['available']);
        $this->assertTrue($state['sold_out']);
        $this->assertSame('SOLD_OUT', $state['reason']);
    }

    public function test_bilingual_fallback_and_draft_publish_boundary_are_publicly_observable(): void
    {
        $fixture = $this->fixture('PUB', 3.750);
        $writer = app(SmartOrderingMenuWriteService::class);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $draft = $revisions->draft($fixture['admin'], $fixture['site']);

        $menu = $writer->createMenu($fixture['admin'], $fixture['site'], $draft, [
            'name_ar' => 'القائمة الرئيسية',
            'name_en' => 'Main Menu',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
        ]);
        $category = $writer->createCategory($fixture['admin'], $fixture['site'], $draft, $menu, [
            'name_ar' => 'البرجر',
            'name_en' => 'Burgers',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
        ]);
        $item = $writer->createItem($fixture['admin'], $fixture['site'], $draft, $category, [
            'source_product_id' => $fixture['product_id'],
            'name_ar' => null,
            'name_en' => 'Burger V1',
            'is_visible' => true,
            'sold_out' => false,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 0,
        ]);

        $revisions->publish($fixture['admin'], $fixture['site'], $draft);
        $this->enablePublicMenu($fixture);

        $this->getJson('/api/v1/smart-ordering/'.$fixture['site']->slug.'/menu?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.menus.0.name', 'القائمة الرئيسية')
            ->assertJsonPath('data.menus.0.categories.0.name', 'البرجر')
            ->assertJsonPath('data.menus.0.categories.0.items.0.name', 'Burger V1');

        $nextDraft = $revisions->draft($fixture['admin'], $fixture['site']->fresh());
        $draftItem = SmartOrderingMenuItem::query()
            ->where('revision_id', $nextDraft->id)
            ->where('public_id', $item->public_id)
            ->firstOrFail();
        $writer->updateItem($fixture['admin'], $fixture['site']->fresh(), $nextDraft, $draftItem, [
            'name_en' => 'Burger V2',
        ]);

        $this->getJson('/api/v1/smart-ordering/'.$fixture['site']->slug.'/menu?locale=en')
            ->assertOk()
            ->assertJsonPath('data.menus.0.categories.0.items.0.name', 'Burger V1');

        $revisions->publish($fixture['admin'], $fixture['site']->fresh(), $nextDraft->fresh());

        $this->getJson('/api/v1/smart-ordering/'.$fixture['site']->slug.'/menu?locale=en')
            ->assertOk()
            ->assertJsonPath('data.menus.0.categories.0.items.0.name', 'Burger V2');
    }

    public function test_search_pagination_and_menu_branch_scope_are_fail_closed(): void
    {
        $fixture = $this->fixture('SEARCH', 2.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $draft = $revisions->draft($fixture['admin'], $fixture['site']);
        $branchA = $this->branch($fixture['site']->id, 'A', true);
        $branchB = $this->branch($fixture['site']->id, 'B');

        $menu = $writer->createMenu($fixture['admin'], $fixture['site'], $draft, [
            'name_en' => 'Branch A',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
            'branch_ids' => [$branchA->id],
        ]);
        $category = $writer->createCategory($fixture['admin'], $fixture['site'], $draft, $menu, [
            'name_en' => 'Meals', 'is_visible' => true, 'sold_out' => false, 'sort_order' => 0,
        ]);

        $items = [];
        foreach (['Alpha Burger', 'Beta Burger', 'Gamma Burger'] as $index => $name) {
            $items[] = $writer->createItem($fixture['admin'], $fixture['site'], $draft, $category, [
                'price_override' => 1.000 + $index,
                'name_en' => $name,
                'is_visible' => true,
                'sold_out' => false,
                'spicy_level' => 0,
                'allow_customer_notes' => true,
                'sort_order' => $index,
            ]);
        }

        $revisions->publish($fixture['admin'], $fixture['site'], $draft);
        $this->enablePublicMenu($fixture);

        $this->getJson('/api/v1/smart-ordering/'.$fixture['site']->slug.'/items?branch='.$branchA->public_id.'&q=Burger&per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/smart-ordering/'.$fixture['site']->slug.'/items?branch='.$branchB->public_id.'&q=Burger')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/smart-ordering/'.$fixture['site']->slug.'/items/'.$items[0]->public_id.'?branch='.$branchB->public_id)
            ->assertNotFound();
    }

    public function test_cross_tenant_products_and_admin_mutations_are_rejected(): void
    {
        $a = $this->fixture('TEN-A', 4.000);
        $b = $this->fixture('TEN-B', 9.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draftA, , $categoryA] = $this->draftItem($a, $writer);

        try {
            $writer->createItem($a['admin'], $a['site'], $draftA, $categoryA, [
                'source_product_id' => $b['product_id'],
                'name_en' => 'Leaked product',
                'is_visible' => true,
                'sold_out' => false,
                'spicy_level' => 0,
                'allow_customer_notes' => true,
                'sort_order' => 99,
            ]);
            $this->fail('A foreign restaurant product must never be linkable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_product_id', $exception->errors());
        }

        $draftB = app(SmartOrderingMenuRevisionService::class)->draft($b['admin'], $b['site']);
        try {
            $writer->createMenu($a['admin'], $b['site'], $draftB, [
                'name_en' => 'Foreign mutation', 'is_visible' => true, 'sort_order' => 0,
            ]);
            $this->fail('A restaurant admin must not mutate another restaurant.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->actingAs($a['admin'])
            ->getJson(route('admin.smart-ordering.menu.products', [
                'store_id' => $a['store_id'],
                'q' => 'TEN-',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $a['product_id']);

        $this->actingAs($a['admin'])
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $a['store_id']]))
            ->assertOk()
            ->assertSee('Smart Ordering Menu Builder')
            ->assertDontSee('config_json');
    }

    /** @return array{draft:\App\Domain\SmartOrdering\Models\SmartOrderingRevision,menu:SmartOrderingMenu,category:SmartOrderingMenuCategory,item:SmartOrderingMenuItem} */
    private function draftItem(array $fixture, SmartOrderingMenuWriteService $writer, array $itemOverrides = []): array
    {
        $draft = app(SmartOrderingMenuRevisionService::class)->draft($fixture['admin'], $fixture['site']);
        $menu = $writer->createMenu($fixture['admin'], $fixture['site'], $draft, [
            'name_en' => 'Main Menu',
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
            'source_product_id' => $fixture['product_id'],
            'name_en' => 'Test Item',
            'is_visible' => true,
            'sold_out' => false,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 0,
            ...$itemOverrides,
        ]);

        return [$draft, $menu, $category, $item];
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

    /** @return array{store_id:int,product_id:int,admin:User,site:\App\Domain\SmartOrdering\Models\SmartOrderingSite} */
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
            'description' => 'Smart Ordering source product',
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

        return compact('storeId', 'productId', 'admin', 'site') + [
            'store_id' => $storeId,
            'product_id' => $productId,
        ];
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
