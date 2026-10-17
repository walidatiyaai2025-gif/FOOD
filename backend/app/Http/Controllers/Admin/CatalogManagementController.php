<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CatalogImageService;
use App\Services\CatalogImportRunService;
use App\Services\CatalogOwnership;
use App\Services\CatalogProductCompletionManager;
use App\Services\LookupScopeService;
use App\Services\ProductAvailabilityService;
use App\Services\ProductSkuGenerator;
use App\Services\RetailWholesaleAccountService;
use App\Services\WholesalePrincipal;
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
        private readonly ProductAvailabilityService $availability,
        private readonly ProductSkuGenerator $skuGenerator,
        private readonly CatalogImageService $images,
        private readonly CatalogImportRunService $catalogImports,
        private readonly CatalogProductCompletionManager $catalogCompletion,
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

        $tab = in_array((string) $request->query('tab'), ['overview', 'products', 'categories', 'attributes', 'import'], true)
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

        $productSearch = mb_substr(trim((string) $request->query('product_q', '')), 0, 120);
        $productCategoryId = max(0, $request->integer('product_category_id'));
        $productBrandId = max(0, $request->integer('product_brand_id'));
        $productStatus = in_array((string) $request->query('product_status'), ['active', 'inactive'], true)
            ? (string) $request->query('product_status')
            : '';
        $productAvailability = in_array((string) $request->query('product_availability'), ['available', 'out_of_stock'], true)
            ? (string) $request->query('product_availability')
            : '';
        $productHealth = in_array((string) $request->query('product_health'), ['low_stock', 'unpriced', 'unavailable', 'missing_category', 'missing_brand', 'margin_exception', 'data_quality'], true)
            ? (string) $request->query('product_health')
            : '';
        $categorySearch = mb_substr(trim((string) $request->query('category_q', '')), 0, 120);
        $categoryStatus = in_array((string) $request->query('category_status'), ['active', 'inactive'], true)
            ? (string) $request->query('category_status')
            : '';
        $categoryParent = trim((string) $request->query('category_parent', ''));
        if ($categoryParent !== 'root' && (! ctype_digit($categoryParent) || (int) $categoryParent < 1)) {
            $categoryParent = '';
        }

        $productColumns = [
            'products.id',
            'products.sku',
            'products.name',
            'products.description',
            'products.is_active',
            'products.lifecycle_state',
            'products.lifecycle_effective_at',
            'products.end_of_life_at',
            'products.low_stock_threshold',
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
        ];
        $productQuery = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('stores as catalog_store', 'catalog_store.id', '=', 'catalogs.store_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.is_migration_quarantine', false);

        if ($productSearch !== '') {
            $needle = '%'.$productSearch.'%';
            $productQuery->where(static function ($query) use ($needle): void {
                $query->where('products.name', 'like', $needle)
                    ->orWhere('products.sku', 'like', $needle);
            });
        }
        if ($productCategoryId > 0) {
            $productQuery->where('products.category_id', $productCategoryId);
        }
        if ($productBrandId > 0) {
            $productQuery->where('products.brand_id', $productBrandId);
        }
        if ($productStatus !== '') {
            $productQuery->where('products.is_active', $productStatus === 'active');
        }
        if ($productAvailability !== '') {
            $hasWholesale = DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->whereIn('stores.id', $storeIds)
                ->where('store_types.code', 'B2B')
                ->exists();
            $principalWarehouseId = $hasWholesale
                ? app(WholesalePrincipal::class)->warehouseId()
                : null;
            $inventoryScope = static function ($query, bool $positiveOnly = false) use ($principalWarehouseId): void {
                $query->selectRaw('1')
                    ->from('inventories as catalog_filter_inventory')
                    ->join('warehouses as catalog_filter_warehouse', 'catalog_filter_warehouse.id', '=', 'catalog_filter_inventory.warehouse_id')
                    ->whereColumn('catalog_filter_inventory.product_id', 'products.id')
                    ->whereColumn('catalog_filter_warehouse.store_id', 'catalogs.store_id')
                    ->where('catalog_filter_warehouse.is_active', true)
                    ->where(static function ($scope) use ($principalWarehouseId): void {
                        $scope->where('catalogs.channel', '!=', 'b2b');
                        if ($principalWarehouseId !== null) {
                            $scope->orWhere('catalog_filter_warehouse.id', $principalWarehouseId);
                        }
                    });
                if ($positiveOnly) {
                    $query->whereColumn('catalog_filter_inventory.quantity', '>', 'catalog_filter_inventory.reserved_quantity');
                }
            };

            if ($productAvailability === 'out_of_stock') {
                $productQuery
                    ->whereExists(static function ($query) use ($inventoryScope): void {
                        $inventoryScope($query);
                    })
                    ->whereNotExists(static function ($query) use ($inventoryScope): void {
                        $inventoryScope($query, true);
                    });
            } else {
                $productQuery->where(static function ($query) use ($inventoryScope): void {
                    $query->whereNotExists(static function ($subquery) use ($inventoryScope): void {
                        $inventoryScope($subquery);
                    })->orWhereExists(static function ($subquery) use ($inventoryScope): void {
                        $inventoryScope($subquery, true);
                    });
                });
            }
        }

        if ($productHealth !== '') {
            $assignmentExists = static function ($query, ?string $condition = null): void {
                $query->selectRaw('1')
                    ->from('store_products as catalog_health_assignment')
                    ->whereColumn('catalog_health_assignment.product_id', 'products.id')
                    ->whereColumn('catalog_health_assignment.store_id', 'catalogs.store_id');

                if ($condition === 'unpriced') {
                    $query->whereNull('catalog_health_assignment.price');
                } elseif ($condition === 'margin_exception') {
                    $query->whereNotNull('catalog_health_assignment.price')
                        ->whereNotNull('catalog_health_assignment.cost_price')
                        ->whereColumn('catalog_health_assignment.price', '<=', 'catalog_health_assignment.cost_price');
                } elseif ($condition === 'inactive') {
                    $query->where('catalog_health_assignment.is_active', false);
                }
            };

            if ($productHealth === 'unpriced') {
                $productQuery->where(function ($query) use ($assignmentExists): void {
                    $query->whereNotExists(static function ($subquery) use ($assignmentExists): void {
                        $assignmentExists($subquery);
                    })->orWhereExists(static function ($subquery) use ($assignmentExists): void {
                        $assignmentExists($subquery, 'unpriced');
                    });
                });
            } elseif ($productHealth === 'margin_exception') {
                $productQuery->whereExists(static function ($query) use ($assignmentExists): void {
                    $assignmentExists($query, 'margin_exception');
                });
            } elseif ($productHealth === 'missing_category') {
                $productQuery->whereNull('products.category_id');
            } elseif ($productHealth === 'missing_brand') {
                $productQuery->whereNull('products.brand_id');
            } elseif ($productHealth === 'low_stock') {
                $productQuery
                    ->where('products.low_stock_threshold', '>', 0)
                    ->whereRaw('(SELECT COALESCE(SUM(catalog_health_inventory.quantity - catalog_health_inventory.reserved_quantity), 0) FROM inventories AS catalog_health_inventory INNER JOIN warehouses AS catalog_health_warehouse ON catalog_health_warehouse.id = catalog_health_inventory.warehouse_id WHERE catalog_health_inventory.product_id = products.id AND catalog_health_warehouse.store_id = catalogs.store_id AND catalog_health_warehouse.is_active = 1) > 0')
                    ->whereRaw('(SELECT COALESCE(SUM(catalog_health_inventory.quantity - catalog_health_inventory.reserved_quantity), 0) FROM inventories AS catalog_health_inventory INNER JOIN warehouses AS catalog_health_warehouse ON catalog_health_warehouse.id = catalog_health_inventory.warehouse_id WHERE catalog_health_inventory.product_id = products.id AND catalog_health_warehouse.store_id = catalogs.store_id AND catalog_health_warehouse.is_active = 1) <= products.low_stock_threshold');
            } elseif ($productHealth === 'unavailable') {
                $productQuery->where(function ($query) use ($assignmentExists): void {
                    $query->where('products.lifecycle_state', '!=', 'active')
                        ->orWhere('products.is_active', false)
                        ->orWhereExists(static function ($subquery) use ($assignmentExists): void {
                            $assignmentExists($subquery, 'inactive');
                        })
                        ->orWhereExists(static function ($subquery): void {
                            $subquery->selectRaw('1')
                                ->from('product_commercial_policies as catalog_health_policy')
                                ->whereColumn('catalog_health_policy.product_id', 'products.id')
                                ->where('catalog_health_policy.status', 'CLOSED');
                        });
                });
            } elseif ($productHealth === 'data_quality') {
                $productQuery->where(function ($query) use ($assignmentExists): void {
                    $query->whereNull('products.category_id')
                        ->orWhereNull('products.brand_id')
                        ->orWhereNotExists(static function ($subquery) use ($assignmentExists): void {
                            $assignmentExists($subquery);
                        })
                        ->orWhereExists(static function ($subquery) use ($assignmentExists): void {
                            $assignmentExists($subquery, 'unpriced');
                        })
                        ->orWhereNotExists(static function ($subquery): void {
                            $subquery->selectRaw('1')
                                ->from('product_images as catalog_health_image')
                                ->whereColumn('catalog_health_image.product_id', 'products.id');
                        });
                });
            }
        }

        $products = $productQuery
            ->orderByDesc('products.id')
            ->paginate(25, $productColumns, 'product_page')
            ->withQueryString();
        $products->setCollection($products->getCollection()->map(function (object $product): object {
            $availability = $this->availability->forStoreProduct(
                (int) $product->catalog_store_id,
                (int) $product->id,
            );
            $product->available_quantity = $availability['available_quantity'];
            $product->availability_state = $availability['availability_state'];
            $product->is_available = $availability['is_available'];

            return $product;
        }));

        $categoryQuery = DB::table('categories')
            ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
            ->join('stores as catalog_store', 'catalog_store.id', '=', 'catalogs.store_id')
            ->leftJoin('categories as parent', 'parent.id', '=', 'categories.parent_id')
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.is_migration_quarantine', false);

        $categoryRowsQuery = clone $categoryQuery;
        if ($categorySearch !== '') {
            $categoryRowsQuery->where('categories.name', 'like', '%'.$categorySearch.'%');
        }
        if ($categoryParent === 'root') {
            $categoryRowsQuery->whereNull('categories.parent_id');
        } elseif ($categoryParent !== '') {
            $categoryRowsQuery->where('categories.parent_id', (int) $categoryParent);
        }
        if ($categoryStatus !== '') {
            $categoryRowsQuery->where('categories.is_active', $categoryStatus === 'active');
        }
        $categoryColumns = [
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
        ];
        $categories = (clone $categoryQuery)->orderBy('categories.name')->get($categoryColumns);
        $categoryRows = $categoryRowsQuery
            ->orderBy('categories.name')
            ->paginate(25, $categoryColumns, 'category_page')
            ->withQueryString();

        $pageProductIds = $products->getCollection()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $pageBrandIds = $products->getCollection()
            ->pluck('brand_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $pageUnitIds = $products->getCollection()
            ->pluck('unit_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return view('admin.catalog-management', [
            'user' => $actor,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => $navContext,
            'inventoryUrl' => $inventoryUrl,
            'tab' => $tab,
            'products' => $products,
            'categories' => $categories,
            'categoryRows' => $categoryRows,
            'productFilters' => [
                'q' => $productSearch,
                'category_id' => $productCategoryId,
                'brand_id' => $productBrandId,
                'status' => $productStatus,
                'availability' => $productAvailability,
                'health' => $productHealth,
            ],
            'categoryFilters' => [
                'q' => $categorySearch,
                'parent' => $categoryParent,
                'status' => $categoryStatus,
            ],
            'filterBrands' => $this->lookups
                ->visible(Brand::query(), $actor, 'brands')
                ->orderBy('brands.name')
                ->get(),
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
            'currentBrands' => DB::table('brands')
                ->whereIn('id', $pageBrandIds)
                ->get(['id', 'name', 'is_active', 'scope', 'store_id'])
                ->keyBy('id'),
            'currentUnits' => DB::table('units')
                ->whereIn('id', $pageUnitIds)
                ->get(['id', 'name', 'code', 'is_active', 'scope', 'store_id'])
                ->keyBy('id'),
            'stores' => DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->leftJoin('catalogs as default_catalog', function ($join): void {
                    $join->on('default_catalog.store_id', '=', 'stores.id')
                        ->where('default_catalog.code', '=', 'default')
                        ->where('default_catalog.is_migration_quarantine', '=', false);
                })
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
                    'default_catalog.id as default_catalog_id',
                ]),
            'storeTypes' => collect(),
            'productImages' => DB::table('product_images')
                ->join('products', 'products.id', '=', 'product_images.product_id')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->whereIn('catalogs.store_id', $storeIds)
                ->where('catalogs.is_migration_quarantine', false)
                ->whereIn('product_images.product_id', $pageProductIds)
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
                ->whereIn('product_id', $pageProductIds)
                ->get()
                ->keyBy(fn ($row) => $row->store_id.':'.$row->product_id),
            'currencies' => DB::table('settings')
                ->whereIn('store_id', $storeIds)
                ->whereIn('key', ['store.currency', 'currency', 'default_currency', 'checkout.currency'])
                ->get(['store_id', 'key', 'value'])
                ->groupBy('store_id')
                ->map(function ($rows): ?string {
                    foreach (['store.currency', 'currency', 'default_currency', 'checkout.currency'] as $key) {
                        $setting = $rows->firstWhere('key', $key);
                        if ($setting === null) {
                            continue;
                        }

                        $decoded = json_decode((string) $setting->value, true);
                        if (is_string($decoded) && trim($decoded) !== '') {
                            return trim($decoded);
                        }

                        $raw = trim((string) $setting->value, ' "');
                        if ($raw !== '') {
                            return $raw;
                        }
                    }

                    return null;
                }),
            'catalogDashboard' => $tab === 'overview'
                ? $this->catalogCompletion->dashboard($storeIds, [
                    'from' => $request->query('from'),
                    'to' => $request->query('to'),
                ])
                : null,
            'attributeDefinitions' => $tab === 'attributes'
                ? $this->catalogCompletion->attributeDefinitions($storeIds)
                : null,
            'product360' => $request->integer('product_detail') > 0
                ? $this->catalogCompletion->product360($storeIds, $request->integer('product_detail'))
                : null,
            'category360' => $request->integer('category_detail') > 0
                ? $this->catalogCompletion->category360($storeIds, $request->integer('category_detail'))
                : null,
            'importRuns' => $tab === 'import'
                ? DB::table('catalog_import_runs')
                    ->whereIn('store_id', $storeIds)
                    ->orderByDesc('id')
                    ->paginate(25, ['*'], 'import_run_page')
                    ->withQueryString()
                : null,
            'reportsUrl' => route('admin.erp.bi', ['report' => 'catalog.availability']),
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
        $sku = $this->skuGenerator->generate((int) $catalog->id, (int) $data['store_id']);

        $active = $request->boolean('is_active', true);
        $id = DB::transaction(function () use ($data, $catalog, $sku, $active): int {
            $id = (int) DB::table('products')->insertGetId([
                'catalog_id' => $catalog->id,
                'sku' => $sku,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'unit_id' => $data['unit_id'],
                'is_active' => $active,
                'lifecycle_state' => $active ? 'active' : 'draft',
                'lifecycle_effective_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('store_products')->insert([
                'store_id' => $data['store_id'],
                'product_id' => $id,
                'price' => $data['price'] ?? null,
                'is_active' => $active,
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
            'sku' => $sku,
        ], $request);

        return back()->with('status', $this->msg('تمت إضافة المنتج وصوره.', 'Product and images added.'));
    }

    public function updateProduct(Request $request, int $product): RedirectResponse
    {
        $owner = $this->productOwner($product);
        $this->authorizeCatalogAction($request, 'catalog.edit', (int) $owner->store_id, (string) $owner->channel);

        $data = $request->validate([
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
        $beforeProduct = DB::table('products')->where('id', $product)->first();
        $active = $request->boolean('is_active');
        $nextLifecycle = $active ? 'active' : 'paused';
        DB::transaction(function () use ($product, $data, $active, $nextLifecycle, $beforeProduct, $request): void {
            DB::table('products')->where('id', $product)->update([
                ...collect($data)->except(['is_active', 'images'])->all(),
                'is_active' => $active,
                'lifecycle_state' => $nextLifecycle,
                'lifecycle_effective_at' => ($beforeProduct->lifecycle_state ?? null) === $nextLifecycle
                    ? ($beforeProduct->lifecycle_effective_at ?? now())
                    : now(),
                'end_of_life_at' => null,
                'updated_at' => now(),
            ]);

            if (($beforeProduct->lifecycle_state ?? null) !== $nextLifecycle) {
                DB::table('product_lifecycle_transitions')->insert([
                    'product_id' => $product,
                    'user_id' => $this->actor($request)->id,
                    'from_state' => $beforeProduct?->lifecycle_state,
                    'to_state' => $nextLifecycle,
                    'effective_at' => now(),
                    'reason' => __('catalog_completion.lifecycle.legacy_active_toggle_reason'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        app(AuditLogger::class)->record(
            'catalog.product.updated',
            $this->actor($request),
            Product::query()->find($product),
            $beforeProduct === null ? null : (array) $beforeProduct,
            (array) DB::table('products')->where('id', $product)->first(),
            $request,
        );

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
            $beforeProduct = DB::table('products')->where('id', $product)->first();
            DB::transaction(function () use ($product, $request, $beforeProduct): void {
                DB::table('products')->where('id', $product)->update([
                    'is_active' => false,
                    'lifecycle_state' => 'end_of_life',
                    'lifecycle_effective_at' => now(),
                    'end_of_life_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('product_lifecycle_transitions')->insert([
                    'product_id' => $product,
                    'user_id' => $this->actor($request)->id,
                    'from_state' => $beforeProduct?->lifecycle_state,
                    'to_state' => 'end_of_life',
                    'effective_at' => now(),
                    'reason' => __('catalog_completion.lifecycle.referenced_delete_reason'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            app(AuditLogger::class)->record(
                'catalog.product.lifecycle.transitioned',
                $this->actor($request),
                Product::query()->find($product),
                $beforeProduct === null ? null : (array) $beforeProduct,
                ['lifecycle_state' => 'end_of_life'],
                $request,
            );

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
        app(AuditLogger::class)->record(
            'catalog.category.created',
            $this->actor($request),
            Category::query()->find($categoryId),
            null,
            ['category_id' => $categoryId, 'catalog_id' => (int) $catalog->id],
            $request,
        );

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

        $beforeCategory = DB::table('categories')->where('id', $category)->first();
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
        app(AuditLogger::class)->record(
            'catalog.category.updated',
            $this->actor($request),
            Category::query()->find($category),
            $beforeCategory === null ? null : (array) $beforeCategory,
            (array) DB::table('categories')->where('id', $category)->first(),
            $request,
        );

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

    public function storeAttribute(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);
        $catalog = $this->catalogs->defaultCatalogForStore((int) $data['store_id']);
        $this->authorizeCatalogAction($request, 'catalog.manage', (int) $data['store_id'], (string) $catalog->channel);

        $this->catalogCompletion->storeAttributeDefinition(
            $this->actor($request),
            (int) $catalog->id,
            $request->all(),
            $request,
        );

        return redirect()->route('admin.catalog.index', $this->catalogRouteParams($request, 'attributes', (int) $data['store_id']))
            ->with('status', __('catalog_completion.status.attribute_created'));
    }

    public function updateAttribute(Request $request, int $attribute): RedirectResponse
    {
        $owner = DB::table('product_attribute_definitions as attributes')
            ->join('catalogs', 'catalogs.id', '=', 'attributes.catalog_id')
            ->where('attributes.id', $attribute)
            ->first(['attributes.catalog_id', 'catalogs.store_id', 'catalogs.channel']);
        abort_if($owner === null, 404);

        $this->authorizeCatalogAction($request, 'catalog.manage', (int) $owner->store_id, (string) $owner->channel);
        $this->catalogCompletion->updateAttributeDefinition(
            $this->actor($request),
            $attribute,
            (int) $owner->catalog_id,
            $request->all(),
            $request,
        );

        return redirect()->route('admin.catalog.index', $this->catalogRouteParams($request, 'attributes', (int) $owner->store_id))
            ->with('status', __('catalog_completion.status.attribute_updated'));
    }

    public function saveProductAttributes(Request $request, int $product): RedirectResponse
    {
        $owner = $this->productOwner($product);
        $this->authorizeCatalogAction($request, 'catalog.edit', (int) $owner->store_id, (string) $owner->channel);
        $data = $request->validate(['attributes' => ['nullable', 'array']]);

        $this->catalogCompletion->saveProductAttributes(
            $this->actor($request),
            [(int) $owner->store_id],
            $product,
            $data['attributes'] ?? [],
            $request,
        );

        return redirect()->route('admin.catalog.index', $this->catalogRouteParams($request, 'products', (int) $owner->store_id, [
            'product_detail' => $product,
        ]))->with('status', __('catalog_completion.status.attributes_saved'));
    }

    public function transitionProductLifecycle(Request $request, int $product): RedirectResponse
    {
        $owner = $this->productOwner($product);
        $this->authorizeCatalogAction($request, 'catalog.edit', (int) $owner->store_id, (string) $owner->channel);

        $this->catalogCompletion->transitionLifecycle(
            $this->actor($request),
            [(int) $owner->store_id],
            $product,
            $request->only(['state', 'effective_at', 'reason']),
            $request,
        );

        return redirect()->route('admin.catalog.index', $this->catalogRouteParams($request, 'products', (int) $owner->store_id, [
            'product_detail' => $product,
        ]))->with('status', __('catalog_completion.status.lifecycle_updated'));
    }

    public function exportCatalog(Request $request): Response
    {
        $actor = $this->actor($request);
        $storeIds = $this->catalogReadableStoreIds($actor, $this->visibleStoreIds($actor, $request));
        if ($storeIds === []) {
            abort(403);
        }

        $rows = $this->catalogCompletion->bulkExportRows($storeIds);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            abort(500);
        }

        $headers = [
            'store_id', 'channel', 'sku', 'name', 'description', 'category', 'brand', 'unit_code',
            'lifecycle_state', 'lifecycle_effective_at', 'end_of_life_at', 'price', 'cost_price',
            'available_quantity', 'commercial_status', 'attributes_json',
        ];
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string $header): mixed => $row[$header] ?? null, $headers));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response("\xEF\xBB\xBF".($csv === false ? '' : $csv), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="foodex-catalog-export-'.now()->format('Ymd-His').'.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @param array<string,int|string> $extra @return array<string,int|string> */
    private function catalogRouteParams(Request $request, string $tab, int $storeId, array $extra = []): array
    {
        $params = ['tab' => $tab, 'store_id' => $storeId, ...$extra];
        if ($request->boolean('support_access')) {
            $params['support_access'] = 1;
        }

        return $params;
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
