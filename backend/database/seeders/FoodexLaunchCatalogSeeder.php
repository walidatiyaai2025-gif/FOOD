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
    public const PROVENANCE_LEDGER = 'seeders/fixtures/foodex-launch-catalog-provenance.json';
    public const IMAGE_MANIFEST = 'seeders/fixtures/foodex-launch-image-manifest.json';
    public const IMAGE_BUNDLE_DIRECTORY = 'seeders/fixtures/foodex-launch-image-bundle';

    public function run(): void
    {
        $fixture = $this->fixture();
        $this->assertSeedable($fixture);
        $this->assertProvenanceLedger($fixture);
        $imageBundle = $this->validatedImageBundle($fixture);

        DB::transaction(function () use ($fixture, $imageBundle): void {
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
                $imageAsset = $imageBundle['entries_by_sku'][$sku];
                $imageBytes = $imageBundle['asset_bytes_by_sku'][$sku];

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

                $imagePath = (string) $imageAsset['public_path'];
                if (! Storage::disk('public')->put($imagePath, $imageBytes)) {
                    throw new RuntimeException("FOODEX launch image staging failed for {$sku}.");
                }

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
     * @return array{
     *     ready:bool,
     *     unresolved_source_fields:list<string>,
     *     missing_images:list<string>,
     *     low_resolution_images:list<string>,
     *     checksum_mismatches:list<string>,
     *     image_provenance_errors:list<string>,
     *     product_images_ready:bool,
     *     image_manifest_sha256:?string,
     *     image_bundle_sha256:?string,
     *     banner_ready:bool
     * }
     */
    public function readiness(): array
    {
        $fixture = $this->fixture();
        $minimum = (int) $fixture['source']['minimum_launch_image_px'];
        $unresolved = [];

        foreach ($fixture['products'] as $index => $source) {
            $ordinal = $index + 1;
            $sku = self::SKU_PREFIX.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT);
            foreach ((array) ($source['review_required'] ?? []) as $field) {
                $unresolved[] = $sku.':'.$field;
            }
            if (($source['image_review_required'] ?? false) === true) {
                $unresolved[] = $sku.':image_mapping';
            }
        }

        $imageAudit = $this->imageManifestAudit($fixture, $minimum);
        $bannerReady = $this->imageQuality(self::BANNER_PATH, $minimum) === 'ready';

        return [
            'ready' => $unresolved === [] && $imageAudit['product_images_ready'] && $bannerReady,
            'unresolved_source_fields' => $unresolved,
            'missing_images' => $imageAudit['missing_images'],
            'low_resolution_images' => $imageAudit['low_resolution_images'],
            'checksum_mismatches' => $imageAudit['checksum_mismatches'],
            'image_provenance_errors' => $imageAudit['image_provenance_errors'],
            'product_images_ready' => $imageAudit['product_images_ready'],
            'image_manifest_sha256' => $imageAudit['image_manifest_sha256'],
            'image_bundle_sha256' => $imageAudit['image_bundle_sha256'],
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

    /** @return array<string, mixed> */
    public function imageManifest(): array
    {
        $path = database_path(self::IMAGE_MANIFEST);
        if (! is_file($path)) {
            throw new RuntimeException('FOODEX launch image manifest is missing.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('FOODEX launch image manifest is invalid JSON.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function provenanceLedger(): array
    {
        $path = database_path(self::PROVENANCE_LEDGER);
        if (! is_file($path)) {
            throw new RuntimeException('FOODEX launch provenance ledger is missing.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('FOODEX launch provenance ledger is invalid JSON.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $fixture */
    private function assertProvenanceLedger(array $fixture): void
    {
        $ledger = $this->provenanceLedger();
        $entries = (array) ($ledger['entries'] ?? []);
        $allowed = ['official', 'inferred', 'derived', 'reconstructed_asset'];
        $coverage = [];

        foreach ($entries as $entry) {
            $sku = (string) ($entry['product_identifier'] ?? '');
            $field = (string) ($entry['field'] ?? '');
            $state = (string) ($entry['provenance_state'] ?? '');
            if ($sku === '' || $field === '' || ! in_array($state, $allowed, true)) {
                throw new RuntimeException('FOODEX launch provenance ledger contains an invalid entry.');
            }
            $coverage[$sku][$field] = true;
        }

        foreach ($fixture['products'] as $index => $product) {
            $sku = self::SKU_PREFIX.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            foreach (['price', 'carton_count', 'image'] as $field) {
                if (! ($coverage[$sku][$field] ?? false)) {
                    throw new RuntimeException("FOODEX launch provenance missing {$sku}:{$field}.");
                }
            }
        }
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

    /**
     * @param array<string, mixed> $fixture
     * @return array{
     *     entries_by_sku:array<string, array<string, mixed>>,
     *     asset_bytes_by_sku:array<string, string>
     * }
     */
    private function validatedImageBundle(array $fixture): array
    {
        $audit = $this->imageManifestAudit(
            $fixture,
            (int) $fixture['source']['minimum_launch_image_px'],
        );

        if (! $audit['product_images_ready']) {
            throw new RuntimeException(
                'FOODEX launch image bundle is not production-ready: '.
                json_encode([
                    'missing_images' => $audit['missing_images'],
                    'low_resolution_images' => $audit['low_resolution_images'],
                    'checksum_mismatches' => $audit['checksum_mismatches'],
                    'image_provenance_errors' => $audit['image_provenance_errors'],
                ], JSON_UNESCAPED_UNICODE),
            );
        }

        return [
            'entries_by_sku' => $audit['entries_by_sku'],
            'asset_bytes_by_sku' => $audit['asset_bytes_by_sku'],
        ];
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array{
     *     product_images_ready:bool,
     *     missing_images:list<string>,
     *     low_resolution_images:list<string>,
     *     checksum_mismatches:list<string>,
     *     image_provenance_errors:list<string>,
     *     image_manifest_sha256:?string,
     *     image_bundle_sha256:?string,
     *     entries_by_sku:array<string, array<string, mixed>>,
     *     asset_bytes_by_sku:array<string, string>
     * }
     */
    private function imageManifestAudit(array $fixture, int $minimum): array
    {
        $missingImages = [];
        $lowResolution = [];
        $checksumMismatches = [];
        $provenanceErrors = [];
        $entriesBySku = [];
        $assetBytesBySku = [];

        $manifestPath = database_path(self::IMAGE_MANIFEST);
        $manifestSha = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : null;
        $bundleSha = null;

        try {
            $manifest = $this->imageManifest();
            $bundle = $this->readImageBundle($manifest);
            $bundleSha = $bundle['sha256'];
            $assets = $bundle['assets'];
        } catch (RuntimeException $exception) {
            return [
                'product_images_ready' => false,
                'missing_images' => [],
                'low_resolution_images' => [],
                'checksum_mismatches' => [],
                'image_provenance_errors' => ['manifest_or_bundle:'.$exception->getMessage()],
                'image_manifest_sha256' => $manifestSha,
                'image_bundle_sha256' => $bundleSha,
                'entries_by_sku' => [],
                'asset_bytes_by_sku' => [],
            ];
        }

        if (($manifest['schema_version'] ?? null) !== 1) {
            $provenanceErrors[] = 'manifest:schema_version';
        }
        if (($manifest['release'] ?? null) !== '1.0.77') {
            $provenanceErrors[] = 'manifest:release';
        }
        if (($manifest['source_document']['sha256'] ?? null) !== ($fixture['source']['document_sha256'] ?? null)) {
            $provenanceErrors[] = 'manifest:source_document_sha256';
        }
        if (($manifest['minimum_launch_image_px'] ?? null) !== $minimum) {
            $provenanceErrors[] = 'manifest:minimum_launch_image_px';
        }

        $entries = (array) ($manifest['entries'] ?? []);
        if (count($entries) !== 25) {
            $provenanceErrors[] = 'manifest:entry_count';
        }
        if (count($assets) !== 25) {
            $provenanceErrors[] = 'bundle:asset_count';
        }

        foreach ($entries as $entry) {
            $sku = (string) ($entry['product_identifier'] ?? '');
            if ($sku === '' || isset($entriesBySku[$sku])) {
                $provenanceErrors[] = $sku === '' ? 'manifest:blank_sku' : $sku.':duplicate';
                continue;
            }
            $entriesBySku[$sku] = (array) $entry;
        }

        foreach ($fixture['products'] as $index => $source) {
            $ordinal = $index + 1;
            $number = str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT);
            $sku = self::SKU_PREFIX.$number;
            $entry = $entriesBySku[$sku] ?? null;

            if (! is_array($entry)) {
                $missingImages[] = $sku;
                continue;
            }

            $expectedSource = $source['source_image'] ?? null;
            $expectedMethod = is_string($expectedSource)
                ? 'content_preserving_upscale_lanczos_unsharp'
                : 'source_missing_placeholder';

            if (($entry['product_name'] ?? null) !== ($source['name_en'] ?? null)) {
                $provenanceErrors[] = $sku.':product_name';
            }
            if (($entry['source_image'] ?? null) !== $expectedSource) {
                $provenanceErrors[] = $sku.':source_image';
            }
            if (($entry['method'] ?? null) !== $expectedMethod) {
                $provenanceErrors[] = $sku.':method';
            }
            if (($entry['bundle_key'] ?? null) !== $number) {
                $provenanceErrors[] = $sku.':bundle_key';
            }
            if (($entry['public_path'] ?? null) !== 'foodex-launch/products/'.$number.'.webp') {
                $provenanceErrors[] = $sku.':public_path';
            }

            if (is_string($expectedSource)) {
                $sourceDimensions = (array) ($entry['source_dimensions'] ?? []);
                if (($sourceDimensions['width'] ?? null) !== ($source['source_width'] ?? null)
                    || ($sourceDimensions['height'] ?? null) !== ($source['source_height'] ?? null)) {
                    $provenanceErrors[] = $sku.':source_dimensions';
                }
                if (! preg_match('/^[0-9a-f]{64}$/', (string) ($entry['source_sha256'] ?? ''))) {
                    $provenanceErrors[] = $sku.':source_sha256';
                }
            } else {
                if (($entry['source_dimensions'] ?? null) !== null) {
                    $provenanceErrors[] = $sku.':placeholder_source_dimensions';
                }
                if (($entry['source_sha256'] ?? null) !== null) {
                    $provenanceErrors[] = $sku.':placeholder_source_sha256';
                }
            }

            $encoded = $assets[$number] ?? null;
            if (! is_string($encoded) || $encoded === '') {
                $missingImages[] = $sku;
                continue;
            }

            $bytes = base64_decode($encoded, true);
            if (! is_string($bytes)) {
                $checksumMismatches[] = $sku;
                continue;
            }

            $actualSha = hash('sha256', $bytes);
            $expectedSha = (string) ($entry['sha256'] ?? '');
            if (! preg_match('/^[0-9a-f]{64}$/', $expectedSha) || $actualSha !== $expectedSha) {
                $checksumMismatches[] = $sku;
            }

            if (($entry['bytes'] ?? null) !== strlen($bytes)) {
                $provenanceErrors[] = $sku.':byte_length';
            }

            $dimensions = @getimagesizefromstring($bytes);
            if (! is_array($dimensions)
                || ($dimensions['mime'] ?? null) !== 'image/webp'
                || $dimensions[0] < $minimum
                || $dimensions[1] < $minimum) {
                $lowResolution[] = $sku;
            }

            $declaredDimensions = (array) ($entry['derivative_dimensions'] ?? []);
            if (! is_array($dimensions)
                || ($declaredDimensions['width'] ?? null) !== $dimensions[0]
                || ($declaredDimensions['height'] ?? null) !== $dimensions[1]) {
                $provenanceErrors[] = $sku.':derivative_dimensions';
            }

            $assetBytesBySku[$sku] = $bytes;
        }

        $productImagesReady = $missingImages === []
            && $lowResolution === []
            && $checksumMismatches === []
            && $provenanceErrors === []
            && count($entriesBySku) === 25
            && count($assetBytesBySku) === 25;

        return [
            'product_images_ready' => $productImagesReady,
            'missing_images' => array_values(array_unique($missingImages)),
            'low_resolution_images' => array_values(array_unique($lowResolution)),
            'checksum_mismatches' => array_values(array_unique($checksumMismatches)),
            'image_provenance_errors' => array_values(array_unique($provenanceErrors)),
            'image_manifest_sha256' => $manifestSha,
            'image_bundle_sha256' => $bundleSha,
            'entries_by_sku' => $entriesBySku,
            'asset_bytes_by_sku' => $assetBytesBySku,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{sha256:string,assets:array<string,string>}
     */
    private function readImageBundle(array $manifest): array
    {
        $directory = database_path(self::IMAGE_BUNDLE_DIRECTORY);
        $parts = glob($directory.'/*.part') ?: [];
        sort($parts, SORT_STRING);

        $expectedPartCount = (int) ($manifest['bundle']['part_count'] ?? 0);
        if ($expectedPartCount < 1 || count($parts) !== $expectedPartCount) {
            throw new RuntimeException('FOODEX launch image bundle part count does not match the manifest.');
        }

        $content = '';
        foreach ($parts as $part) {
            $chunk = @file_get_contents($part);
            if (! is_string($chunk)) {
                throw new RuntimeException('FOODEX launch image bundle contains an unreadable part.');
            }
            $content .= $chunk;
        }

        $actualSha = hash('sha256', $content);
        $expectedSha = (string) ($manifest['bundle']['sha256'] ?? '');
        if (! preg_match('/^[0-9a-f]{64}$/', $expectedSha) || ! hash_equals($expectedSha, $actualSha)) {
            throw new RuntimeException('FOODEX launch image bundle checksum mismatch.');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== 1
            || ($decoded['encoding'] ?? null) !== 'base64'
            || ! is_array($decoded['assets'] ?? null)) {
            throw new RuntimeException('FOODEX launch image bundle payload is invalid.');
        }

        if (($manifest['bundle']['decoded_asset_count'] ?? null) !== count($decoded['assets'])) {
            throw new RuntimeException('FOODEX launch image bundle asset count does not match the manifest.');
        }

        return [
            'sha256' => $actualSha,
            'assets' => $decoded['assets'],
        ];
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
