<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenu;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuCategory;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingAvailabilityService;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionService;
use App\Domain\SmartOrdering\SmartOrderingMenuWriteService;
use App\Domain\SmartOrdering\SmartOrderingModifierService;
use App\Domain\SmartOrdering\SmartOrderingPriceResolver;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\DatabaseStorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\StorefrontThemeRenderer;
use App\Models\Role;
use App\Models\User;
use App\Services\CommerceQuoteService;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_menu_builder_bounds_default_editor_and_opens_focused_records_directly(): void
    {
        $fixture = $this->fixture('FOCUS', 5.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draft, $firstMenu] = $this->draftItem($fixture, $writer);

        $secondMenu = $writer->createMenu($fixture['admin'], $fixture['site'], $draft, [
            'name_en' => 'Second Menu',
            'is_default' => false,
            'is_visible' => true,
            'sort_order' => 10,
        ]);
        $secondCategory = $writer->createCategory($fixture['admin'], $fixture['site'], $draft, $secondMenu, [
            'name_en' => 'Second Category',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
        ]);
        $secondItem = $writer->createItem($fixture['admin'], $fixture['site'], $draft, $secondCategory, [
            'name_en' => 'Second Target Item',
            'price_override' => 6.500,
            'is_visible' => true,
            'sold_out' => false,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $fixture['store_id']]))
            ->assertOk()
            ->assertSee('data-menu-editor-bounded', false)
            ->assertSee('id="menu-'.$firstMenu->id.'"', false)
            ->assertDontSee('id="menu-'.$secondMenu->id.'"', false)
            ->assertDontSee('data-smart-ordering-draft-review', false);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'q' => 'Second Target',
            ]))
            ->assertOk()
            ->assertSee('data-menu-focus-results', false)
            ->assertSee('Second Target Item')
            ->assertSee('#menu-item-'.$secondItem->public_id, false);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'menu_id' => $secondMenu->id,
            ]))
            ->assertOk()
            ->assertSee('id="menu-'.$secondMenu->id.'"', false)
            ->assertSee('id="menu-item-'.$secondItem->public_id.'"', false)
            ->assertDontSee('id="menu-'.$firstMenu->id.'"', false);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'review' => 1,
            ]))
            ->assertOk()
            ->assertSee('data-smart-ordering-draft-review', false)
            ->assertSee('Second Target Item');
    }

    public function test_menu_media_and_draft_review_are_available_before_publish(): void
    {
        Storage::fake('public');
        $fixture = $this->fixture('MEDIA', 4.250);
        $writer = app(SmartOrderingMenuWriteService::class);
        $draft = app(SmartOrderingMenuRevisionService::class)->draft($fixture['admin'], $fixture['site']);

        $this->actingAs($fixture['admin'])
            ->post(route('admin.smart-ordering.menu.menus.store'), [
                'store_id' => $fixture['store_id'],
                'name_en' => 'Visual Menu',
                'sort_order' => 0,
                'is_default' => 1,
                'is_visible' => 1,
                'image' => UploadedFile::fake()->image('menu.jpg', 900, 600),
            ])
            ->assertRedirect();

        $menu = SmartOrderingMenu::query()
            ->where('revision_id', $draft->id)
            ->where('name_en', 'Visual Menu')
            ->firstOrFail();

        $this->assertNotNull($menu->image_path);
        Storage::disk('public')->assertExists((string) $menu->image_path);

        $category = $writer->createCategory($fixture['admin'], $fixture['site'], $draft->fresh(), $menu, [
            'name_en' => 'Meals',
            'image_path' => 'smart-ordering/demo/category.webp',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
        ]);
        $reviewItem = $writer->createItem($fixture['admin'], $fixture['site'], $draft->fresh(), $category, [
            'name_en' => 'Review Burger',
            'image_path' => 'smart-ordering/demo/item.webp',
            'price_override' => 4.250,
            'is_visible' => true,
            'sold_out' => false,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 0,
        ]);

        $checkoutQuote = app(CommerceQuoteService::class)->quote(
            'b2c',
            $fixture['store_id'],
            null,
            [['product_id' => $fixture['product_id'], 'quantity' => 1]],
        );
        $this->assertSame('EGP', $checkoutQuote['currency']);
        $this->assertSame(4.25, $checkoutQuote['subtotal']);
        $this->assertSame(
            app(SmartOrderingPriceResolver::class)->displayPrice($fixture['site'], $reviewItem)['amount'],
            $checkoutQuote['subtotal'],
        );

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'review' => 1,
            ]))
            ->assertOk()
            ->assertSee('Review menu before publishing')
            ->assertSee('data-smart-ordering-draft-review', false)
            ->assertSee('Current category image')
            ->assertSee('Current item image')
            ->assertSee('Visual Menu')
            ->assertSee('Review Burger')
            ->assertSee('4.25 EGP')
            ->assertDontSee('KWD');

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'menu_id' => $menu->id,
            ]))
            ->assertOk()
            ->assertSee('step="0.01"', false);

        $published = app(SmartOrderingMenuRevisionService::class)->publish(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
        );

        $publishedMenu = SmartOrderingMenu::query()
            ->where('revision_id', $published->id)
            ->where('public_id', $menu->public_id)
            ->firstOrFail();

        $this->assertSame($menu->image_path, $publishedMenu->image_path);
    }

    public function test_menu_builder_keeps_unavailable_linked_items_repairable_and_blocks_publish(): void
    {
        $fixture = $this->fixture('REPAIR', 4.500);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draft, , $category, $linkedItem] = $this->draftItem($fixture, $writer);
        $writer->createItem($fixture['admin'], $fixture['site'], $draft, $category, [
            'name_en' => 'Unaffected Item',
            'price_override' => 7.500,
            'is_visible' => true,
            'sold_out' => false,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 1,
        ]);

        DB::table('products')->where('id', $fixture['product_id'])->update(['is_active' => false]);

        app()->setLocale('en');
        $response = $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $fixture['store_id']]));

        $response->assertOk()
            ->assertSee('Resolve unavailable menu items before publishing')
            ->assertSee('Linked product needs attention')
            ->assertSee('Replace product')
            ->assertSee('Unlink product')
            ->assertSee('Set override price')
            ->assertSee('Review visibility')
            ->assertSee('Unaffected Item')
            ->assertSee('7.50 EGP')
            ->assertDontSee('0.00 EGP')
            ->assertSee('menu-item-'.$linkedItem->public_id, false)
            ->assertSee('disabled', false);

        try {
            app(SmartOrderingMenuRevisionService::class)->publish(
                $fixture['admin'],
                $fixture['site']->fresh(),
                $draft->fresh(),
            );
            $this->fail('Publishing must remain fail-closed while a linked item has no authoritative price.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_product_id', $exception->errors());
        }

        $fixture['admin']->forceFill(['locale' => 'ar'])->save();
        app()->setLocale('ar');
        $this->actingAs($fixture['admin']->fresh())
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $fixture['store_id']]))
            ->assertOk()
            ->assertSee('عالج أصناف المنيو غير المتاحة قبل النشر')
            ->assertSee('إلغاء ربط المنتج');
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

    public function test_modifier_kind_options_are_localized_for_create_edit_and_unknown_legacy_values(): void
    {
        $expected = [
            'en' => [
                'modifier' => 'Modifier',
                'size' => 'Size',
                'variant' => 'Variant',
                'extra' => 'Extra',
                'ingredient' => 'Ingredient',
                'unknown' => 'Unsupported legacy type — choose a supported type',
            ],
            'ar' => [
                'modifier' => 'تخصيص',
                'size' => 'الحجم',
                'variant' => 'خيار',
                'extra' => 'إضافة',
                'ingredient' => 'مكوّن',
                'unknown' => 'نوع قديم غير مدعوم — اختر نوعًا مدعومًا',
            ],
        ];

        foreach ($expected as $locale => $labels) {
            app()->setLocale($locale);

            foreach ($labels as $kind => $label) {
                $this->assertSame($label, __('smart_ordering_menu.modifier_kinds.'.$kind));
            }
        }

        $view = file_get_contents(resource_path('views/admin/smart-ordering-menu.blade.php'));
        $this->assertIsString($view);
        $this->assertSame(2, substr_count($view, '@foreach($modifierKindValues as $kind)'));
        $this->assertStringNotContainsString('ucfirst($kind)', $view);
        $this->assertStringContainsString('in_array($group->kind, $modifierKindValues, true)', $view);

        $fixture = $this->fixture('KIND', 5.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draft, , , $item] = $this->draftItem($fixture, $writer);
        $group = $writer->createModifierGroup($fixture['admin'], $fixture['site'], $draft, $item, [
            'name_en' => 'Legacy choice',
            'kind' => 'extra',
            'selection_type' => 'single',
            'is_required' => false,
            'min_selections' => 0,
            'max_selections' => 1,
            'is_visible' => true,
            'sort_order' => 0,
        ]);

        DB::table('smart_ordering_modifier_groups')->where('id', $group->id)->update(['kind' => 'legacy_kind']);

        app()->setLocale('en');
        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $fixture['store_id']]))
            ->assertOk()
            ->assertSee('Unsupported legacy type — choose a supported type');

        $this->assertSame(
            'legacy_kind',
            DB::table('smart_ordering_modifier_groups')->where('id', $group->id)->value('kind'),
        );
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
            'image_path' => 'smart-ordering/menu-main.webp',
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
            ->assertJsonPath('data.menus.0.image', 'http://localhost/storage/smart-ordering/menu-main.webp')
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
            ->assertJsonPath('currency', 'EGP')
            ->assertJsonPath('money_step', '0.01')
            ->assertJsonPath('products.0.id', $a['product_id'])
            ->assertJsonPath('products.0.currency', 'EGP')
            ->assertJsonPath('products.0.display_price', '4.00');

        $this->actingAs($a['admin'])
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $a['store_id']]))
            ->assertOk()
            ->assertSee('Smart Ordering Menu Builder')
            ->assertDontSee('config_json');
    }

    public function test_restaurant_content_editor_binds_real_menu_data_and_survives_template_changes(): void
    {
        Storage::fake('public');
        $fixture = $this->fixture('CONTENT', 5.750);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draft, , $category, $item] = $this->draftItem($fixture, $writer, [
            'name_ar' => 'طبق الاختبار',
            'description_en' => 'Fresh seasonal plate',
            'description_ar' => 'طبق موسمي طازج',
            'image_path' => 'smart-ordering/content/item.webp',
            'featured' => true,
            'vegetarian' => true,
            'allergens' => ['sesame'],
        ]);
        $category->forceFill([
            'name_ar' => 'الوجبات',
            'description_en' => 'Main dishes',
            'description_ar' => 'الأطباق الرئيسية',
        ])->save();

        $this->actingAs($fixture['admin'])
            ->put(route('admin.smart-ordering.revisions.content', $draft), [
                'name_en' => 'Content Kitchen',
                'name_ar' => 'مطبخ المحتوى',
                'tagline_en' => 'One menu, every template',
                'tagline_ar' => 'منيو واحدة لكل القوالب',
                'description_en' => 'Seasonal restaurant menu',
                'description_ar' => 'منيو مطعم موسمية',
                'phone' => '+96522000000',
                'whatsapp_url' => 'https://wa.me/96522000000',
                'logo' => UploadedFile::fake()->image('logo.png', 256, 256),
                'cover' => UploadedFile::fake()->image('cover.webp', 1200, 600),
                'about_en' => 'Local ingredients and careful cooking.',
                'about_ar' => 'مكونات محلية وطهي بعناية.',
                'chef_note_en' => 'Chef selections change with the season.',
                'chef_note_ar' => 'اختيارات الشيف تتغير حسب الموسم.',
                'location_en' => 'Kuwait City',
                'location_ar' => 'مدينة الكويت',
                'map_url' => 'https://maps.google.com/?q=Kuwait+City',
                'reservation_cta_en' => 'Reserve a table',
                'reservation_cta_ar' => 'احجز طاولتك',
                'reservation_url' => 'https://example.test/reservations',
                'allergen_note_en' => 'Ask the team about allergens.',
                'allergen_note_ar' => 'اسأل الفريق عن مسببات الحساسية.',
            ])
            ->assertRedirect();

        $provider = app(StorefrontSnapshotProvider::class);
        $this->assertInstanceOf(DatabaseStorefrontSnapshotProvider::class, $provider);

        $before = $provider->revision((string) $fixture['site']->slug, 'draft');
        $this->assertIsArray($before);
        $this->assertSame('Content Kitchen', $before['site']['name']['en']);
        $this->assertSame('مطبخ المحتوى', $before['site']['name']['ar']);
        $this->assertSame('Chef selections change with the season.', $before['site']['chef_note']['en']);
        $this->assertSame('احجز طاولتك', $before['site']['reservation_cta']['ar']);
        $this->assertSame('الوجبات', $before['menu']['categories'][0]['name']['ar']);
        $this->assertSame((string) $item->public_id, $before['menu']['categories'][0]['items'][0]['public_key']);
        $this->assertSame('5.75 EGP', $before['menu']['categories'][0]['items'][0]['price_display']);
        $this->assertTrue($before['menu']['categories'][0]['items'][0]['featured']);

        $json = json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['store_id', 'site_id', 'tenant_id', 'internal_id', 'created_by', 'published_by'] as $privateKey) {
            $this->assertStringNotContainsString('"'.$privateKey.'"', $json);
        }

        $renderer = app(StorefrontThemeRenderer::class);
        $english = $renderer->prepare($before, localeOverride: 'en');
        $arabic = $renderer->prepare($before, localeOverride: 'ar');
        $this->assertSame('Content Kitchen', $english['site']['name']);
        $this->assertSame('مطبخ المحتوى', $arabic['site']['name']);
        $this->assertContains('Vegetarian', $english['menu']['categories'][0]['items'][0]['tags']);
        $this->assertContains('نباتي', $arabic['menu']['categories'][0]['items'][0]['tags']);
        $this->assertSame('rtl', $arabic['direction']);

        $draft = $draft->fresh();
        $payload = (array) $draft->payload;
        $payload['appearance'] = ['template' => 'signature-brand', 'mode' => 'light'];
        app(SmartOrderingRevisionService::class)->updateDraft($fixture['admin'], $draft, $payload);
        $after = $provider->revision((string) $fixture['site']->slug, 'draft');

        $this->assertSame($before['site']['name'], $after['site']['name']);
        $this->assertSame($before['menu'], $after['menu']);
        $this->assertSame('signature-brand', $after['appearance']['template']);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.index', ['store_id' => $fixture['store_id']]))
            ->assertOk()
            ->assertSee('data-restaurant-content-editor', false)
            ->assertSee('Content Kitchen')
            ->assertDontSee('config_json');
    }

    /** @return array{draft:SmartOrderingRevision,menu:SmartOrderingMenu,category:SmartOrderingMenuCategory,item:SmartOrderingMenuItem} */
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
        $this->enableRestaurantSaasForTestStore($storeId);
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
