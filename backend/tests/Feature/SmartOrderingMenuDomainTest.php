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
use App\Domain\SmartOrdering\Themes\ThemeRegistry;
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

    public function test_appearance_builder_creates_a_starter_menu_with_categories_and_images(): void
    {
        Storage::fake('public');
        $fixture = $this->fixture('QUICKMENU', 5.000);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.appearance.index', [
                'site' => $fixture['site']->public_id,
                'store_id' => $fixture['store_id'],
            ]))
            ->assertOk()
            ->assertSee('data-qm-card', false)
            ->assertSee('data-qm-open', false)
            ->assertSee('data-qm-modal', false)
            ->assertSee('data-qm-add-category', false)
            ->assertSee('data-qm-add-item', false)
            ->assertSee('data-qm-save', false)
            ->assertSee('name="categories[0][image]"', false)
            ->assertSee('name="items[0][image]"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('display:flex!important;flex-direction:column!important', false)
            ->assertSee('height:auto!important', false)
            ->assertSee('grid-template-columns:repeat(2,minmax(0,1fr))!important', false)
            ->assertSee(route('admin.smart-ordering.menu.quick-create'), false);

        $this->actingAs($fixture['admin'])
            ->post(route('admin.smart-ordering.menu.quick-create'), [
                'store_id' => $fixture['store_id'],
                '_quick_menu' => '1',
                'menu_name_ar' => 'المنيو الرئيسية',
                'menu_name_en' => 'Main Menu',
                'menu_image' => UploadedFile::fake()->image('menu-cover.jpg', 1000, 700),
                'categories' => [
                    '0' => [
                        'name_ar' => 'الأطباق الرئيسية',
                        'name_en' => 'Main Dishes',
                        'image' => UploadedFile::fake()->image('mains.jpg', 800, 600),
                    ],
                    '1' => [
                        'name_ar' => 'المشروبات',
                        'name_en' => 'Drinks',
                    ],
                ],
                'items' => [
                    '0' => [
                        'category_key' => '0',
                        'name_ar' => 'برجر لحم',
                        'name_en' => 'Beef Burger',
                        'price' => '7.500',
                        'featured' => '1',
                        'image' => UploadedFile::fake()->image('burger.jpg', 700, 700),
                    ],
                    '1' => [
                        'category_key' => '1',
                        'name_ar' => 'كولا',
                        'name_en' => 'Cola',
                        'price' => '2.250',
                    ],
                ],
            ])
            ->assertRedirect();

        $draft = app(SmartOrderingMenuRevisionService::class)
            ->draft($fixture['admin'], $fixture['site']->fresh());

        $menu = SmartOrderingMenu::query()
            ->where('revision_id', $draft->id)
            ->where('name_en', 'Main Menu')
            ->firstOrFail();

        $this->assertTrue((bool) $menu->is_default);
        $this->assertTrue((bool) $menu->is_visible);
        $this->assertNotNull($menu->image_path);
        Storage::disk('public')->assertExists((string) $menu->image_path);

        $main = SmartOrderingMenuCategory::query()
            ->where('menu_id', $menu->id)
            ->where('name_en', 'Main Dishes')
            ->firstOrFail();
        $drinks = SmartOrderingMenuCategory::query()
            ->where('menu_id', $menu->id)
            ->where('name_en', 'Drinks')
            ->firstOrFail();

        $this->assertNotNull($main->image_path);
        Storage::disk('public')->assertExists((string) $main->image_path);

        $burger = SmartOrderingMenuItem::query()
            ->where('category_id', $main->id)
            ->where('name_en', 'Beef Burger')
            ->firstOrFail();
        $cola = SmartOrderingMenuItem::query()
            ->where('category_id', $drinks->id)
            ->where('name_en', 'Cola')
            ->firstOrFail();

        $this->assertSame(7.5, (float) $burger->price_override);
        $this->assertTrue((bool) $burger->featured);
        $this->assertNotNull($burger->image_path);
        Storage::disk('public')->assertExists((string) $burger->image_path);
        $this->assertSame(2.25, (float) $cola->price_override);

        $originalMenuImage = (string) $menu->image_path;
        $originalCategoryImage = (string) $main->image_path;
        $originalItemImage = (string) $burger->image_path;

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'setup' => 'menu',
            ]))
            ->assertOk()
            ->assertSee('data-qm-edit-menu="'.$menu->id.'"', false)
            ->assertSee('Edit menu', false);

        $this->actingAs($fixture['admin'])
            ->put(route('admin.smart-ordering.menu.quick-update', $menu), [
                'store_id' => $fixture['store_id'],
                '_quick_menu' => '1',
                '_quick_menu_mode' => 'edit',
                'quick_menu_id' => $menu->id,
                'return_to' => 'menu_setup',
                'menu_name_ar' => 'منيو الغداء',
                'menu_name_en' => 'Lunch Menu',
                'categories' => [
                    '0' => [
                        'id' => $main->id,
                        'name_ar' => 'الرئيسية',
                        'name_en' => 'Mains',
                    ],
                    '1' => [
                        'id' => $drinks->id,
                        'name_ar' => 'المشروبات الباردة',
                        'name_en' => 'Cold Drinks',
                    ],
                ],
                'items' => [
                    '0' => [
                        'id' => $burger->id,
                        'category_key' => '0',
                        'name_ar' => 'برجر لحم خاص',
                        'name_en' => 'Signature Beef Burger',
                        'price' => '8.250',
                        'featured' => '1',
                    ],
                    '1' => [
                        'id' => $cola->id,
                        'category_key' => '1',
                        'name_ar' => 'كولا',
                        'name_en' => 'Cola',
                        'price' => '2.500',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'setup' => 'menu',
            ]));

        $this->assertDatabaseHas('smart_ordering_menus', [
            'id' => $menu->id,
            'name_en' => 'Lunch Menu',
            'image_path' => $originalMenuImage,
        ]);
        $this->assertDatabaseHas('smart_ordering_menu_categories', [
            'id' => $main->id,
            'name_en' => 'Mains',
            'image_path' => $originalCategoryImage,
        ]);
        $this->assertDatabaseHas('smart_ordering_menu_items', [
            'id' => $burger->id,
            'name_en' => 'Signature Beef Burger',
            'image_path' => $originalItemImage,
            'featured' => true,
        ]);
        $this->assertSame(8.25, (float) $burger->fresh()->price_override);
        $this->assertSame(2.5, (float) $cola->fresh()->price_override);
    }

    public function test_guided_restaurant_launch_flow_keeps_menu_design_identity_review_and_publish_in_one_workspace(): void
    {
        $fixture = $this->fixture('GUIDED', 5.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        [, , , $item] = $this->draftItem($fixture, $writer, [
            'name_en' => 'Guided Burger',
            'price_override' => 5.000,
        ]);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'review' => 1,
            ]))
            ->assertOk()
            ->assertSee('data-restaurant-launch', false)
            ->assertSee('data-launch-step="review"', false)
            ->assertSee('Real preview on two devices')
            ->assertSee('<small>390px</small>', false)
            ->assertSee('<small>768px</small>', false)
            ->assertDontSee('Quick add / delete before publishing')
            ->assertDontSee('data-qm-open', false)
            ->assertDontSee(route('admin.smart-ordering.menu.items.destroy', $item), false);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'setup' => 'design',
            ]))
            ->assertOk()
            ->assertSee('data-launch-step="design"', false)
            ->assertSee(route('admin.smart-ordering.appearance.apply'), false)
            ->assertSee('name="return_to" value="menu_setup"', false)
            ->assertSee(route('admin.smart-ordering.appearance.preview'), false);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'setup' => 'identity',
            ]))
            ->assertOk()
            ->assertSee('data-launch-step="identity"', false)
            ->assertSee(route('admin.smart-ordering.menu.setup.identity'), false)
            ->assertSee('Save and continue to review');

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'setup' => 'publish',
            ]))
            ->assertOk()
            ->assertSee('data-launch-step="publish"', false)
            ->assertSee(route('admin.smart-ordering.menu.publish', app(SmartOrderingMenuRevisionService::class)->draft($fixture['admin'], $fixture['site'])), false);
    }

    public function test_guided_identity_step_saves_branding_and_slug_without_activating_runtime(): void
    {
        Storage::fake('public');
        $fixture = $this->fixture('IDENTITY', 5.000);
        $draft = app(SmartOrderingMenuRevisionService::class)->draft($fixture['admin'], $fixture['site']);
        $runtimeBefore = (string) $fixture['site']->runtime_state;
        $masterBefore = (bool) $fixture['site']->master_enabled;

        $this->actingAs($fixture['admin'])
            ->post(route('admin.smart-ordering.menu.setup.identity'), [
                'store_id' => $fixture['store_id'],
                'name_en' => 'Guided Kitchen',
                'name_ar' => 'مطعم الخطوات',
                'tagline_en' => 'One guided setup',
                'tagline_ar' => 'إعداد واحد واضح',
                'phone' => '+96522000000',
                'slug' => 'guided-kitchen-'.$fixture['store_id'],
                'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 700),
            ])
            ->assertRedirect(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'setup' => 'review',
            ]));

        $site = $fixture['site']->fresh();
        $this->assertSame('guided-kitchen-'.$fixture['store_id'], $site->slug);
        $this->assertSame($masterBefore, (bool) $site->master_enabled);
        $this->assertSame($runtimeBefore, (string) $site->runtime_state);

        $payload = (array) $draft->fresh()->payload;
        $identity = (array) data_get($payload, 'content.identity', []);
        $this->assertSame('Guided Kitchen', $identity['name_en'] ?? null);
        $this->assertSame('مطعم الخطوات', $identity['name_ar'] ?? null);
        $this->assertNotEmpty($identity['logo_url'] ?? null);
        $this->assertNotEmpty($identity['cover_url'] ?? null);
        Storage::disk('public')->assertExists((string) $identity['logo_url']);
        Storage::disk('public')->assertExists((string) $identity['cover_url']);
    }

    public function test_guided_menu_publish_keeps_runtime_off_until_restaurant_launch(): void
    {
        $fixture = $this->fixture('GUIDEDPUBLISH', 5.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draft] = $this->draftItem($fixture, $writer, [
            'name_en' => 'Launch Item',
            'price_override' => 5.000,
        ]);
        $runtimeBefore = (string) $fixture['site']->runtime_state;
        $masterBefore = (bool) $fixture['site']->master_enabled;

        $this->actingAs($fixture['admin'])
            ->post(route('admin.smart-ordering.menu.publish', $draft), [
                'store_id' => $fixture['store_id'],
                'guided' => '1',
            ])
            ->assertRedirect(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'setup' => 'publish',
            ]));

        $site = $fixture['site']->fresh();
        $this->assertSame($masterBefore, (bool) $site->master_enabled);
        $this->assertSame($runtimeBefore, (string) $site->runtime_state);
        $this->assertNotNull(app(SmartOrderingMenuRevisionService::class)->published($site));
        $this->assertDatabaseHas('smart_ordering_qr_codes', [
            'store_id' => $fixture['store_id'],
            'type' => 'generic',
            'source' => 'restaurant-launch',
            'is_enabled' => true,
        ]);
    }

    public function test_live_publish_surface_exposes_direct_site_qr_assets_and_downloads(): void
    {
        $fixture = $this->fixture('SITEQR', 5.000);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draft] = $this->draftItem($fixture, $writer, [
            'name_ar' => 'صنف مباشر',
            'name_en' => 'Direct item',
            'price_override' => 5.000,
        ]);

        app(SmartOrderingMenuRevisionService::class)->publish(
            $fixture['admin'],
            $fixture['site'],
            $draft,
        );
        $fixture['site']->forceFill([
            'master_enabled' => true,
            'runtime_state' => 'ENABLED',
        ])->save();

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'setup' => 'publish',
            ]))
            ->assertOk()
            ->assertSee('Live site QR')
            ->assertSee(route('admin.smart-ordering.menu.site-qr.svg', [
                'site' => $fixture['site'],
                'store_id' => $fixture['store_id'],
            ]), false)
            ->assertSee(route('admin.smart-ordering.menu.site-qr.pdf', [
                'site' => $fixture['site'],
                'store_id' => $fixture['store_id'],
            ]), false);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.site-qr.svg', [
                'site' => $fixture['site'],
                'store_id' => $fixture['store_id'],
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertSee('<svg', false);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.site-qr.pdf', [
                'site' => $fixture['site'],
                'store_id' => $fixture['store_id'],
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
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
                'legacy' => 1,
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
                'legacy' => 1,
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
            ->assertJsonPath('data.menus.0.image', '/smart-ordering/media/smart-ordering/menu-main.webp')
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

    public function test_public_menu_unpublish_fails_closed_and_preserves_private_editing(): void
    {
        $fixture = $this->fixture('UNPUBLISH', 6.750);
        $writer = app(SmartOrderingMenuWriteService::class);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        [$draft] = $this->draftItem($fixture, $writer);
        $published = $revisions->publish($fixture['admin'], $fixture['site']->fresh(), $draft->fresh());
        $this->enablePublicMenu($fixture);

        $publicUrl = route('public.smart-ordering.storefront', ['siteKey' => $fixture['site']->slug]);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $fixture['store_id']]))
            ->assertOk()
            ->assertSee($publicUrl, false)
            ->assertSee('Open public menu')
            ->assertSee('Manage share &amp; QR', false)
            ->assertSee('Unpublish public menu')
            ->assertSee('data-public-menu-publishing-actions', false);

        $this->actingAs($fixture['admin'])
            ->post(route('admin.smart-ordering.menu.unpublish'), [
                'store_id' => $fixture['store_id'],
            ])
            ->assertRedirect();

        $this->assertNull($revisions->published($fixture['site']->fresh()));
        $this->assertSame('archived', $published->fresh()->status);

        $this->get($publicUrl)->assertNotFound();

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $fixture['store_id']]))
            ->assertOk()
            ->assertSee('Public menu is unpublished')
            ->assertDontSee('data-public-menu-publishing-actions', false);

        $this->assertNull($revisions->published($fixture['site']->fresh()));
        $privateDraft = SmartOrderingRevision::query()
            ->where('site_id', $fixture['site']->id)
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($published->id, (int) $privateDraft->parent_revision_id);

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.index', ['store_id' => $fixture['store_id']]))
            ->assertOk()
            ->assertSee('Public menu is unpublished');

        $this->assertNull($revisions->published($fixture['site']->fresh()));
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

    public function test_restaurant_templates_complete_gallery_to_public_certification_journey(): void
    {
        $fixture = $this->fixture('TEMPLATE-QA', 5.750);
        $writer = app(SmartOrderingMenuWriteService::class);
        [$draft, , $category, $item] = $this->draftItem($fixture, $writer, [
            'name_en' => 'Charred Garden Plate',
            'name_ar' => 'طبق الحديقة المشوي',
            'description_en' => 'Seasonal vegetables, smoked tahini and herbs from local growers.',
            'description_ar' => 'خضروات موسمية وطحينة مدخنة وأعشاب من مزارع محلية.',
            'image_path' => null,
            'featured' => true,
            'vegetarian' => true,
            'allergens' => ['sesame'],
        ]);
        $writer->createItem($fixture['admin'], $fixture['site'], $draft, $category, [
            'name_en' => 'Sold Out Tasting Course',
            'name_ar' => 'طبق تذوق غير متاح',
            'description_en' => 'A sold-out state used by final template QA.',
            'description_ar' => 'حالة غير متاحة مستخدمة في فحص القوالب النهائي.',
            'image_path' => null,
            'price_override' => 8.500,
            'is_visible' => true,
            'sold_out' => true,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 1,
        ]);

        $longNameEn = 'Saffron & Stone Seasonal Dining House — Fire, Market Produce and Generous Hospitality';
        $longNameAr = 'دار زعفران وستون للمأكولات الموسمية — نكهات النار ومنتجات السوق وضيافة دافئة';

        $this->actingAs($fixture['admin'])
            ->put(route('admin.smart-ordering.revisions.content', $draft), [
                'name_en' => $longNameEn,
                'name_ar' => $longNameAr,
                'tagline_en' => 'One bilingual menu reused across every production template without content re-entry.',
                'tagline_ar' => 'قائمة ثنائية اللغة يعاد استخدامها في كل القوالب الإنتاجية دون إعادة إدخال المحتوى.',
                'description_en' => 'Final integration fixture for responsive, localization and publication certification.',
                'description_ar' => 'بيانات التكامل النهائي لاعتماد التجاوب والترجمة والنشر.',
                'phone' => '+96522000000',
                'whatsapp_url' => 'https://wa.me/96522000000',
                'about_en' => 'Long-form restaurant copy remains readable across compact and editorial compositions.',
                'about_ar' => 'يبقى وصف المطعم الطويل مقروءًا عبر التكوينات المدمجة والتحريرية.',
                'chef_note_en' => 'The chef changes the tasting sequence with the market.',
                'chef_note_ar' => 'يغير الشيف تسلسل التذوق حسب المتوفر في السوق.',
                'location_en' => 'Kuwait City',
                'location_ar' => 'مدينة الكويت',
                'map_url' => 'https://maps.google.com/?q=Kuwait+City',
                'reservation_cta_en' => 'Reserve your table',
                'reservation_cta_ar' => 'احجز طاولتك',
                'reservation_url' => 'https://example.test/reservations',
                'allergen_note_en' => 'Ask the team about allergens before ordering.',
                'allergen_note_ar' => 'اسأل الفريق عن مسببات الحساسية قبل الطلب.',
            ])
            ->assertRedirect();

        $themes = app(ThemeRegistry::class);
        $productionIds = $themes->productionIds();
        $this->assertCount(6, $productionIds);
        $this->assertCount(6, array_unique(array_map(
            fn (string $theme): string => (string) $themes->productionDefinition($theme)['view'],
            $productionIds,
        )));
        $this->assertCount(6, array_unique(array_map(
            fn (string $theme): string => (string) $themes->productionDefinition($theme)['layout'],
            $productionIds,
        )));

        $gallery = $this->actingAs($fixture['admin'])->get(route('admin.smart-ordering.appearance.index', [
            'site' => (string) $fixture['site']->slug,
            'lang' => 'en',
        ]));
        $gallery->assertOk()->assertSee('data-builder', false);
        foreach ($productionIds as $theme) {
            $gallery->assertSee('data-theme="'.$theme.'"', false);
        }

        $provider = app(StorefrontSnapshotProvider::class);
        $before = $provider->revision((string) $fixture['site']->slug, 'draft');
        $this->assertIsArray($before);
        $baselineMenu = $before['menu'];

        foreach ($productionIds as $theme) {
            $this->actingAs($fixture['admin'])
                ->post(route('admin.smart-ordering.appearance.apply'), [
                    'site' => (string) $fixture['site']->slug,
                    'theme' => $theme,
                    'lang' => 'en',
                    'device' => 'desktop',
                ])
                ->assertRedirect();

            $snapshot = $provider->revision((string) $fixture['site']->slug, 'draft');
            $this->assertIsArray($snapshot);
            $this->assertSame($theme, $snapshot['appearance']['template']);
            $this->assertSame($baselineMenu, $snapshot['menu'], 'Template selection must never mutate restaurant menu content.');

            foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
                $preview = $this->actingAs($fixture['admin'])->get(route('admin.smart-ordering.appearance.preview', [
                    'site' => (string) $fixture['site']->slug,
                    'theme' => $theme,
                    'lang' => $locale,
                    'revision' => 'draft',
                ]));

                $preview->assertOk()
                    ->assertHeader('Cache-Control', 'no-store, private')
                    ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                    ->assertSee('data-template="'.$theme.'"', false)
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee($locale === 'ar' ? $longNameAr : $longNameEn)
                    ->assertSee($locale === 'ar' ? 'طبق الحديقة المشوي' : 'Charred Garden Plate')
                    ->assertSee('/images/smart-ordering/menu-media-placeholder.svg', false)
                    ->assertDontSee('internal_id')
                    ->assertDontSee('tenant_id');
            }
        }

        $revisions = app(SmartOrderingMenuRevisionService::class);
        $currentDraft = $revisions->draft($fixture['admin'], $fixture['site']->fresh());
        $this->assertSame('chef-tasting', data_get($currentDraft->payload, 'appearance.template'));
        $revisions->publish($fixture['admin'], $fixture['site']->fresh(), $currentDraft->fresh());
        $this->enablePublicMenu($fixture);

        $publicEn = $this->get(route('public.smart-ordering.storefront', [
            'siteKey' => (string) $fixture['site']->slug,
            'lang' => 'en',
        ]));
        $publicEn->assertOk()
            ->assertSee('data-template="chef-tasting"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee($longNameEn)
            ->assertSee('Charred Garden Plate')
            ->assertSee('Sold Out Tasting Course')
            ->assertSee('/images/smart-ordering/menu-media-placeholder.svg', false)
            ->assertDontSee('internal_id')
            ->assertDontSee('tenant_id');

        $this->get(route('public.smart-ordering.storefront', [
            'siteKey' => (string) $fixture['site']->slug,
            'lang' => 'ar',
        ]))
            ->assertOk()
            ->assertSee('data-template="chef-tasting"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee($longNameAr)
            ->assertSee('طبق الحديقة المشوي');

        $nextDraft = $revisions->draft($fixture['admin'], $fixture['site']->fresh());
        $privateItem = SmartOrderingMenuItem::query()
            ->where('revision_id', $nextDraft->id)
            ->where('public_id', $item->public_id)
            ->firstOrFail();
        $writer->updateItem($fixture['admin'], $fixture['site']->fresh(), $nextDraft, $privateItem, [
            'name_en' => 'QA PRIVATE DRAFT ITEM',
        ]);
        $this->actingAs($fixture['admin'])
            ->post(route('admin.smart-ordering.appearance.apply'), [
                'site' => (string) $fixture['site']->slug,
                'theme' => 'modern-minimal',
                'lang' => 'en',
                'device' => 'mobile',
            ])
            ->assertRedirect();

        $this->get(route('public.smart-ordering.storefront', [
            'siteKey' => (string) $fixture['site']->slug,
            'lang' => 'en',
        ]))
            ->assertOk()
            ->assertSee('data-template="chef-tasting"', false)
            ->assertSee('Charred Garden Plate')
            ->assertDontSee('QA PRIVATE DRAFT ITEM');
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
