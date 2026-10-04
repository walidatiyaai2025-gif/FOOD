<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class CatalogZipImportService
{
    private const MAX_ARCHIVE_BYTES = 52_428_800;

    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 73_400_320;

    private const MAX_ENTRY_BYTES = 5_242_880;

    private const MAX_WORKBOOK_BYTES = 5_242_880;

    private const MAX_ENTRIES = 10_000;

    private const MAX_ROWS_PER_SHEET = 5_000;

    /** @var array<string, string> */
    private const IMAGE_EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function sampleZip(string $unitCode = 'PCS'): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl+Y9sAAAAASUVORK5CYII=',
            true,
        );
        if (! is_string($png)) {
            throw new RuntimeException('Unable to build sample catalog image.');
        }

        $workbook = $this->buildWorkbook([
            'Products' => [
                ['sku', 'name', 'description', 'category_code', 'brand_code', 'unit_code', 'price', 'is_active', 'image_files'],
                ['PROD-001', 'Sample Water', 'Replace this sample row with your product.', 'CAT-001', 'BRAND-001', strtoupper(trim($unitCode)), '1.250', '1', 'sample-product.png'],
            ],
            'Categories' => [
                ['code', 'name', 'parent_code', 'is_active', 'image_file'],
                ['CAT-001', 'Beverages', '', '1', 'sample-category.png'],
            ],
            'Brands' => [
                ['code', 'name_ar', 'name_en', 'is_active', 'image_file'],
                ['BRAND-001', 'فودكس', 'Foodex', '1', 'sample-brand.png'],
            ],
            'Instructions' => [
                ['Rule', 'Value'],
                ['Required sheets', 'Products, Categories, Brands. Do not rename them.'],
                ['Stable keys', 'Products=sku, Categories=code, Brands=code. Re-import updates matching keys instead of duplicating them.'],
                ['Images', 'Put only JPG/JPEG/PNG/WEBP files in images/products, images/categories, or images/brands. Product image_files uses semicolon-separated filenames.'],
                ['Validation', 'Upload first creates a preview only. Nothing is persisted until you confirm Import.'],
                ['Units', 'unit_code must already exist and be active for the selected store/channel.'],
            ],
        ]);

        return $this->buildZip([
            'catalog.xlsx' => $workbook,
            'images/products/sample-product.png' => $png,
            'images/categories/sample-category.png' => $png,
            'images/brands/sample-brand.png' => $png,
        ]);
    }

    /**
     * @return array{token:?string,store_id:int,channel:string,counts:array<string,int>,errors:list<string>,warnings:list<string>,expires_at:?string}
     */
    public function preview(UploadedFile $archive, int $storeId, int $catalogId, string $channel): array
    {
        $realPath = $archive->getRealPath();
        if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
            throw ValidationException::withMessages(['catalog_zip' => ['The uploaded catalog ZIP could not be read.']]);
        }
        if ((int) filesize($realPath) > self::MAX_ARCHIVE_BYTES) {
            throw ValidationException::withMessages(['catalog_zip' => ['The catalog ZIP may not exceed 50 MB.']]);
        }

        $validated = $this->parseAndValidate($realPath, $storeId, $catalogId, strtolower($channel));
        $token = null;
        $expiresAt = null;

        if ($validated['errors'] === []) {
            $token = (string) Str::uuid();
            $expiresAt = now()->addHour()->toIso8601String();
            $base = "catalog-import-previews/{$token}";
            Storage::disk('local')->makeDirectory($base);

            $source = fopen($realPath, 'rb');
            if ($source === false) {
                throw ValidationException::withMessages(['catalog_zip' => ['The uploaded catalog ZIP could not be staged for confirmation.']]);
            }
            try {
                if (! Storage::disk('local')->writeStream("{$base}/package.zip", $source)) {
                    throw new RuntimeException('Unable to stage catalog import package.');
                }
            } finally {
                fclose($source);
            }

            $manifest = [
                'version' => 1,
                'token' => $token,
                'store_id' => $storeId,
                'catalog_id' => $catalogId,
                'channel' => strtolower($channel),
                'created_at' => now()->toIso8601String(),
                'expires_at' => $expiresAt,
            ];
            Storage::disk('local')->put(
                "{$base}/manifest.json",
                json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        }

        return [
            'token' => $token,
            'store_id' => $storeId,
            'channel' => strtolower($channel),
            'counts' => $validated['counts'],
            'errors' => $validated['errors'],
            'warnings' => $validated['warnings'],
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array<string, mixed> */
    public function manifest(string $token): array
    {
        $this->assertToken($token);
        $path = "catalog-import-previews/{$token}/manifest.json";
        if (! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages(['preview_token' => ['The catalog import preview is missing or has already been used.']]);
        }

        $decoded = json_decode((string) Storage::disk('local')->get($path), true);
        if (! is_array($decoded) || (int) ($decoded['version'] ?? 0) !== 1) {
            throw ValidationException::withMessages(['preview_token' => ['The catalog import preview manifest is invalid.']]);
        }
        $expiresAt = strtotime((string) ($decoded['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            Storage::disk('local')->deleteDirectory("catalog-import-previews/{$token}");
            throw ValidationException::withMessages(['preview_token' => ['The catalog import preview expired. Upload the ZIP again.']]);
        }

        return $decoded;
    }

    /**
     * @return array{counts:array<string,int>,media_errors:list<string>}
     */
    public function commit(string $token): array
    {
        $manifest = $this->manifest($token);
        $package = Storage::disk('local')->path("catalog-import-previews/{$token}/package.zip");
        if (! is_file($package)) {
            throw ValidationException::withMessages(['preview_token' => ['The staged catalog ZIP is missing. Upload it again.']]);
        }

        $storeId = (int) $manifest['store_id'];
        $catalogId = (int) $manifest['catalog_id'];
        $channel = (string) $manifest['channel'];

        $currentCatalog = DB::table('catalogs')
            ->where('id', $catalogId)
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->where('is_migration_quarantine', false)
            ->first(['id']);
        if ($currentCatalog === null) {
            throw ValidationException::withMessages(['preview_token' => ['The catalog scope changed after preview. Upload the ZIP again.']]);
        }

        $validated = $this->parseAndValidate($package, $storeId, $catalogId, $channel);
        if ($validated['errors'] !== []) {
            throw ValidationException::withMessages([
                'catalog_zip' => array_slice($validated['errors'], 0, 20),
            ]);
        }

        $rows = $validated['rows'];
        $mutationCounts = [
            'categories_created' => 0,
            'categories_updated' => 0,
            'brands_created' => 0,
            'brands_updated' => 0,
            'products_created' => 0,
            'products_updated' => 0,
        ];

        $entityMap = DB::transaction(function () use ($rows, $storeId, $catalogId, $channel, &$mutationCounts): array {
            $categoryIds = [];
            foreach ($rows['Categories'] as $row) {
                $code = $this->normalizeCode($row['code']);
                $existing = $this->findCategory($catalogId, $code);
                $payload = [
                    'catalog_id' => $catalogId,
                    'name' => $row['name'],
                    'slug' => $code,
                    'is_active' => $row['is_active'],
                    'updated_at' => now(),
                ];
                if ($existing === null) {
                    $payload['created_at'] = now();
                    $id = (int) DB::table('categories')->insertGetId($payload);
                    $mutationCounts['categories_created']++;
                } else {
                    $id = (int) $existing->id;
                    DB::table('categories')->where('id', $id)->update($payload);
                    $mutationCounts['categories_updated']++;
                }
                $categoryIds[$code] = $id;
            }

            foreach ($rows['Categories'] as $row) {
                $code = $this->normalizeCode($row['code']);
                $parentCode = $this->normalizeNullableCode($row['parent_code']);
                $parentId = null;
                if ($parentCode !== null) {
                    $parentId = $categoryIds[$parentCode] ?? (int) optional($this->findCategory($catalogId, $parentCode))->id;
                    if ($parentId <= 0) {
                        throw new RuntimeException("Category parent disappeared during import: {$parentCode}");
                    }
                }
                DB::table('categories')->where('id', $categoryIds[$code])->update([
                    'parent_id' => $parentId,
                    'updated_at' => now(),
                ]);
            }

            $brandIds = [];
            [$brandScope, $brandScopeKey, $brandStoreId] = $this->brandScope($storeId, $channel);
            foreach ($rows['Brands'] as $row) {
                $code = $this->normalizeCode($row['code']);
                $existing = DB::table('brands')->where('scope_key', $brandScopeKey)->whereRaw('LOWER(slug) = ?', [$code])->first(['id']);
                $fallbackName = $row['name_en'] !== '' ? $row['name_en'] : $row['name_ar'];
                $payload = [
                    'store_id' => $brandStoreId,
                    'scope' => $brandScope,
                    'scope_key' => $brandScopeKey,
                    'name' => $fallbackName,
                    'name_ar' => $row['name_ar'] !== '' ? $row['name_ar'] : $fallbackName,
                    'name_en' => $row['name_en'] !== '' ? $row['name_en'] : $fallbackName,
                    'slug' => $code,
                    'is_active' => $row['is_active'],
                    'updated_at' => now(),
                ];
                if ($existing === null) {
                    $payload['created_at'] = now();
                    $id = (int) DB::table('brands')->insertGetId($payload);
                    $mutationCounts['brands_created']++;
                } else {
                    $id = (int) $existing->id;
                    DB::table('brands')->where('id', $id)->update($payload);
                    $mutationCounts['brands_updated']++;
                }
                $brandIds[$code] = $id;
            }

            $productIds = [];
            foreach ($rows['Products'] as $row) {
                $sku = $this->normalizeSku($row['sku']);
                $existing = $this->findProduct($catalogId, $sku);
                $categoryCode = $this->normalizeNullableCode($row['category_code']);
                $brandCode = $this->normalizeNullableCode($row['brand_code']);
                $categoryId = $categoryCode === null
                    ? null
                    : ($categoryIds[$categoryCode] ?? (int) optional($this->findCategory($catalogId, $categoryCode))->id);
                $brandId = $brandCode === null
                    ? null
                    : ($brandIds[$brandCode] ?? $this->resolveBrandId($brandCode, $storeId, $channel));
                $unitId = $this->resolveUnitId($row['unit_code'], $storeId, $channel);

                if ($categoryCode !== null && $categoryId <= 0) {
                    throw new RuntimeException("Category disappeared during import: {$categoryCode}");
                }
                if ($brandCode !== null && $brandId <= 0) {
                    throw new RuntimeException("Brand disappeared during import: {$brandCode}");
                }
                if ($unitId <= 0) {
                    throw new RuntimeException("Unit disappeared during import: {$row['unit_code']}");
                }

                $payload = [
                    'catalog_id' => $catalogId,
                    'category_id' => $categoryId,
                    'brand_id' => $brandId ?: null,
                    'unit_id' => $unitId,
                    'sku' => $sku,
                    'name' => $row['name'],
                    'description' => $row['description'] !== '' ? $row['description'] : null,
                    'is_active' => $row['is_active'],
                    'updated_at' => now(),
                ];
                if ($existing === null) {
                    $payload['created_at'] = now();
                    $id = (int) DB::table('products')->insertGetId($payload);
                    $mutationCounts['products_created']++;
                } else {
                    $id = (int) $existing->id;
                    DB::table('products')->where('id', $id)->update($payload);
                    $mutationCounts['products_updated']++;
                }
                $productIds[$sku] = $id;

                $assignment = DB::table('store_products')->where('store_id', $storeId)->where('product_id', $id)->first(['id']);
                $assignmentPayload = [
                    'price' => $row['price'],
                    'is_active' => $row['is_active'],
                    'updated_at' => now(),
                ];
                if ($assignment === null) {
                    DB::table('store_products')->insert([
                        'store_id' => $storeId,
                        'product_id' => $id,
                        ...$assignmentPayload,
                        'created_at' => now(),
                    ]);
                } else {
                    DB::table('store_products')->where('id', $assignment->id)->update($assignmentPayload);
                }
            }

            return ['categories' => $categoryIds, 'brands' => $brandIds, 'products' => $productIds];
        });

        $zip = $this->readZipFile($package);
        $mediaErrors = [];

        foreach ($rows['Categories'] as $row) {
            if ($row['image_file'] === '') {
                continue;
            }
            try {
                $code = $this->normalizeCode($row['code']);
                $this->replaceEntityImage(
                    'categories',
                    (int) $entityMap['categories'][$code],
                    "images/categories/{$row['image_file']}",
                    $zip,
                );
            } catch (Throwable $exception) {
                $mediaErrors[] = "Category {$row['code']}: {$exception->getMessage()}";
            }
        }

        foreach ($rows['Brands'] as $row) {
            if ($row['image_file'] === '') {
                continue;
            }
            try {
                $code = $this->normalizeCode($row['code']);
                $this->replaceEntityImage(
                    'brands',
                    (int) $entityMap['brands'][$code],
                    "images/brands/{$row['image_file']}",
                    $zip,
                );
            } catch (Throwable $exception) {
                $mediaErrors[] = "Brand {$row['code']}: {$exception->getMessage()}";
            }
        }

        foreach ($rows['Products'] as $row) {
            try {
                $sku = $this->normalizeSku($row['sku']);
                $this->syncProductImages(
                    (int) $entityMap['products'][$sku],
                    $row['image_files'],
                    $zip,
                );
            } catch (Throwable $exception) {
                $mediaErrors[] = "Product {$row['sku']}: {$exception->getMessage()}";
            }
        }

        Storage::disk('local')->deleteDirectory("catalog-import-previews/{$token}");

        return [
            'counts' => [
                ...$validated['counts'],
                ...$mutationCounts,
            ],
            'media_errors' => $mediaErrors,
        ];
    }

    /**
     * @return array{rows:array{Products:list<array<string,mixed>>,Categories:list<array<string,mixed>>,Brands:list<array<string,mixed>>},counts:array<string,int>,errors:list<string>,warnings:list<string>}
     */
    private function parseAndValidate(string $archivePath, int $storeId, int $catalogId, string $channel): array
    {
        $errors = [];
        $warnings = [];
        try {
            $zip = $this->readZipFile($archivePath);
        } catch (Throwable $exception) {
            return [
                'rows' => ['Products' => [], 'Categories' => [], 'Brands' => []],
                'counts' => ['products' => 0, 'categories' => 0, 'brands' => 0, 'images' => 0],
                'errors' => [$exception->getMessage()],
                'warnings' => [],
            ];
        }

        foreach (array_keys($zip['entries']) as $path) {
            if (! $this->isAllowedPackagePath($path)) {
                $errors[] = "Unexpected file in ZIP: {$path}";
            }
        }
        if (! isset($zip['entries']['catalog.xlsx'])) {
            $errors[] = 'catalog.xlsx is required at the ZIP root.';
        }
        if ($errors !== []) {
            return [
                'rows' => ['Products' => [], 'Categories' => [], 'Brands' => []],
                'counts' => ['products' => 0, 'categories' => 0, 'brands' => 0, 'images' => 0],
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $workbookEntry = $zip['entries']['catalog.xlsx'];
        if ((int) $workbookEntry['uncompressed_size'] > self::MAX_WORKBOOK_BYTES) {
            $errors[] = 'catalog.xlsx may not exceed 5 MB uncompressed.';
            return [
                'rows' => ['Products' => [], 'Categories' => [], 'Brands' => []],
                'counts' => ['products' => 0, 'categories' => 0, 'brands' => 0, 'images' => 0],
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        try {
            $xlsx = $this->readZipBytes($this->entryBytes($zip, 'catalog.xlsx'));
            $rows = [
                'Products' => $this->sheetRows($xlsx, 'Products'),
                'Categories' => $this->sheetRows($xlsx, 'Categories'),
                'Brands' => $this->sheetRows($xlsx, 'Brands'),
            ];
        } catch (Throwable $exception) {
            return [
                'rows' => ['Products' => [], 'Categories' => [], 'Brands' => []],
                'counts' => ['products' => 0, 'categories' => 0, 'brands' => 0, 'images' => 0],
                'errors' => ['catalog.xlsx is invalid: '.$exception->getMessage()],
                'warnings' => $warnings,
            ];
        }

        $this->requireHeaders($rows['Products'], ['sku', 'name', 'description', 'category_code', 'brand_code', 'unit_code', 'price', 'is_active', 'image_files'], 'Products', $errors);
        $this->requireHeaders($rows['Categories'], ['code', 'name', 'parent_code', 'is_active', 'image_file'], 'Categories', $errors);
        $this->requireHeaders($rows['Brands'], ['code', 'name_ar', 'name_en', 'is_active', 'image_file'], 'Brands', $errors);

        $products = $this->normalizeSheetData($rows['Products']);
        $categories = $this->normalizeSheetData($rows['Categories']);
        $brands = $this->normalizeSheetData($rows['Brands']);
        $normalizedRows = ['Products' => [], 'Categories' => [], 'Brands' => []];
        $referencedImages = [];

        $categoryCodes = [];
        foreach ($categories as $index => $row) {
            $line = $index + 2;
            $code = $this->normalizeNullableCode($row['code'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            if ($code === null || ! $this->validCode($code)) {
                $errors[] = "Categories row {$line}: code is required and may contain only letters, numbers, dot, underscore, and hyphen.";
                continue;
            }
            if (isset($categoryCodes[$code])) {
                $errors[] = "Categories row {$line}: duplicate code {$code}.";
            }
            $categoryCodes[$code] = true;
            if ($name === '') {
                $errors[] = "Categories row {$line}: name is required.";
            }
            $parentCode = $this->normalizeNullableCode($row['parent_code'] ?? '');
            if ($parentCode !== null && ! $this->validCode($parentCode)) {
                $errors[] = "Categories row {$line}: parent_code is invalid.";
            }
            $active = $this->parseBoolean($row['is_active'] ?? '', "Categories row {$line}", $errors);
            $image = trim((string) ($row['image_file'] ?? ''));
            if ($image !== '') {
                if (! $this->validImageFilename($image)) {
                    $errors[] = "Categories row {$line}: image_file must be a JPG/JPEG/PNG/WEBP filename without folders.";
                } else {
                    $path = "images/categories/{$image}";
                    $referencedImages[$path] = true;
                    $this->validateImageEntry($zip, $path, "Categories row {$line}", $errors);
                }
            }
            $normalizedRows['Categories'][] = [
                'code' => $code,
                'name' => $name,
                'parent_code' => $parentCode ?? '',
                'is_active' => $active,
                'image_file' => $image,
            ];
        }

        $existingCategories = DB::table('categories')
            ->where('catalog_id', $catalogId)
            ->get(['id', 'slug', 'parent_id']);
        $existingCategoryCodes = [];
        $idToCode = [];
        foreach ($existingCategories as $category) {
            $normalized = $this->normalizeCode((string) $category->slug);
            $existingCategoryCodes[$normalized] = true;
            $idToCode[(int) $category->id] = $normalized;
        }
        foreach ($normalizedRows['Categories'] as $row) {
            $parentCode = $this->normalizeNullableCode($row['parent_code']);
            if ($parentCode !== null && ! isset($categoryCodes[$parentCode]) && ! isset($existingCategoryCodes[$parentCode])) {
                $errors[] = "Category {$row['code']}: parent_code {$parentCode} does not exist in this package or catalog.";
            }
        }
        $this->validateCategoryGraph($normalizedRows['Categories'], $existingCategories->all(), $idToCode, $errors);

        $brandCodes = [];
        foreach ($brands as $index => $row) {
            $line = $index + 2;
            $code = $this->normalizeNullableCode($row['code'] ?? '');
            $nameAr = trim((string) ($row['name_ar'] ?? ''));
            $nameEn = trim((string) ($row['name_en'] ?? ''));
            if ($code === null || ! $this->validCode($code)) {
                $errors[] = "Brands row {$line}: code is required and invalid.";
                continue;
            }
            if (isset($brandCodes[$code])) {
                $errors[] = "Brands row {$line}: duplicate code {$code}.";
            }
            $brandCodes[$code] = true;
            if ($nameAr === '' && $nameEn === '') {
                $errors[] = "Brands row {$line}: at least one of name_ar or name_en is required.";
            }
            $active = $this->parseBoolean($row['is_active'] ?? '', "Brands row {$line}", $errors);
            $image = trim((string) ($row['image_file'] ?? ''));
            if ($image !== '') {
                if (! $this->validImageFilename($image)) {
                    $errors[] = "Brands row {$line}: image_file must be a JPG/JPEG/PNG/WEBP filename without folders.";
                } else {
                    $path = "images/brands/{$image}";
                    $referencedImages[$path] = true;
                    $this->validateImageEntry($zip, $path, "Brands row {$line}", $errors);
                }
            }
            $normalizedRows['Brands'][] = [
                'code' => $code,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'is_active' => $active,
                'image_file' => $image,
            ];
        }

        $productSkus = [];
        foreach ($products as $index => $row) {
            $line = $index + 2;
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($sku === '' || ! $this->validSku($sku)) {
                $errors[] = "Products row {$line}: sku is required and invalid.";
                continue;
            }
            $normalizedSku = $this->normalizeSku($sku);
            if (isset($productSkus[$normalizedSku])) {
                $errors[] = "Products row {$line}: duplicate sku {$normalizedSku}.";
            }
            $productSkus[$normalizedSku] = true;
            if ($name === '') {
                $errors[] = "Products row {$line}: name is required.";
            }

            $categoryCode = $this->normalizeNullableCode($row['category_code'] ?? '');
            if ($categoryCode !== null && ! isset($categoryCodes[$categoryCode]) && ! isset($existingCategoryCodes[$categoryCode])) {
                $errors[] = "Products row {$line}: category_code {$categoryCode} is not available in the selected catalog.";
            }

            $brandCode = $this->normalizeNullableCode($row['brand_code'] ?? '');
            if ($brandCode !== null && ! isset($brandCodes[$brandCode]) && $this->resolveBrandId($brandCode, $storeId, $channel) <= 0) {
                $errors[] = "Products row {$line}: brand_code {$brandCode} is not available for the selected store/channel.";
            }

            $unitCode = trim((string) ($row['unit_code'] ?? ''));
            if ($unitCode === '' || $this->resolveUnitId($unitCode, $storeId, $channel) <= 0) {
                $errors[] = "Products row {$line}: unit_code {$unitCode} is missing, inactive, or outside the selected store/channel.";
            }

            $priceRaw = trim((string) ($row['price'] ?? ''));
            $price = null;
            if ($priceRaw !== '') {
                if (! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                    $errors[] = "Products row {$line}: price must be zero or a positive number.";
                } else {
                    $price = round((float) $priceRaw, 3);
                }
            }
            $active = $this->parseBoolean($row['is_active'] ?? '', "Products row {$line}", $errors);

            $imageFiles = $this->parseImageList((string) ($row['image_files'] ?? ''));
            if (count($imageFiles) > 8) {
                $errors[] = "Products row {$line}: image_files may contain at most 8 images.";
            }
            foreach ($imageFiles as $image) {
                if (! $this->validImageFilename($image)) {
                    $errors[] = "Products row {$line}: invalid image filename {$image}.";
                    continue;
                }
                $path = "images/products/{$image}";
                $referencedImages[$path] = true;
                $this->validateImageEntry($zip, $path, "Products row {$line}", $errors);
            }

            $normalizedRows['Products'][] = [
                'sku' => $normalizedSku,
                'name' => $name,
                'description' => trim((string) ($row['description'] ?? '')),
                'category_code' => $categoryCode ?? '',
                'brand_code' => $brandCode ?? '',
                'unit_code' => $unitCode,
                'price' => $price,
                'is_active' => $active,
                'image_files' => $imageFiles,
            ];
        }

        $imageCount = 0;
        foreach ($zip['entries'] as $path => $entry) {
            if (! str_starts_with($path, 'images/')) {
                continue;
            }
            $imageCount++;
            if (! isset($referencedImages[$path])) {
                $warnings[] = "Image is present but not referenced by catalog.xlsx: {$path}";
            }
        }

        return [
            'rows' => $normalizedRows,
            'counts' => [
                'products' => count($normalizedRows['Products']),
                'categories' => count($normalizedRows['Categories']),
                'brands' => count($normalizedRows['Brands']),
                'images' => $imageCount,
            ],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $required @param list<string> $errors */
    private function requireHeaders(array $rows, array $required, string $sheet, array &$errors): void
    {
        if ($rows === []) {
            $errors[] = "Sheet {$sheet} is missing or empty.";
            return;
        }
        $headers = array_keys($rows[0]);
        foreach ($required as $header) {
            if (! in_array($header, $headers, true)) {
                $errors[] = "Sheet {$sheet} is missing required column {$header}.";
            }
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,string>> */
    private function normalizeSheetData(array $rows): array
    {
        return array_values(array_filter(array_map(static function (array $row): array {
            $normalized = [];
            foreach ($row as $key => $value) {
                $normalized[strtolower(trim((string) $key))] = trim((string) $value);
            }
            return $normalized;
        }, $rows), static fn (array $row): bool => collect($row)->contains(static fn ($value): bool => trim((string) $value) !== '')));
    }

    /**
     * @param list<array<string,mixed>> $incoming
     * @param list<object> $existing
     * @param array<int,string> $idToCode
     * @param list<string> $errors
     */
    private function validateCategoryGraph(array $incoming, array $existing, array $idToCode, array &$errors): void
    {
        $parents = [];
        foreach ($existing as $row) {
            $code = $this->normalizeCode((string) $row->slug);
            $parents[$code] = $row->parent_id === null ? null : ($idToCode[(int) $row->parent_id] ?? null);
        }
        foreach ($incoming as $row) {
            $parents[$this->normalizeCode($row['code'])] = $this->normalizeNullableCode($row['parent_code']);
        }

        foreach (array_keys($parents) as $start) {
            $seen = [];
            $cursor = $start;
            while ($cursor !== null && isset($parents[$cursor])) {
                if (isset($seen[$cursor])) {
                    $errors[] = "Category hierarchy contains a cycle involving {$cursor}.";
                    break;
                }
                $seen[$cursor] = true;
                $cursor = $parents[$cursor];
            }
        }
    }

    /** @param list<string> $errors */
    private function parseBoolean(mixed $value, string $context, array &$errors): bool
    {
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'active', 'نعم'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'inactive', 'لا'], true)) {
            return false;
        }
        $errors[] = "{$context}: is_active must be 1/0, true/false, yes/no, or active/inactive.";

        return false;
    }

    /** @return list<string> */
    private function parseImageList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $files = array_map('trim', explode(';', $value));
        $files = array_values(array_filter($files, static fn (string $file): bool => $file !== ''));

        return array_values(array_unique($files));
    }

    /** @param array<string,mixed> $zip @param list<string> $errors */
    private function validateImageEntry(array $zip, string $path, string $context, array &$errors): void
    {
        if (! isset($zip['entries'][$path])) {
            $errors[] = "{$context}: referenced image is missing: {$path}";
            return;
        }
        $entry = $zip['entries'][$path];
        if ((int) $entry['uncompressed_size'] > self::MAX_ENTRY_BYTES) {
            $errors[] = "{$context}: image exceeds 5 MB: {$path}";
            return;
        }
        try {
            $bytes = $this->entryBytes($zip, $path);
        } catch (Throwable $exception) {
            $errors[] = "{$context}: image could not be read: {$path}";
            return;
        }
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! isset(self::IMAGE_EXTENSIONS[$extension]) || self::IMAGE_EXTENSIONS[$extension] !== $mime) {
            $errors[] = "{$context}: image content/type does not match an allowed JPG/JPEG/PNG/WEBP file: {$path}";
        }
    }

    private function isAllowedPackagePath(string $path): bool
    {
        if ($path === 'catalog.xlsx') {
            return true;
        }

        return preg_match('#^images/(products|categories|brands)/[^/]+\.(?:jpe?g|png|webp)$#i', $path) === 1;
    }

    private function validImageFilename(string $filename): bool
    {
        return basename($filename) === $filename
            && ! str_contains($filename, '\\')
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,199}\.(?:jpe?g|png|webp)$/i', $filename) === 1;
    }

    private function validCode(string $code): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $code) === 1;
    }

    private function validSku(string $sku): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $sku) === 1;
    }

    private function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    private function normalizeNullableCode(mixed $code): ?string
    {
        $value = $this->normalizeCode((string) $code);

        return $value === '' ? null : $value;
    }

    private function normalizeSku(string $sku): string
    {
        return strtoupper(trim($sku));
    }

    private function findCategory(int $catalogId, string $code): ?object
    {
        return DB::table('categories')->where('catalog_id', $catalogId)->whereRaw('LOWER(slug) = ?', [$this->normalizeCode($code)])->first(['id']);
    }

    private function findProduct(int $catalogId, string $sku): ?object
    {
        return DB::table('products')->where('catalog_id', $catalogId)->whereRaw('LOWER(sku) = ?', [strtolower(trim($sku))])->first(['id']);
    }

    private function resolveUnitId(string $code, int $storeId, string $channel): int
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return 0;
        }
        $targetScopeKey = $channel === 'b2b' ? 'b2b' : 'store:'.$storeId;
        $rows = DB::table('units')
            ->whereRaw('LOWER(code) = ?', [$code])
            ->where('is_active', true)
            ->whereIn('scope_key', [$targetScopeKey, 'global'])
            ->get(['id', 'scope_key']);
        $target = $rows->firstWhere('scope_key', $targetScopeKey) ?? $rows->firstWhere('scope_key', 'global');

        return $target === null ? 0 : (int) $target->id;
    }

    private function resolveBrandId(string $code, int $storeId, string $channel): int
    {
        $code = $this->normalizeCode($code);
        $targetScopeKey = $channel === 'b2b' ? 'b2b' : 'store:'.$storeId;
        $rows = DB::table('brands')
            ->whereRaw('LOWER(slug) = ?', [$code])
            ->where('is_active', true)
            ->whereIn('scope_key', [$targetScopeKey, 'global'])
            ->get(['id', 'scope_key']);
        $target = $rows->firstWhere('scope_key', $targetScopeKey) ?? $rows->firstWhere('scope_key', 'global');

        return $target === null ? 0 : (int) $target->id;
    }

    /** @return array{0:string,1:string,2:?int} */
    private function brandScope(int $storeId, string $channel): array
    {
        if ($channel === 'b2b') {
            return ['b2b', 'b2b', null];
        }

        return ['store', 'store:'.$storeId, $storeId];
    }

    /** @param array<string,mixed> $zip */
    private function replaceEntityImage(string $table, int $id, string $entryPath, array $zip): void
    {
        $bytes = $this->entryBytes($zip, $entryPath);
        $directory = $table === 'categories' ? "catalog/categories/{$id}" : "catalog/brands/{$id}";
        $newPath = $this->storeImportedImage($directory, $bytes, basename($entryPath));
        $oldPath = DB::table($table)->where('id', $id)->value('image_path');
        DB::table($table)->where('id', $id)->update(['image_path' => $newPath, 'updated_at' => now()]);
        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $newPath) {
            $this->deletePublicPath($oldPath);
        }
    }

    /** @param list<string> $files @param array<string,mixed> $zip */
    private function syncProductImages(int $productId, array $files, array $zip): void
    {
        $prepared = [];
        foreach ($files as $filename) {
            $entryPath = "images/products/{$filename}";
            $bytes = $this->entryBytes($zip, $entryPath);
            $publicPath = $this->storeImportedImage("catalog/products/{$productId}", $bytes, $filename);
            $prepared[] = $publicPath;
        }

        $prefix = "storage/catalog/products/{$productId}/import-";
        $oldImported = DB::table('product_images')
            ->where('product_id', $productId)
            ->where('path', 'like', $prefix.'%')
            ->get(['id', 'path', 'is_primary']);
        $manualPrimary = DB::table('product_images')
            ->where('product_id', $productId)
            ->where('path', 'not like', $prefix.'%')
            ->where('is_primary', true)
            ->exists();
        $nextSort = (int) DB::table('product_images')
            ->where('product_id', $productId)
            ->where('path', 'not like', $prefix.'%')
            ->max('sort_order') + 1;

        DB::transaction(function () use ($productId, $prefix, $prepared, $manualPrimary, $nextSort): void {
            DB::table('product_images')->where('product_id', $productId)->where('path', 'like', $prefix.'%')->delete();
            foreach ($prepared as $index => $path) {
                DB::table('product_images')->insert([
                    'product_id' => $productId,
                    'path' => $path,
                    'sort_order' => $nextSort + $index,
                    'is_primary' => ! $manualPrimary && $index === 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        foreach ($oldImported as $old) {
            if (! in_array((string) $old->path, $prepared, true)) {
                $this->deletePublicPath((string) $old->path);
            }
        }
    }

    private function storeImportedImage(string $directory, string $bytes, string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        $path = trim($directory, '/').'/import-'.hash('sha256', $bytes).'.'.$extension;
        $stored = Storage::disk('public')->put($path, $bytes, ['visibility' => 'public']);
        if (! $stored || ! Storage::disk('public')->exists($path)) {
            throw new RuntimeException('Unable to persist imported image to public storage.');
        }

        return 'storage/'.$path;
    }

    private function deletePublicPath(string $path): void
    {
        $path = ltrim(trim($path), '/');
        if (str_starts_with($path, 'storage/')) {
            Storage::disk('public')->delete(substr($path, strlen('storage/')));
        }
    }

    /** @return array{data:string,entries:array<string,array<string,int|string>>} */
    private function readZipFile(string $path): array
    {
        $size = @filesize($path);
        if (! is_int($size) || $size <= 0 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('ZIP is empty, unreadable, or exceeds the 50 MB package limit.');
        }
        $data = file_get_contents($path);
        if (! is_string($data)) {
            throw new RuntimeException('ZIP could not be read.');
        }

        return $this->readZipBytes($data);
    }

    /** @return array{data:string,entries:array<string,array<string,int|string>>} */
    private function readZipBytes(string $data): array
    {
        $length = strlen($data);
        if ($length < 22) {
            throw new RuntimeException('ZIP structure is incomplete.');
        }
        $tailLength = min($length, 65_557);
        $tailStart = $length - $tailLength;
        $tail = substr($data, $tailStart);
        $eocdRelative = strrpos($tail, "\x50\x4b\x05\x06");
        if ($eocdRelative === false) {
            throw new RuntimeException('ZIP end-of-central-directory record is missing.');
        }
        $eocdOffset = $tailStart + $eocdRelative;
        $eocd = unpack('vdisk/vstart_disk/vdisk_entries/ventries/Vcentral_size/Vcentral_offset/vcomment_length', substr($data, $eocdOffset + 4, 18));
        if (! is_array($eocd) || (int) $eocd['disk'] !== 0 || (int) $eocd['start_disk'] !== 0) {
            throw new RuntimeException('Multi-disk ZIP packages are not supported.');
        }
        $entryCount = (int) $eocd['entries'];
        if ($entryCount <= 0 || $entryCount > self::MAX_ENTRIES || (int) $eocd['disk_entries'] !== $entryCount) {
            throw new RuntimeException('ZIP entry count is invalid or exceeds the safety limit.');
        }
        $offset = (int) $eocd['central_offset'];
        $centralEnd = $offset + (int) $eocd['central_size'];
        if ($offset < 0 || $centralEnd > $length || $centralEnd > $eocdOffset) {
            throw new RuntimeException('ZIP central directory is out of bounds.');
        }

        $entries = [];
        $totalUncompressed = 0;
        for ($i = 0; $i < $entryCount; $i++) {
            if (substr($data, $offset, 4) !== "\x50\x4b\x01\x02") {
                throw new RuntimeException('ZIP central directory contains an invalid file header.');
            }
            $meta = unpack(
                'vversion_made/vversion_needed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal_attributes/Vexternal_attributes/Vlocal_offset',
                substr($data, $offset + 4, 42),
            );
            if (! is_array($meta)) {
                throw new RuntimeException('ZIP file metadata could not be parsed.');
            }
            $nameLength = (int) $meta['name_length'];
            $extraLength = (int) $meta['extra_length'];
            $commentLength = (int) $meta['comment_length'];
            $name = substr($data, $offset + 46, $nameLength);
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('ZIP contains an unnamed entry.');
            }
            $this->assertSafeZipPath($name);
            if ((int) $meta['disk_start'] !== 0
                || (int) $meta['compressed_size'] === 0xffffffff
                || (int) $meta['uncompressed_size'] === 0xffffffff
                || (int) $meta['local_offset'] === 0xffffffff) {
                throw new RuntimeException("ZIP64 or multi-disk entry is not supported: {$name}");
            }
            if (((int) $meta['flags'] & 0x0001) !== 0) {
                throw new RuntimeException("Encrypted ZIP entries are not supported: {$name}");
            }
            if (! in_array((int) $meta['method'], [0, 8], true)) {
                throw new RuntimeException("Unsupported ZIP compression method for {$name}.");
            }
            if (isset($entries[$name])) {
                throw new RuntimeException("ZIP contains duplicate entry: {$name}");
            }
            $mode = ((int) $meta['external_attributes'] >> 16) & 0xf000;
            if ($mode === 0xa000) {
                throw new RuntimeException("ZIP symbolic links are not allowed: {$name}");
            }
            $uncompressed = (int) $meta['uncompressed_size'];
            $totalUncompressed += $uncompressed;
            if ($totalUncompressed > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('ZIP uncompressed content exceeds the 70 MB safety limit.');
            }
            if (! str_ends_with($name, '/') && $name !== 'catalog.xlsx' && $uncompressed > self::MAX_ENTRY_BYTES) {
                throw new RuntimeException("ZIP entry exceeds 5 MB: {$name}");
            }

            if (! str_ends_with($name, '/')) {
                $entries[$name] = [
                    'method' => (int) $meta['method'],
                    'flags' => (int) $meta['flags'],
                    'crc' => (int) $meta['crc'],
                    'compressed_size' => (int) $meta['compressed_size'],
                    'uncompressed_size' => $uncompressed,
                    'local_offset' => (int) $meta['local_offset'],
                ];
            }
            $offset += 46 + $nameLength + $extraLength + $commentLength;
            if ($offset > $centralEnd) {
                throw new RuntimeException('ZIP central directory entry extends past its declared boundary.');
            }
        }

        return ['data' => $data, 'entries' => $entries];
    }

    private function assertSafeZipPath(string $name): void
    {
        if (strlen($name) > 255
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || preg_match('//u', $name) !== 1) {
            throw new RuntimeException("Unsafe ZIP path: {$name}");
        }
        $parts = explode('/', rtrim($name, '/'));
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException("Unsafe ZIP path traversal: {$name}");
            }
        }
    }

    /** @param array{data:string,entries:array<string,array<string,int|string>>} $zip */
    private function entryBytes(array $zip, string $path): string
    {
        if (! isset($zip['entries'][$path])) {
            throw new RuntimeException("ZIP entry is missing: {$path}");
        }
        $entry = $zip['entries'][$path];
        $data = $zip['data'];
        $localOffset = (int) $entry['local_offset'];
        if (substr($data, $localOffset, 4) !== "\x50\x4b\x03\x04") {
            throw new RuntimeException("ZIP local header is invalid: {$path}");
        }
        $local = unpack('vversion/vflags/vmethod/vmtime/vmdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length', substr($data, $localOffset + 4, 26));
        if (! is_array($local)) {
            throw new RuntimeException("ZIP local metadata is invalid: {$path}");
        }
        $payloadOffset = $localOffset + 30 + (int) $local['name_length'] + (int) $local['extra_length'];
        $compressed = substr($data, $payloadOffset, (int) $entry['compressed_size']);
        if (! is_string($compressed) || strlen($compressed) !== (int) $entry['compressed_size']) {
            throw new RuntimeException("ZIP payload is truncated: {$path}");
        }
        if ((int) $entry['method'] === 0) {
            $uncompressed = $compressed;
        } else {
            $uncompressed = @gzinflate($compressed);
            if (! is_string($uncompressed)) {
                throw new RuntimeException("ZIP deflate payload is corrupt: {$path}");
            }
        }
        if (strlen($uncompressed) !== (int) $entry['uncompressed_size']) {
            throw new RuntimeException("ZIP size verification failed: {$path}");
        }
        $crc = (int) sprintf('%u', crc32($uncompressed));
        $expected = (int) sprintf('%u', (int) $entry['crc']);
        if ($crc !== $expected) {
            throw new RuntimeException("ZIP CRC verification failed: {$path}");
        }

        return $uncompressed;
    }

    /**
     * @param array{data:string,entries:array<string,array<string,int|string>>} $xlsx
     * @return list<array<string,string>>
     */
    private function sheetRows(array $xlsx, string $sheetName): array
    {
        $workbook = $this->entryBytes($xlsx, 'xl/workbook.xml');
        $relationships = $this->entryBytes($xlsx, 'xl/_rels/workbook.xml.rels');
        $sheetRid = null;
        preg_match_all('/<sheet\b[^>]*\/?\s*>/i', $workbook, $sheetTags);
        foreach ($sheetTags[0] ?? [] as $tag) {
            $attrs = $this->xmlAttributes($tag);
            if (($attrs['name'] ?? '') === $sheetName) {
                $sheetRid = $attrs['r:id'] ?? $attrs['id'] ?? null;
                break;
            }
        }
        if (! is_string($sheetRid) || $sheetRid === '') {
            throw new RuntimeException("required sheet {$sheetName} was not found");
        }

        $target = null;
        preg_match_all('/<Relationship\b[^>]*\/?\s*>/i', $relationships, $relationshipTags);
        foreach ($relationshipTags[0] ?? [] as $tag) {
            $attrs = $this->xmlAttributes($tag);
            if (($attrs['Id'] ?? '') === $sheetRid) {
                $target = $attrs['Target'] ?? null;
                break;
            }
        }
        if (! is_string($target) || $target === '') {
            throw new RuntimeException("worksheet relationship for {$sheetName} was not found");
        }
        $target = ltrim($target, '/');
        $worksheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        $worksheetPath = $this->normalizeInternalPath($worksheetPath);
        $worksheet = $this->entryBytes($xlsx, $worksheetPath);

        $sharedStrings = [];
        if (isset($xlsx['entries']['xl/sharedStrings.xml'])) {
            $shared = $this->entryBytes($xlsx, 'xl/sharedStrings.xml');
            if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/si', $shared, $matches)) {
                foreach ($matches[1] as $fragment) {
                    $sharedStrings[] = $this->xmlTextNodes($fragment);
                }
            }
        }

        $matrix = [];
        if (preg_match_all('/<row\b[^>]*>(.*?)<\/row>/si', $worksheet, $rowMatches)) {
            foreach ($rowMatches[1] as $rowXml) {
                $cells = [];
                if (preg_match_all('/<c\b([^>]*?)>(.*?)<\/c>/si', $rowXml, $cellMatches, PREG_SET_ORDER)) {
                    $fallbackColumn = 0;
                    foreach ($cellMatches as $match) {
                        $attrs = $this->xmlAttributes('<c '.$match[1].'>');
                        $reference = (string) ($attrs['r'] ?? '');
                        if ($reference !== '' && preg_match('/^([A-Z]+)\d+$/i', $reference, $refMatch)) {
                            $column = $this->columnIndex($refMatch[1]);
                        } else {
                            $column = $fallbackColumn;
                        }
                        $fallbackColumn = $column + 1;
                        $type = (string) ($attrs['t'] ?? '');
                        $body = $match[2];
                        if ($type === 'inlineStr') {
                            $value = $this->xmlTextNodes($body);
                        } elseif ($type === 's') {
                            $index = 0;
                            if (preg_match('/<v\b[^>]*>(.*?)<\/v>/si', $body, $valueMatch)) {
                                $index = (int) trim($this->decodeXml($valueMatch[1]));
                            }
                            $value = $sharedStrings[$index] ?? '';
                        } else {
                            $value = '';
                            if (preg_match('/<v\b[^>]*>(.*?)<\/v>/si', $body, $valueMatch)) {
                                $value = trim($this->decodeXml($valueMatch[1]));
                            }
                        }
                        $cells[$column] = $value;
                    }
                }
                if ($cells !== []) {
                    ksort($cells);
                    $matrix[] = $cells;
                }
                if (count($matrix) > self::MAX_ROWS_PER_SHEET + 1) {
                    throw new RuntimeException("sheet {$sheetName} exceeds ".self::MAX_ROWS_PER_SHEET.' data rows');
                }
            }
        }
        if ($matrix === []) {
            return [];
        }

        $headerCells = array_shift($matrix);
        $headers = [];
        foreach ($headerCells as $column => $value) {
            $header = strtolower(trim((string) $value));
            if ($header !== '') {
                $headers[(int) $column] = $header;
            }
        }
        if ($headers === []) {
            return [];
        }

        $rows = [];
        foreach ($matrix as $cells) {
            $row = [];
            $hasValue = false;
            foreach ($headers as $column => $header) {
                $value = trim((string) ($cells[$column] ?? ''));
                $row[$header] = $value;
                $hasValue = $hasValue || $value !== '';
            }
            if ($hasValue) {
                $rows[] = $row;
            }
        }

        return [array_fill_keys(array_values($headers), ''), ...$rows];
    }

    /** @return array<string,string> */
    private function xmlAttributes(string $tag): array
    {
        $attrs = [];
        if (preg_match_all('/([A-Za-z_:][A-Za-z0-9_.:-]*)\s*=\s*"([^"]*)"/', $tag, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs[$match[1]] = $this->decodeXml($match[2]);
            }
        }

        return $attrs;
    }

    private function xmlTextNodes(string $fragment): string
    {
        $parts = [];
        if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/si', $fragment, $matches)) {
            foreach ($matches[1] as $value) {
                $parts[] = $this->decodeXml($value);
            }
        }

        return implode('', $parts);
    }

    private function decodeXml(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function normalizeInternalPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new RuntimeException('XLSX relationship escapes the workbook root.');
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function columnIndex(string $letters): int
    {
        $value = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $value = ($value * 26) + (ord($letter) - 64);
        }

        return max(0, $value - 1);
    }

    /** @param array<string,list<list<string>>> $sheets */
    private function buildWorkbook(array $sheets): string
    {
        $sheetEntries = [];
        $workbookSheets = [];
        $relationships = [];
        $contentTypes = [];
        $index = 1;
        foreach ($sheets as $name => $rows) {
            $sheetEntries["xl/worksheets/sheet{$index}.xml"] = $this->buildSheetXml($rows);
            $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $workbookSheets[] = '<sheet name="'.$safeName.'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $relationships[] = '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
            $contentTypes[] = '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $index++;
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.implode('', $contentTypes).'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.implode('', $workbookSheets).'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', $relationships).'</Relationships>',
            ...$sheetEntries,
        ];

        return $this->buildZip($files);
    }

    /** @param list<list<string>> $rows */
    private function buildSheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $xml .= '<row r="'.$excelRow.'">';
            foreach ($row as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex).$excelRow;
                $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $xml .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name = chr(65 + $remainder).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    /** @param array<string,string> $files */
    private function buildZip(array $files): string
    {
        $locals = '';
        $centrals = '';
        $offset = 0;
        $count = 0;
        foreach ($files as $name => $content) {
            $this->assertSafeZipPath($name);
            $compressed = gzdeflate($content, 9);
            if (! is_string($compressed)) {
                throw new RuntimeException("Unable to compress ZIP entry {$name}.");
            }
            $crc = (int) sprintf('%u', crc32($content));
            $compressedSize = strlen($compressed);
            $uncompressedSize = strlen($content);
            $nameLength = strlen($name);
            $flags = 0x0800;
            $method = 8;
            [$dosTime, $dosDate] = $this->dosTimestamp();

            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $dosTime, $dosDate, $crc, $compressedSize, $uncompressedSize, $nameLength, 0)
                .$name.$compressed;
            $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0314, 20, $flags, $method, $dosTime, $dosDate, $crc, $compressedSize, $uncompressedSize, $nameLength, 0, 0, 0, 0, 0, $offset)
                .$name;
            $locals .= $local;
            $centrals .= $central;
            $offset += strlen($local);
            $count++;
        }
        $centralOffset = strlen($locals);
        $centralSize = strlen($centrals);
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0);

        return $locals.$centrals.$eocd;
    }

    /** @return array{0:int,1:int} */
    private function dosTimestamp(): array
    {
        $parts = getdate();
        $year = max(1980, (int) $parts['year']);
        $time = ((int) $parts['hours'] << 11) | ((int) $parts['minutes'] << 5) | intdiv((int) $parts['seconds'], 2);
        $date = (($year - 1980) << 9) | ((int) $parts['mon'] << 5) | (int) $parts['mday'];

        return [$time, $date];
    }

    private function assertToken(string $token): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token) !== 1) {
            throw ValidationException::withMessages(['preview_token' => ['Invalid catalog import preview token.']]);
        }
    }
}
