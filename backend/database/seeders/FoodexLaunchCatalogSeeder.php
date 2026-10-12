<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FoodexLaunchCatalogSeeder extends Seeder
{
    public const STORE_CODE = 'FOODEX-LAUNCH-B2C';
    public const CATALOG_CODE = 'foodex-launch';
    public const SKU_PREFIX = 'FDX-LAUNCH-';
    public const BANNER_PATH = 'foodex-launch/banners/hero.webp';

    public function run(): void
    {
        DB::transaction(function (): void {
            $fixture = $this->fixture();
            $this->assertSeedable($fixture);

            $storeId = $this->ensureStore();
            $catalogId = $this->ensureCatalog($storeId);
            $categoryId = $this->ensureCategory($catalogId, $fixture['category']);
            $unitId = $this->ensurePackUnit();
            $brandIds = [
                'FIRST_CHOICE' => $this->ensureBrand('first-choice', 'فرست تشويس', 'First Choice'),
                'FOODEX' => $this->ensureBrand('foodex', 'فوديكس', 'FOODEX'),
            ];

            foreach ($fixture['products'] as $index => $source) {
                $ordinal = $index + 1;
                $sku = self::SKU_PREFIX.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT);
                $brandKey = ($source['brand'] ?? 'FIRST_CHOICE') === 'FOODEX' ? 'FOODEX' : 'FIRST_CHOICE';

                DB::table('products')->updateOrInsert(
                    ['catalog_id' => $catalogId, 'sku' => $sku],
                    [
                        'category_id' => $categoryId,
                        'brand_id' => $brandIds[$brandKey],
                        'unit_id' => $unitId,
                        'name' => $source['name_ar'],
                        'description' => $this->description($source),
                        'low_stock_threshold' => 0,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $productId = (int) DB::table('products')
                    ->where('catalog_id', $catalogId)
                    ->where('sku', $sku)
                    ->value('id');

                DB::table('store_products')->updateOrInsert(
                    ['store_id' => $storeId, 'product_id' => $productId],
                    [
                        'price' => $source['price'],
                        'cost_price' => null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                DB::table('product_selling_units')->updateOrInsert(
                    ['product_id' => $productId, 'code' => 'PACK'],
                    [
                        'unit_id' => $unitId,
                        'name' => $source['package_ar'],
                        'conversion_factor' => 1,
                        'price' => $source['price'],
                        'sku' => $sku.'-PACK',
                        'barcode' => null,
                        'is_base' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                if (is_int($source['carton_count'] ?? null) && $source['carton_count'] > 0) {
                    DB::table('product_selling_units')->updateOrInsert(
                        ['product_id' => $productId, 'code' => 'CARTON'],
                        [
                            'unit_id' => $unitId,
                            'name' => 'كرتونة × '.$source['carton_count'],
                            'conversion_factor' => $source['carton_count'],
                            'price' => $source['price'],
                            'sku' => $sku.'-CARTON',
                            'barcode' => null,
                            'is_base' => false,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                } else {
                    DB::table('product_selling_units')
                        ->where('product_id', $productId)
                        ->where('code', 'CARTON')
                        ->delete();
                }

                $imagePath = 'foodex-launch/products/'.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT).'.webp';
                DB::table('product_images')->updateOrInsert(
                    ['product_id' => $productId, 'sort_order' => 1],
                    [
                        'path' => 'storage/'.$imagePath,
                        'is_primary' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $this->upsertTranslation("catalog.product.{$sku}.name", $source['name_ar'], $source['name_en']);
                $this->upsertTranslation("catalog.product.{$sku}.package", $source['package_ar'], $source['package_en']);
            }

            $this->ensureBanner($storeId);
        });
    }

    /**
     * Production launch readiness is intentionally stricter than seedability.
     *
     * @return array{ready:bool,unresolved_source_fields:list<string>,missing_images:list<string>,low_resolution_images:list<string>,banner_ready:bool}
     */
    public function readiness(): array
    {
        $fixture = $this->fixture();
        $minimum = (int) $fixture['source']['minimum_launch_image_px'];
        $unresolved = [];
        $missingImages = [];
        $lowResolution = [];

        foreach ($fixture['products'] as $index => $source) {
            $ordinal = $index + 1;
            $sku = self::SKU_PREFIX.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT);
            foreach ((array) ($source['review_required'] ?? []) as $field) {
                $unresolved[] = $sku.':'.$field;
            }
            if (($source['image_review_required'] ?? false) === true) {
                $unresolved[] = $sku.':image_mapping';
            }

            $relative = 'foodex-launch/products/'.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT).'.webp';
            $quality = $this->imageQuality($relative, $minimum);
            if ($quality === 'missing') {
                $missingImages[] = $sku;
            } elseif ($quality === 'low_resolution') {
                $lowResolution[] = $sku;
            }
        }

        $bannerReady = $this->imageQuality(self::BANNER_PATH, $minimum) === 'ready';

        return [
            'ready' => $unresolved === [] && $missingImages === [] && $lowResolution === [] && $bannerReady,
            'unresolved_source_fields' => $unresolved,
            'missing_images' => $missingImages,
            'low_resolution_images' => $lowResolution,
            'banner_ready' => $bannerReady,
        ];
    }

    public function assertReadyForLaunch(): void
    {
        $readiness = $this->readiness();
        if (! $readiness['ready']) {
            throw new RuntimeException('FOODEX launch catalog is not production-ready: '.json_encode($readiness, JSON_UNESCAPED_UNICODE));
        }
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        /** @var array<string, mixed> $fixture */
        $fixture = require database_path('seeders/fixtures/foodex-launch-catalog.php');

        return $fixture;
    }

    /** @param array<string, mixed> $fixture */
    private function assertSeedable(array $fixture): void
    {
        if (($fixture['source']['product_count'] ?? null) !== 25 || count($fixture['products'] ?? []) !== 25) {
            throw new RuntimeException('FOODEX launch fixture must contain exactly 25 source products.');
        }

        foreach ($fixture['products'] as $index => $product) {
            foreach (['name_ar', 'name_en', 'package_ar', 'package_en'] as $field) {
                if (! is_string($product[$field] ?? null) || trim((string) $product[$field]) === '') {
                    throw new RuntimeException('FOODEX launch fixture is missing '.$field.' at row '.($index + 1).'.');
                }
            }

            $review = (array) ($product['review_required'] ?? []);
            if (($product['price'] ?? null) === null && ! in_array('price', $review, true)) {
                throw new RuntimeException('Missing source price must be explicitly unresolved at row '.($index + 1).'.');
            }
            if (($product['carton_count'] ?? null) === null && ! in_array('carton_count', $review, true)) {
                throw new RuntimeException('Missing carton quantity must be explicitly unresolved at row '.($index + 1).'.');
            }
        }
    }

    private function ensureStore(): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId === 0) {
            throw new RuntimeException('B2C store type must exist before FOODEX launch seeding.');
        }

        DB::table('stores')->updateOrInsert(
            ['code' => self::STORE_CODE],
            [
                'store_type_id' => $typeId,
                'name' => 'FOODEX Launch Store',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('stores')->where('code', self::STORE_CODE)->value('id');
    }

    private function ensureCatalog(int $storeId): int
    {
        DB::table('catalogs')->updateOrInsert(
            ['store_id' => $storeId, 'code' => self::CATALOG_CODE],
            [
                'channel' => 'b2c',
                'name' => 'FOODEX Launch Catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('catalogs')
            ->where('store_id', $storeId)
            ->where('code', self::CATALOG_CODE)
            ->value('id');
    }

    /** @param array<string, mixed> $category */
    private function ensureCategory(int $catalogId, array $category): int
    {
        DB::table('categories')->updateOrInsert(
            ['catalog_id' => $catalogId, 'slug' => $category['slug']],
            [
                'parent_id' => null,
                'name' => $category['name_ar'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->upsertTranslation(
            'catalog.category.foodex-products.name',
            $category['name_ar'],
            $category['name_en'],
        );

        return (int) DB::table('categories')
            ->where('catalog_id', $catalogId)
            ->where('slug', $category['slug'])
            ->value('id');
    }

    private function ensurePackUnit(): int
    {
        DB::table('units')->updateOrInsert(
            ['scope_key' => 'global', 'code' => 'PACK'],
            [
                'store_id' => null,
                'scope' => 'global',
                'name' => 'Pack',
                'name_ar' => 'عبوة',
                'name_en' => 'Pack',
                'is_active' => true,
                'decimal_places' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('units')
            ->where('scope_key', 'global')
            ->where('code', 'PACK')
            ->value('id');
    }

    private function ensureBrand(string $slug, string $ar, string $en): int
    {
        DB::table('brands')->updateOrInsert(
            ['scope_key' => 'global', 'slug' => $slug],
            [
                'store_id' => null,
                'scope' => 'global',
                'name' => $en,
                'name_ar' => $ar,
                'name_en' => $en,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('brands')
            ->where('scope_key', 'global')
            ->where('slug', $slug)
            ->value('id');
    }

    private function ensureBanner(int $storeId): void
    {
        DB::table('banners')->updateOrInsert(
            ['store_id' => $storeId, 'sort_order' => 0],
            [
                'title' => 'FOODEX',
                'image_path' => 'storage/'.self::BANNER_PATH,
                'target_url' => '/products',
                'target_type' => 'url',
                'target_id' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function upsertTranslation(string $key, string $ar, string $en): void
    {
        DB::table('translations')->updateOrInsert(
            ['key' => $key],
            [
                'surface' => 'customer.catalog',
                'ar' => $ar,
                'en' => $en,
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /** @param array<string, mixed> $source */
    private function description(array $source): string
    {
        $carton = $source['carton_count'] ?? null;
        $cartonLabel = is_int($carton) ? (string) $carton : 'unresolved';

        return $source['package_ar'].' · carton '.$cartonLabel;
    }

    private function imageQuality(string $relativePath, int $minimum): string
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($relativePath)) {
            return 'missing';
        }

        $dimensions = @getimagesize($disk->path($relativePath));
        if (! is_array($dimensions)) {
            return 'low_resolution';
        }

        return $dimensions[0] >= $minimum && $dimensions[1] >= $minimum
            ? 'ready'
            : 'low_resolution';
    }
}
