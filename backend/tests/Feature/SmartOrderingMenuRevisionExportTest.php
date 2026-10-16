<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionExportService;
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

class SmartOrderingMenuRevisionExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_export_is_deterministic_complete_and_private_identity_free(): void
    {
        $fixture = $this->fixture('EXP-A', 7.500);
        $published = $this->publishedTree($fixture);
        $exporter = app(SmartOrderingMenuRevisionExportService::class);

        $first = $exporter->export($fixture['site']->fresh(), $published->fresh());
        $secondJson = $exporter->json($fixture['site']->fresh(), $published->fresh());

        $this->assertSame(1, $first['schema_version']);
        $this->assertSame((string) $fixture['site']->public_id, $first['site_public_id']);
        $this->assertSame((string) $published->public_id, $first['revision']['public_id']);
        $this->assertSame((string) $published->checksum, $first['revision']['checksum']);
        $this->assertSame(
            json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $secondJson,
        );

        $contentJson = json_encode(
            $first['content'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $this->assertSame(hash('sha256', $contentJson), $first['content_checksum']);

        $records = $first['content']['records'];
        $identities = array_map(
            static fn (array $record): string => implode('|', [
                $record['entity'],
                $record['public_id'],
                $record['branch_public_id'] ?? '',
            ]),
            $records,
        );
        $sorted = $identities;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $identities);

        $entities = array_values(array_unique(array_column($records, 'entity')));
        foreach ([
            'menu',
            'menu_branch_scope',
            'menu_category',
            'category_branch_override',
            'menu_item',
            'item_branch_override',
            'modifier_group',
            'modifier_option',
            'modifier_option_branch_override',
        ] as $entity) {
            $this->assertContains($entity, $entities);
        }

        $item = $this->record($records, 'menu_item');
        $this->assertSame('SKU-EXP-A', $item['attributes']['source_product_sku']);
        $this->assertArrayNotHasKey('source_product_id', $item['attributes']);
        $this->assertNoInternalKeys($first);
    }

    public function test_equivalent_cloned_revision_has_the_same_export_content_checksum_despite_new_row_ids(): void
    {
        $fixture = $this->fixture('EXP-CLONE', 8.000);
        $published = $this->publishedTree($fixture);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $draft = $revisions->draft($fixture['admin'], $fixture['site']->fresh());
        $exporter = app(SmartOrderingMenuRevisionExportService::class);

        $publishedExport = $exporter->export($fixture['site']->fresh(), $published->fresh());
        $draftExport = $exporter->export($fixture['site']->fresh(), $draft->fresh());

        $this->assertNotSame($published->id, $draft->id);
        $this->assertNotSame((string) $published->public_id, (string) $draft->public_id);
        $this->assertSame($publishedExport['content'], $draftExport['content']);
        $this->assertSame($publishedExport['content_checksum'], $draftExport['content_checksum']);
    }

    public function test_export_fails_closed_for_a_revision_from_another_site(): void
    {
        $a = $this->fixture('EXP-XA', 4.000);
        $b = $this->fixture('EXP-XB', 4.000);
        $publishedA = $this->publishedTree($a);

        try {
            app(SmartOrderingMenuRevisionExportService::class)->export(
                $b['site']->fresh(),
                $publishedA->fresh(),
            );
            $this->fail('Cross-site revision export must fail closed.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    /**
     * @param  array{store_id:int,product_id:int,admin:User,site:SmartOrderingSite}  $fixture
     */
    private function publishedTree(array $fixture): SmartOrderingRevision
    {
        $writer = app(SmartOrderingMenuWriteService::class);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $branch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $fixture['site']->id,
            'code' => 'EXPORT-A',
            'name_en' => 'Export Branch',
            'timezone' => 'Asia/Kuwait',
            'is_default' => false,
            'is_active' => true,
        ]);
        $draft = $revisions->draft($fixture['admin'], $fixture['site']->fresh());

        $menu = $writer->createMenu($fixture['admin'], $fixture['site']->fresh(), $draft, [
            'name_en' => 'Export Menu',
            'description_en' => 'Stable export menu',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
            'branch_ids' => [$branch->id],
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
                'name_en' => 'Export Burger',
                'gallery' => ['/media/b.jpg', '/media/a.jpg'],
                'price_override' => 7.500,
                'is_visible' => true,
                'sold_out' => false,
                'allergens' => ['dairy' => false, 'gluten' => true],
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
            $branch->fresh(),
            ['sold_out' => false, 'sort_order' => 2],
        );
        $writer->setItemBranchOverride(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $item->fresh(),
            $branch->fresh(),
            ['price_override' => 7.750, 'prep_time_minutes' => 12],
        );
        $writer->setOptionBranchOverride(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
            $option->fresh(),
            $branch->fresh(),
            ['price_delta' => 1.250],
        );

        return $revisions->publish(
            $fixture['admin'],
            $fixture['site']->fresh(),
            $draft->fresh(),
        );
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
            'description' => 'Revision export source product',
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
            'name' => 'Export Admin '.$suffix,
            'email' => strtolower('export-'.$suffix).'@example.test',
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
     * @param list<array{
     *   entity:string,
     *   public_id:string,
     *   branch_public_id:?string,
     *   attributes:array<string,mixed>
     * }> $records
     * @return array{entity:string,public_id:string,branch_public_id:?string,attributes:array<string,mixed>}
     */
    private function record(array $records, string $entity): array
    {
        foreach ($records as $record) {
            if ($record['entity'] === $entity) {
                return $record;
            }
        }

        $this->fail('Expected export record was not found: '.$entity);

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
