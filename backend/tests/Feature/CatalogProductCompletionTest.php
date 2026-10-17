<?php

namespace Tests\Feature;

use App\Enterprise\Reporting\ReportRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\CatalogImportRunService;
use App\Services\CatalogOwnership;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogProductCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_catalog_completion_dashboard_attributes_lifecycle_and_360_are_operational(): void
    {
        $user = $this->superAdmin('en');
        [$storeId, $catalogId, $unitId] = $this->catalogScope('CAT-COMPLETE');

        $this->actingAs($user)->post('/admin/catalog/categories', [
            'store_id' => $storeId,
            'name' => 'Beverages',
            'slug' => 'beverages-complete',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $categoryId = (int) DB::table('categories')->where('catalog_id', $catalogId)->where('slug', 'beverages-complete')->value('id');

        $this->actingAs($user)->post('/admin/catalog/products', [
            'store_id' => $storeId,
            'name' => 'Catalog Completion Water',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'price' => 1.250,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $productId = (int) DB::table('products')->where('catalog_id', $catalogId)->where('name', 'Catalog Completion Water')->value('id');

        $this->actingAs($user)
            ->get('/admin/catalog?tab=overview&store_id='.$storeId.'&support_access=1')
            ->assertOk()
            ->assertSee('data-foodex-module-overview', false)
            ->assertSee('Active SKUs')
            ->assertSee('Category coverage')
            ->assertSee('Catalog reports');

        $this->actingAs($user)->post('/admin/catalog/attributes', [
            'store_id' => $storeId,
            'category_id' => $categoryId,
            'code' => 'package_material',
            'data_type' => 'select',
            'label_ar' => 'مادة العبوة',
            'label_en' => 'Package material',
            'allowed_values' => "glass\nplastic",
            'is_required' => 1,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $attributeId = (int) DB::table('product_attribute_definitions')
            ->where('catalog_id', $catalogId)
            ->where('code', 'package_material')
            ->value('id');

        $this->actingAs($user)->put('/admin/catalog/products/'.$productId.'/attributes', [
            'attributes' => [$attributeId => 'glass'],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $productId,
            'attribute_definition_id' => $attributeId,
        ]);

        $this->actingAs($user)->post('/admin/catalog/products/'.$productId.'/lifecycle', [
            'state' => 'paused',
            'reason' => 'Seasonal pause',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'lifecycle_state' => 'paused',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('product_lifecycle_transitions', [
            'product_id' => $productId,
            'from_state' => 'active',
            'to_state' => 'paused',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'catalog.product.lifecycle.transitioned',
            'auditable_type' => 'App\\Models\\Product',
            'auditable_id' => $productId,
        ]);

        $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$storeId.'&support_access=1&product_detail='.$productId)
            ->assertOk()
            ->assertSee('data-catalog-product-360', false)
            ->assertSee('Product 360')
            ->assertSee('Package material')
            ->assertSee('Seasonal pause');

        $this->actingAs($user)
            ->get('/admin/catalog?tab=categories&store_id='.$storeId.'&support_access=1&category_detail='.$categoryId)
            ->assertOk()
            ->assertSee('data-catalog-category-360', false)
            ->assertSee('Category 360')
            ->assertSee('Beverages');

        $user->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($user)
            ->get('/admin/catalog?tab=overview&store_id='.$storeId.'&support_access=1')
            ->assertOk()
            ->assertSee('نظرة عامة على الكتالوج')
            ->assertSee('المنتجات النشطة')
            ->assertDontSee('Active SKUs');
    }

    public function test_catalog_import_confirmation_is_idempotent_and_persisted(): void
    {
        [, $catalogId, $unitId, $storeId] = $this->catalogScopeWithStoreLast('CAT-IMPORT');
        $unitCode = (string) DB::table('units')->where('id', $unitId)->value('code');
        $service = app(CatalogImportRunService::class);

        $path = tempnam(sys_get_temp_dir(), 'foodex-catalog-');
        $this->assertIsString($path);
        file_put_contents($path, $service->sampleZip($unitCode));

        try {
            $upload = new UploadedFile($path, 'catalog.zip', 'application/zip', null, true);
            $preview = $service->preview($upload, $storeId, $catalogId, 'b2c');
            $this->assertSame([], $preview['errors']);
            $this->assertNotEmpty($preview['token']);

            $first = $service->commit((string) $preview['token']);
            $second = $service->commit((string) $preview['token']);

            $this->assertSame($first, $second);
            $this->assertSame(1, DB::table('products')->where('catalog_id', $catalogId)->where('sku', 'PROD-001')->count());
            $this->assertDatabaseHas('catalog_import_runs', [
                'token' => $preview['token'],
                'store_id' => $storeId,
                'catalog_id' => $catalogId,
                'status' => 'committed',
            ]);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function test_catalog_reports_reuse_enterprise_bi_registry(): void
    {
        $reports = config('enterprise_reporting.reports');
        $this->assertIsArray($reports);

        foreach ([
            'catalog.availability',
            'catalog.category_brand_performance',
            'catalog.margin_exceptions',
            'catalog.lifecycle',
            'catalog.data_quality',
        ] as $code) {
            $this->assertArrayHasKey($code, $reports);
            $this->assertSame('catalog_operations', $reports[$code]['read_model']);
            $this->assertSame('reports.view', $reports[$code]['view_permission']);
            $this->assertSame('reports.export', $reports[$code]['export_permission']);
            $definition = app(ReportRegistry::class)->require($code);
            $this->assertSame($code, $definition->code);
        }
    }

    public function test_catalog_completion_locale_keys_are_exactly_in_parity(): void
    {
        $en = require lang_path('en/catalog_completion.php');
        $ar = require lang_path('ar/catalog_completion.php');

        $flatten = static function (array $values, string $prefix = '') use (&$flatten): array {
            $keys = [];
            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    $keys = [...$keys, ...$flatten($value, $path)];
                } else {
                    $keys[] = $path;
                }
            }

            sort($keys);

            return $keys;
        };

        $this->assertSame($flatten($en), $flatten($ar));
    }

    /** @return array{int,int,int} */
    private function catalogScope(string $code): array
    {
        [$storeId, $catalogId, $unitId] = $this->createCatalogScope($code);

        return [$storeId, $catalogId, $unitId];
    }

    /** @return array{int,int,int,int} */
    private function catalogScopeWithStoreLast(string $code): array
    {
        [$storeId, $catalogId, $unitId] = $this->createCatalogScope($code);

        return [$storeId, $catalogId, $unitId, $storeId];
    }

    /** @return array{int,int,int} */
    private function createCatalogScope(string $code): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId, 'b2c');

        $unitId = (int) DB::table('units')
            ->where('scope_key', 'global')
            ->where('is_active', true)
            ->value('id');
        if ($unitId < 1) {
            $unitId = (int) DB::table('units')->insertGetId([
                'store_id' => null,
                'scope' => 'global',
                'scope_key' => 'global',
                'code' => 'PCS',
                'name' => 'Piece',
                'name_ar' => 'قطعة',
                'name_en' => 'Piece',
                'decimal_places' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$storeId, (int) $catalog->id, $unitId];
    }

    private function superAdmin(string $locale): User
    {
        $user = User::query()->create([
            'name' => 'Catalog Completion Owner',
            'email' => 'catalog-completion-'.$locale.'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
