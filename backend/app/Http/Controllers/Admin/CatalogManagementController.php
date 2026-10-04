<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CatalogImageService;
use App\Services\CatalogOwnership;
use App\Services\CatalogZipImportService;
use App\Services\LookupScopeService;
use App\Services\RetailWholesaleAccountService;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CatalogManagementController extends Controller
{
    public function __construct(
        private readonly CatalogOwnership $catalogs,
        private readonly TenantContextResolver $tenantContext,
        private readonly LookupScopeService $lookups,
        private readonly CatalogImageService $images,
        private readonly CatalogZipImportService $catalogImports,
        private readonly RetailWholesaleAccountService $wholesaleAccounts,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $actor = $this->actor($request);

        if ((string) $request->query('tab') === 'stores') {
            if ($actor->hasRole('SUPER_ADMIN')) {
                return redirect()->route('admin.retail-stores.index');
            }
            abort(403);
        }

        $tab = in_array((string) $request->query('tab'), ['products', 'categories', 'import'], true)
            ? (string) $request->query('tab')
            : 'products';

        // Store provisioning belongs to the dedicated control-plane screen.
        // This catalog surface is operational only and never doubles as store management.
        $canManageStores = false;
        $storeIds = $this->visibleStoreIds($actor, $request);
        $storeIds = $this->catalogReadableStoreIds($actor, $storeIds);
        if ($storeIds === [] && $this->canAccessWholesale($actor) === false) {
            abort(403);
        }

        $scopeParams = [];
        if ($request->integer('store_id') > 0) {
            $scopeParams['store_id'] = $request->integer('store_id');
        }
        if ($request->boolean('support_access')) {
            $scopeParams['support_access'] = 1;
        }

        $contextStoreId = count($storeIds) === 1 ? (int) $storeIds[0] : null;
        $contextChannel = $contextStoreId === null ? null : $this->storeChannel($contextStoreId);
        if ($actor->hasRole('SUPER_ADMIN') && $contextChannel === 'b2c') {
            $scopeParams['support_access'] = 1;
        }
        $navContext = $contextChannel === 'b2c' ? 'b2c_products' : 'b2b_products';
        $inventoryUrl = $contextStoreId !== null && $contextChannel === 'b2c'
            ? route('admin.b2c.module', array_merge(
                ['module' => 'inventory', 'store_id' => $contextStoreId],
                $actor->hasRole('SUPER_ADMIN') ? ['support_access' => 1] : [],
            ))
            : null;
        $categoryImageColumn = Schema::hasColumn('categories', 'image_path')
            ? 'categories.image_path'
            : DB::raw('NULL as image_path');

        return view('admin.catalog-management', [
            'user' => $actor,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => $navContext,
            'inventoryUrl' => $inventoryUrl,
            'tab' => $tab,
            'products' => DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->join('stores as catalog_store', 'catalog_store.id', '=', 'catalogs.store_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                ->join('units', 'units.id', '=', 'products.unit_id')
                ->whereIn('catalogs.store_id', $storeIds)
                ->where('catalogs.is_migration_quarantine', false)
                ->orderByDesc('products.id')
                ->get([
                    'products.id',
                    'products.sku',
                    'products.name',
                    'products.description',
                    'products.is_active',
                    'products.category_id',
                    'products.brand_id',
                    'products.unit_id',
                    'products.catalog_id',
                    'catalogs.store_id as catalog_store_id',
                    'catalogs.channel as catalog_channel',
                    'catalog_store.name as catalog_store_name',
                    'categories.name as category',
                    'brands.name as brand',
                    'units.name as unit',
                    DB::raw('(select path from product_images where product_images.product_id = products.id order by is_primary desc, sort_order asc, id asc limit 1) as primary_image_path'),
                ]),
            'categories' => DB::table('categories')
                ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
                ->join('stores as catalog_store', 'catalog_store.id', '=', 'catalogs.store_id')
                ->leftJoin('categories as parent', 'parent.id', '=', 'categories.parent_id')
                ->whereIn('catalogs.store_id', $storeIds)
                ->where('catalogs.is_migration_quarantine', false)
                ->orderBy('categories.name')
                ->get([
                    'categories.id',
                    'categories.name',
                    'categories.slug',
                    $categoryImageColumn,
                    'categories.parent_id',
                    'categories.is_active',
                    'categories.catalog_id',
                    'catalogs.store_id as catalog_store_id',
                    'catalog_store.name as catalog_store_name',
                    'parent.name as parent_name',
                ]),
            'brands' => $this->lookups
                ->visible(Brand::query(), $actor, 'brands')
                ->where('brands.is_active', true)
                ->orderBy('brands.name')
                ->get(),
            'units' => $this->lookups
                ->visible(Unit::query(), $actor, 'units')
                ->where('units.is_active', true)
                ->orderBy('units.name')
                ->get(),
            'stores' => DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->whereIn('stores.id', $storeIds)
                ->where('stores.code', '!=', 'SYSTEM-LEGACY-QUARANTINE')
                ->orderBy('stores.name')
                ->get([
                    'stores.id',
                    'stores.code',
                    'stores.name',
                    'stores.is_active',
                    'stores.store_type_id',
                    'store_types.code as type_code',
                ]),
            'storeTypes' => collect(),
            'productImages' => DB::table('product_images')
                ->join('products', 'products.id', '=', 'product_images.product_id')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->whereIn('catalogs.store_id', $storeIds)
                ->where('catalogs.is_migration_quarantine', false)
                ->orderBy('product_images.product_id')
                ->orderByDesc('product_images.is_primary')
                ->orderBy('product_images.sort_order')
                ->orderBy('product_images.id')
                ->get([
                    'product_images.id',
                    'product_images.product_id',
                    'product_images.path',
                    'product_images.sort_order',
                    'product_images.is_primary',
                ])
                ->groupBy('product_id'),
            'assignments' => DB::table('store_products')
                ->whereIn('store_id', $storeIds)
                ->get()
                ->keyBy(fn ($row) => $row->store_id.':'.$row->product_id),
            'canManageStores' => $canManageStores,
            'scopeParams' => $scopeParams,
        ]);
    }

    public function categories(Request $request): RedirectResponse
    {
        $params = ['tab' => 'categories'];
        if ($request->integer('store_id') > 0) {
            $params['store_id'] = $request->integer('store_id');
        }
        if ($request->boolean('support_access')) {
            $params['support_access'] = 1;
        }

        return redirect()->route('admin.catalog.index', $params);
    }

    public function downloadImportSample(Request $request): Response
    {
        $actor = $this->actor($request);
        $storeIds = $this->catalogReadableStoreIds($actor, $this->visibleStoreIds($actor, $request));
        if ($storeIds === [] && $this->canAccessWholesale($actor) === false) {
            abort(403);
        }

        $storeId = $request->integer('store_id') > 0 ? $request->integer('store_id') : (int) ($storeIds[0] ?? 0);
        $channel = $storeId > 0 ? $this->storeChannel($storeId) : null;
        $targetScopeKey = $channel === 'b2b' ? LookupScopeService::B2B : LookupScopeService::STORE.':'.$storeId;
        $unitCode = DB::table('units')
            ->where('is_active', true)
            ->whereIn('scope_key', [$targetScopeKey, LookupScopeService::GLOBAL])
            ->orderByRaw('CASE WHEN scope_key = ? THEN 0 ELSE 1 END', [$targetScopeKey])
            ->value('code');
        $unitCode = is_string($unitCode) && trim($unitCode) !== '' ? $unitCode : 'PCS';

        return response($this->catalogImports->sampleZip($unitCode), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="foodex-catalog-import-sample.zip"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function previewImport(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'catalog_zip' => ['required', 'file', 'max:51200'],
        ]);
        $archive = $request->file('catalog_zip');
        if ($archive instanceof UploadedFile === false) {
            throw ValidationException::withMessages(['catalog_zip' => [$this->msg(
                'تعذر قراءة ملف ZIP المرفوع.',
                'The uploaded ZIP could not be read.',
            )]]);
        }

        $storeId = (int) $data['store_id'];
        $catalog = $this->catalogs->defaultCatalogForStore($storeId);
        $channel = strtolower((string) $catalog->channel);
        $this->authorizeCatalogAction($request, 'catalog.manage', $storeId, $channel);
        $actor = $this->actor($request);
        $this->authorizeImportLookups($request, $actor, $storeId, $channel);

        $preview = $this->catalogImports->preview($archive, $storeId, (int) $catalog->id, $channel);

        return redirect()
            ->route('admin.catalog.index', $this->importRouteParams($request, $storeId))
            ->with('catalog_import_preview', $preview);
    }

    public function commitImport(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preview_token' => ['required', 'string', 'max:64'],
        ]);
        $token = (string) $data['preview_token'];
        $manifest = $this->catalogImports->manifest($token);
        $storeId = (int) ($manifest['store_id'] ?? 0);
        $catalogId = (int) ($manifest['catalog_id'] ?? 0);
        $channel = strtolower((string) ($manifest['channel'] ?? ''));

        $catalog = $this->catalogs->defaultCatalogForStore($storeId, $channel);
        if ((int) $catalog->id !== $catalogId) {
            throw ValidationException::withMessages(['preview_token' => [$this->msg(
                'تغير نطاق الكتالوج بعد المعاينة. ارفع الملف مرة أخرى.',
                'The catalog scope changed after preview. Upload the ZIP again.',
            )]]);
        }

        $this->authorizeCatalogAction($request, 'catalog.manage', $storeId, $channel);
        $actor = $this->actor($request);
        $this->authorizeImportLookups($request, $actor, $storeId, $channel);

        $result = $this->catalogImports->commit($token);
        app(AuditLogger::class)->record('catalog.bulk_import.completed', $actor, null, null, [
            'store_id' => $storeId,
            'catalog_id' => $catalogId,
            'counts' => $result['counts'],
            'media_error_count' => count($result['media_errors']),
        ], $request);

        return redirect()
            ->route('admin.catalog.index', $this->importRouteParams($request, $storeId))
            ->with('catalog_import_result', $result)
            ->with('status', $result['media_errors'] === []
                ? $this->msg('تم استيراد الكتالوج بنجاح.', 'Catalog import completed successfully.')
                : $this->msg('تم استيراد البيانات مع وجود أخطاء في حفظ بعض الصور.', 'Catalog data imported with some media persistence errors.'));
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'is_active' => ['nullable', 'boolean'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'images' => ['nullable', 'array', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $catalog = $this->catalogs->defaultCatalogForStore((int) $data['store_id']);
        $this->authorizeCatalogAction($request, 'catalog.create', (int) $data['store_id'], (string) $catalog->channel);
        $this->catalogs->assertSameCatalog(isset($data['category_id']) ? (int) $data['category_id'] : null, (int) $catalog->id);
        $this->lookups->assertAssignableToStore('units', (int) $data['unit_id'], (int) $data['store_id']);
        if (! empty($data['brand_id'])) {
            $this->lookups->assertAssignableToStore('brands', (int) $data['brand_id'], (int) $data['store_id']);
        }
        $this->assertSkuAvailable((int) $catalog->id, (string) $data['sku']);

        $id = DB::transaction(function () use ($data, $request, $catalog): int {
            $id = (int) DB::table('products')->insertGetId([
                'catalog_id' => $catalog->id,
                'sku' => $data['sku'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'unit_id' => $data['unit_id'],
                'is_active' => $request->boolean('is_active', true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('store_products')->insert([
                'store_id' => $data['store_id'],
                'product_id' => $id,
                'price' => $data['price'] ?? null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });

        /** @var list<UploadedFile> $files */
        $files = collect($request->file('images', []))
            ->filter(static fn ($file): bool => $file instanceof UploadedFile)
            ->values()
            ->all();
        if ($files !== []) {
            $this->images->addProductImages($id, $files);
        }

        app(AuditLogger::class)->record('catalog.product.created', $this->actor($request), null, null, [
            'product_id' => $id,
            'store_id' => (int) $data['store_id'],
            'image_count' => count($files),
        ], $request);

        return back()->with('status', $this->msg('تمت إضافة المنتج وصوره.', 'Product and images added.'));
    }

    public function updateProduct(Request $request, int $product): RedirectResponse
    {
        $owner = $this->productOwner($product);
        $this->authorizeCatalogAction($request, 'catalog.edit', (int) $owner->store_id, (string) $owner->channel);

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'is_active' => ['nullable', 'boolean'],
            'images' => ['nullable', 'array', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $brandId = isset($data['brand_id']) ? (int) $data['brand_id'] : null;
        $unitId = (int) $data['unit_id'];

        $this->validateProductCategoryReference($categoryId, $owner);
        $this->validateProductLookupReference('units', $unitId, $owner);
        $this->validateProductLookupReference('brands', $brandId, $owner);
        $this->assertSkuAvailable((int) $owner->catalog_id, (string) $data['sku'], $product);

        DB::table('products')->where('id', $product)->update([
            ...collect($data)->except(['is_active', 'images'])->all(),
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        /** @var list<UploadedFile> $files */
        $files = collect($request->file('images', []))
            ->filter(static fn ($file): bool => $file instanceof UploadedFile)
            ->values()
            ->all();
        if ($files !== []) {
            $this->images->addProductImages($product, $files);
        }

        return back()->with('status', $this->msg('تم تعديل المنتج وصوره.', 'Product and images updated.'));
    }

    public function updateProductImage(Request $request, int $product, int $image): RedirectResponse
    {
        $owner = $this->productOwner($product);
        $this->authorizeCatalogAction($request, 'catalog.edit', (int) $owner->store_id, (string) $owner->channel);

        $data = $request->validate([
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $this->images->updateProductImage(
            $product,
            $image,
            (int) $data['sort_order'],
            $request->boolean('is_primary'),
        );

        return back()->with('status', $this->msg('تم تحديث ترتيب صورة المنتج.', 'Product image updated.'));
    }

    public function destroyProductImage(Request $request, int $product, int $image): RedirectResponse
    {
        $owner = $this->productOwner($product);
        $this->authorizeCatalogAction($request, 'catalog.edit', (int) $owner->store_id, (string) $owner->channel);
        $this->images->deleteProductImage($product, $image);

        return back()->with('status', $this->msg('تم حذف صورة المنتج.', 'Product image removed.'));
    }

    public function destroyProduct(Request $request, int $product): RedirectResponse
    {
        $owner = $this->productOwner($product);
        $this->authorizeCatalogAction($request, 'catalog.delete', (int) $owner->store_id, (string) $owner->channel);
        $used = DB::table('order_items')->where('product_id', $product)->exists();

        if ($used) {
            DB::table('products')->where('id', $product)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

            return back()->with('status', $this->msg(
                'المنتج مستخدم في طلبات سابقة، لذلك تم تعطيله بدلاً من حذفه.',
                'Product is referenced by orders, so it was deactivated instead of deleted.',
            ));
        }

        $this->images->purgeProductImages($product);
        DB::table('product_images')->where('product_id', $product)->delete();
        DB::table('products')->where('id', $product)->delete();

        return back()->with('status', $this->msg('تم حذف المنتج وصوره.', 'Product and images deleted.'));
    }

    public function assignProduct(Request $request, int $product): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $owner = $this->productOwner($product);
        $this->authorizeCatalogAction($request, 'catalog.manage', (int) $owner->store_id, (string) $owner->channel);
        if ((int) $data['store_id'] !== (int) $owner->store_id) {
            throw ValidationException::withMessages([
                'store_id' => ['A product can only be assigned inside its owning catalog store.'],
            ]);
        }

        DB::table('store_products')->updateOrInsert(
            ['store_id' => $data['store_id'], 'product_id' => $product],
            [
                'price' => $data['price'] ?? null,
                'is_active' => $request->boolean('is_active', true),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return back()->with('status', $this->msg('تم تحديث ربط المنتج بالمتجر.', 'Store product assignment updated.'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
            'category_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $catalog = $this->catalogs->defaultCatalogForStore((int) $data['store_id']);
        $this->authorizeCatalogAction($request, 'catalog.manage', (int) $data['store_id'], (string) $catalog->channel);
        $this->catalogs->assertParentInCatalog(isset($data['parent_id']) ? (int) $data['parent_id'] : null, (int) $catalog->id);
        $slug = ($data['slug'] ?? null) ?: Str::slug($data['name']).'-'.Str::lower(Str::random(5));
        $this->assertSlugAvailable((int) $catalog->id, $slug);

        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalog->id,
            'name' => $data['name'],
            'slug' => $slug,
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryImage = $request->file('category_image');
        if ($categoryImage instanceof UploadedFile) {
            $this->images->replaceCategoryImage($categoryId, $categoryImage);
        }

        return back()->with('status', $this->msg('تمت إضافة التصنيف وصورته.', 'Category and image added.'));
    }

    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        $owner = $this->categoryOwner($category);
        $this->authorizeCatalogAction($request, 'catalog.manage', (int) $owner->store_id, (string) $owner->channel);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id', Rule::notIn([$category])],
            'is_active' => ['nullable', 'boolean'],
            'category_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        $this->catalogs->assertParentInCatalog(isset($data['parent_id']) ? (int) $data['parent_id'] : null, (int) $owner->catalog_id);
        $this->assertSlugAvailable((int) $owner->catalog_id, (string) $data['slug'], $category);

        DB::table('categories')->where('id', $category)->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        if ($request->boolean('remove_image')) {
            $this->images->removeCategoryImage($category);
        }
        $categoryImage = $request->file('category_image');
        if ($categoryImage instanceof UploadedFile) {
            $this->images->replaceCategoryImage($category, $categoryImage);
        }

        return back()->with('status', $this->msg('تم تعديل التصنيف وصورته.', 'Category and image updated.'));
    }

    public function destroyCategory(Request $request, int $category): RedirectResponse
    {
        $owner = $this->categoryOwner($category);
        $this->authorizeCatalogAction($request, 'catalog.manage', (int) $owner->store_id, (string) $owner->channel);

        if (DB::table('products')->where('category_id', $category)->exists()
            || DB::table('categories')->where('parent_id', $category)->exists()) {
            return back()->withErrors([
                'catalog' => $this->msg(
                    'لا يمكن حذف تصنيف مستخدم بواسطة منتجات أو تصنيفات فرعية.',
                    'A category used by products or child categories cannot be deleted.',
                ),
            ]);
        }

        $this->images->removeCategoryImage($category);
        DB::table('categories')->where('id', $category)->delete();

        return back()->with('status', $this->msg('تم حذف التصنيف وصورته.', 'Category and image deleted.'));
    }

    public function storeBrand(Request $request): RedirectResponse
    {
        Gate::authorize('lookups.manage');
        $actor = $this->actor($request);
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('brands', 'slug')->where(fn ($query) => $query->where('scope_key', 'global')),
            ],
        ]);
        $slug = trim((string) ($data['slug'] ?? ''));
        $slug = $slug !== '' ? Str::lower($slug) : Str::slug($data['name']).'-'.Str::lower(Str::random(5));

        $model = Brand::query()->create([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'name' => $data['name'],
            'name_ar' => $data['name'],
            'name_en' => $data['name'],
            'slug' => $slug,
            'is_active' => true,
        ]);
        app(AuditLogger::class)->record('lookup.brand.created', $actor, $model, null, $model->toArray(), $request);

        return redirect()->route('admin.lookups.index', ['type' => 'brands'])
            ->with('status', $this->msg('تمت إضافة العلامة في مركز البيانات المرجعية.', 'Brand added in the Lookup Management Center.'));
    }

    public function destroyBrand(Request $request, int $brand): RedirectResponse
    {
        Gate::authorize('lookups.manage');
        $actor = $this->actor($request);
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);

        $model = Brand::query()->whereKey($brand)->where('scope', 'global')->firstOrFail();
        if (DB::table('products')->where('brand_id', $brand)->exists()) {
            return redirect()->route('admin.lookups.index', ['type' => 'brands'])->withErrors([
                'lookup' => $this->msg(
                    'لا يمكن حذف علامة مستخدمة بواسطة منتجات. عطّلها بدلاً من الحذف.',
                    'A brand referenced by products cannot be deleted. Deactivate it instead.',
                ),
            ]);
        }

        $before = $model->toArray();
        app(AuditLogger::class)->record('lookup.brand.deleted', $actor, $model, $before, null, $request);
        $model->delete();

        return redirect()->route('admin.lookups.index', ['type' => 'brands'])
            ->with('status', $this->msg('تم حذف العلامة.', 'Brand deleted.'));
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        Gate::authorize('lookups.manage');
        $actor = $this->actor($request);
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('units', 'code')->where(fn ($query) => $query->where('scope_key', 'global')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'decimal_places' => ['required', 'integer', 'between:0,6'],
        ]);

        $model = Unit::query()->create([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => Str::upper(trim($data['code'])),
            'name' => $data['name'],
            'name_ar' => $data['name'],
            'name_en' => $data['name'],
            'decimal_places' => $data['decimal_places'],
            'is_active' => true,
        ]);
        app(AuditLogger::class)->record('lookup.unit.created', $actor, $model, null, $model->toArray(), $request);

        return redirect()->route('admin.lookups.index', ['type' => 'units'])
            ->with('status', $this->msg('تمت إضافة الوحدة في مركز البيانات المرجعية.', 'Unit added in the Lookup Management Center.'));
    }

    public function storeStore(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);
        Gate::authorize('stores.manage');

        $data = $request->validate([
            'store_type_id' => ['required', 'integer', 'exists:store_types,id'],
            'code' => ['required', 'string', 'max:50', 'unique:stores,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $store = Store::query()->create([
            'store_type_id' => $data['store_type_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        $channel = DB::table('store_types')->where('id', $store->store_type_id)->value('code');
        if ($channel === 'B2C') {
            $this->wholesaleAccounts->ensureForStore($store);
        }

        return back()->with('status', $this->msg('تمت إضافة المتجر.', 'Store added.'));
    }

    public function updateStore(Request $request, int $store): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);
        Gate::authorize('stores.manage');

        $data = $request->validate([
            'store_type_id' => ['required', 'integer', 'exists:store_types,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('stores', 'code')->ignore($store)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $model = Store::query()->findOrFail($store);
        abort_unless(
            (int) $model->store_type_id === (int) $data['store_type_id'],
            422,
            'Store channel cannot be changed after provisioning.',
        );

        $model->update([
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active'),
        ]);

        $channel = DB::table('store_types')->where('id', $model->store_type_id)->value('code');
        if ($channel === 'B2C') {
            $this->wholesaleAccounts->syncForStore($model);
        }

        return back()->with('status', $this->msg('تم تعديل المتجر.', 'Store updated.'));
    }

    private function productOwner(int $product): object
    {
        $owner = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.id', $product)
            ->first([
                'products.catalog_id',
                'products.category_id',
                'products.brand_id',
                'products.unit_id',
                'catalogs.store_id',
                'catalogs.channel',
            ]);
        abort_if($owner === null, 404);

        return $owner;
    }

    private function validateProductCategoryReference(?int $categoryId, object $owner): void
    {
        $currentCategoryId = $owner->category_id === null ? null : (int) $owner->category_id;
        if ($categoryId === $currentCategoryId || $categoryId === null) {
            return;
        }

        $valid = DB::table('categories')
            ->where('id', $categoryId)
            ->where('catalog_id', (int) $owner->catalog_id)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                'category_id' => [$this->msg(
                    'التصنيف المحدد لا يتبع نفس كتالوج المنتج.',
                    'The selected category does not belong to this product catalog.',
                )],
            ]);
        }
    }

    private function validateProductLookupReference(string $table, ?int $lookupId, object $owner): void
    {
        if ($lookupId === null) {
            return;
        }

        $currentId = $table === 'units'
            ? (int) $owner->unit_id
            : ($owner->brand_id === null ? null : (int) $owner->brand_id);

        // Preserve an existing legacy reference during unrelated edits. A new
        // selection must satisfy the current tenant/channel ownership rules.
        if ($lookupId === $currentId) {
            return;
        }

        $row = DB::table($table)
            ->where('id', $lookupId)
            ->where('is_active', true)
            ->first(['scope', 'store_id']);

        $valid = false;
        if ($row !== null) {
            $scope = (string) $row->scope;
            $channel = strtolower((string) $owner->channel);
            $valid = $scope === LookupScopeService::GLOBAL
                || ($scope === LookupScopeService::B2B && $channel === 'b2b')
                || ($scope === LookupScopeService::STORE
                    && $channel === 'b2c'
                    && (int) $row->store_id === (int) $owner->store_id);
        }

        if (! $valid) {
            $field = $table === 'units' ? 'unit_id' : 'brand_id';
            throw ValidationException::withMessages([
                $field => [$this->msg(
                    'القيمة المحددة لا تتبع نطاق المتجر/القناة الخاصة بهذا المنتج.',
                    'The selected value is outside this product store/channel scope.',
                )],
            ]);
        }
    }

    private function categoryOwner(int $category): object
    {
        $owner = DB::table('categories')
            ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
            ->where('categories.id', $category)
            ->first(['categories.catalog_id', 'catalogs.store_id', 'catalogs.channel']);
        abort_if($owner === null, 404);

        return $owner;
    }

    private function assertStoreAccess(Request $request, int $storeId, string $channel): void
    {
        $actor = $this->actor($request);

        if (strtolower($channel) === 'b2c') {
            $this->tenantContext->retail($actor, $storeId, $actor->hasRole('SUPER_ADMIN'), $request);

            return;
        }

        $this->tenantContext->wholesale($actor, $storeId);
    }

    /** @return list<int> */
    private function visibleStoreIds(User $actor, Request $request): array
    {
        $requested = $request->integer('store_id');

        if ($actor->hasRole('SUPER_ADMIN') && $requested > 0) {
            $channel = $this->storeChannel($requested);
            if ($channel === 'b2c') {
                $this->tenantContext->retail($actor, $requested, true, $request);

                return [$requested];
            }
            if ($channel === 'b2b') {
                $this->tenantContext->wholesale($actor, $requested);

                return [$requested];
            }

            abort(404);
        }

        if ($this->canAccessWholesale($actor)) {
            if ($requested > 0) {
                $this->tenantContext->wholesale($actor, $requested);

                return [$requested];
            }

            return DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('store_types.code', 'B2B')
                ->where('stores.is_active', true)
                ->pluck('stores.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        if ($requested > 0) {
            $this->tenantContext->retail($actor, $requested, false, $request);

            return [$requested];
        }

        return $this->tenantContext->retailStoreIds($actor);
    }

    /** @param list<int> $storeIds
     * @return list<int>
     */
    private function catalogReadableStoreIds(User $actor, array $storeIds): array
    {
        if ($this->canAccessWholesale($actor)) {
            Gate::authorize('catalog.view');

            return $storeIds;
        }

        return array_values(array_filter(
            $storeIds,
            static fn (int $storeId): bool => $actor->hasPermission('catalog.view', $storeId),
        ));
    }

    private function authorizeImportLookups(
        Request $request,
        User $actor,
        int $storeId,
        string $channel,
    ): void {
        $scope = strtolower($channel) === 'b2b' ? LookupScopeService::B2B : LookupScopeService::STORE;
        $scopeStoreId = $scope === LookupScopeService::STORE ? $storeId : null;

        $this->lookups->authorizeMutation(
            $actor,
            $scope,
            $scopeStoreId,
            $request->boolean('support_access'),
            $request,
        );
    }

    /** @return array<string, int|string> */
    private function importRouteParams(Request $request, int $storeId): array
    {
        $params = ['tab' => 'import', 'store_id' => $storeId];
        if ($request->boolean('support_access')) {
            $params['support_access'] = 1;
        }

        return $params;
    }

    private function authorizeCatalogAction(
        Request $request,
        string $permission,
        int $storeId,
        string $channel,
    ): void {
        $actor = $this->actor($request);
        $this->assertStoreAccess($request, $storeId, $channel);

        if (strtolower($channel) === 'b2c' && $actor->hasRole('SUPER_ADMIN') === false) {
            abort_unless($actor->hasPermission($permission, $storeId), 403);

            return;
        }

        Gate::authorize($permission);
    }

    private function assertSkuAvailable(int $catalogId, string $sku, ?int $ignoreProduct = null): void
    {
        $query = DB::table('products')->where('catalog_id', $catalogId)->where('sku', $sku);
        if ($ignoreProduct !== null) {
            $query->where('id', '!=', $ignoreProduct);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['sku' => ['The SKU has already been used in this catalog.']]);
        }
    }

    private function assertSlugAvailable(int $catalogId, string $slug, ?int $ignoreCategory = null): void
    {
        $query = DB::table('categories')->where('catalog_id', $catalogId)->where('slug', $slug);
        if ($ignoreCategory !== null) {
            $query->where('id', '!=', $ignoreCategory);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['slug' => ['The slug has already been used in this catalog.']]);
        }
    }

    private function storeChannel(int $storeId): ?string
    {
        $code = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->value('store_types.code');

        return is_string($code) ? strtolower($code) : null;
    }

    private function canAccessWholesale(User $actor): bool
    {
        $roles = array_values((array) config('admin.channels.b2b.global_roles', []));

        return $actor->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', $roles)
            ->exists();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
