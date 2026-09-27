<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CatalogOwnership;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CatalogManagementController extends Controller
{
    public function __construct(
        private readonly CatalogOwnership $catalogs,
        private readonly TenantContextResolver $tenantContext,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('catalog.view');

        $actor = $this->actor($request);
        $tab = in_array((string) $request->query('tab'), ['products', 'categories', 'brands', 'units', 'stores'], true)
            ? (string) $request->query('tab')
            : 'products';
        $storeIds = $this->visibleStoreIds($actor, $request);

        return view('admin.catalog-management', [
            'user' => $actor,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => 'catalog_management',
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
                    'categories.parent_id',
                    'categories.is_active',
                    'categories.catalog_id',
                    'catalogs.store_id as catalog_store_id',
                    'catalog_store.name as catalog_store_name',
                    'parent.name as parent_name',
                ]),
            'brands' => DB::table('brands')->orderBy('name')->get(),
            'units' => DB::table('units')->orderBy('name')->get(),
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
            'storeTypes' => DB::table('store_types')->orderBy('code')->get(),
            'assignments' => DB::table('store_products')->get()
                ->keyBy(fn ($row) => $row->store_id.':'.$row->product_id),
        ]);
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        Gate::authorize('catalog.create');

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
        ]);

        $catalog = $this->catalogs->defaultCatalogForStore((int) $data['store_id']);
        $this->assertStoreAccess($request, (int) $data['store_id'], (string) $catalog->channel);
        $this->catalogs->assertSameCatalog(isset($data['category_id']) ? (int) $data['category_id'] : null, (int) $catalog->id);
        $this->assertSkuAvailable((int) $catalog->id, (string) $data['sku']);

        DB::transaction(function () use ($data, $request, $catalog): void {
            $id = DB::table('products')->insertGetId([
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
        });

        return back()->with('status', $this->msg('تمت إضافة المنتج.', 'Product added.'));
    }

    public function updateProduct(Request $request, int $product): RedirectResponse
    {
        Gate::authorize('catalog.edit');

        $owner = $this->productOwner($product);
        $this->assertStoreAccess($request, (int) $owner->store_id, (string) $owner->channel);

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->catalogs->assertSameCatalog(isset($data['category_id']) ? (int) $data['category_id'] : null, (int) $owner->catalog_id);
        $this->assertSkuAvailable((int) $owner->catalog_id, (string) $data['sku'], $product);

        DB::table('products')->where('id', $product)->update([
            ...collect($data)->except('is_active')->all(),
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تم تعديل المنتج.', 'Product updated.'));
    }

    public function destroyProduct(Request $request, int $product): RedirectResponse
    {
        Gate::authorize('catalog.delete');

        $owner = $this->productOwner($product);
        $this->assertStoreAccess($request, (int) $owner->store_id, (string) $owner->channel);
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

        DB::table('products')->where('id', $product)->delete();

        return back()->with('status', $this->msg('تم حذف المنتج.', 'Product deleted.'));
    }

    public function assignProduct(Request $request, int $product): RedirectResponse
    {
        Gate::authorize('catalog.manage');

        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $owner = $this->productOwner($product);
        $this->assertStoreAccess($request, (int) $owner->store_id, (string) $owner->channel);
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
        Gate::authorize('catalog.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $catalog = $this->catalogs->defaultCatalogForStore((int) $data['store_id']);
        $this->assertStoreAccess($request, (int) $data['store_id'], (string) $catalog->channel);
        $this->catalogs->assertParentInCatalog(isset($data['parent_id']) ? (int) $data['parent_id'] : null, (int) $catalog->id);
        $slug = ($data['slug'] ?? null) ?: Str::slug($data['name']).'-'.Str::lower(Str::random(5));
        $this->assertSlugAvailable((int) $catalog->id, $slug);

        DB::table('categories')->insert([
            'catalog_id' => $catalog->id,
            'name' => $data['name'],
            'slug' => $slug,
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تمت إضافة التصنيف.', 'Category added.'));
    }

    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        Gate::authorize('catalog.manage');

        $owner = $this->categoryOwner($category);
        $this->assertStoreAccess($request, (int) $owner->store_id, (string) $owner->channel);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id', Rule::notIn([$category])],
            'is_active' => ['nullable', 'boolean'],
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

        return back()->with('status', $this->msg('تم تعديل التصنيف.', 'Category updated.'));
    }

    public function destroyCategory(Request $request, int $category): RedirectResponse
    {
        Gate::authorize('catalog.manage');

        $owner = $this->categoryOwner($category);
        $this->assertStoreAccess($request, (int) $owner->store_id, (string) $owner->channel);

        if (DB::table('products')->where('category_id', $category)->exists()
            || DB::table('categories')->where('parent_id', $category)->exists()) {
            return back()->withErrors([
                'catalog' => $this->msg(
                    'لا يمكن حذف تصنيف مستخدم بواسطة منتجات أو تصنيفات فرعية.',
                    'A category used by products or child categories cannot be deleted.',
                ),
            ]);
        }

        DB::table('categories')->where('id', $category)->delete();

        return back()->with('status', $this->msg('تم حذف التصنيف.', 'Category deleted.'));
    }

    public function storeBrand(Request $request): RedirectResponse
    {
        Gate::authorize('catalog.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:brands,slug'],
        ]);

        DB::table('brands')->insert([
            'name' => $data['name'],
            'slug' => $data['slug'] ?: Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تمت إضافة العلامة التجارية.', 'Brand added.'));
    }

    public function destroyBrand(Request $request, int $brand): RedirectResponse
    {
        Gate::authorize('catalog.manage');

        DB::table('products')->where('brand_id', $brand)->update([
            'brand_id' => null,
            'updated_at' => now(),
        ]);
        DB::table('brands')->where('id', $brand)->delete();

        return back()->with('status', $this->msg('تم حذف العلامة التجارية.', 'Brand deleted.'));
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        Gate::authorize('catalog.manage');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:units,code'],
            'name' => ['required', 'string', 'max:255'],
            'decimal_places' => ['required', 'integer', 'between:0,6'],
        ]);

        DB::table('units')->insert([
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تمت إضافة وحدة القياس.', 'Unit added.'));
    }

    public function storeStore(Request $request): RedirectResponse
    {
        Gate::authorize('stores.manage');

        $data = $request->validate([
            'store_type_id' => ['required', 'integer', 'exists:store_types,id'],
            'code' => ['required', 'string', 'max:50', 'unique:stores,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::table('stores')->insert([
            'store_type_id' => $data['store_type_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تمت إضافة المتجر.', 'Store added.'));
    }

    public function updateStore(Request $request, int $store): RedirectResponse
    {
        Gate::authorize('stores.manage');

        $data = $request->validate([
            'store_type_id' => ['required', 'integer', 'exists:store_types,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('stores', 'code')->ignore($store)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::table('stores')->where('id', $store)->update([
            'store_type_id' => $data['store_type_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تم تعديل المتجر.', 'Store updated.'));
    }

    private function productOwner(int $product): object
    {
        $owner = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.id', $product)
            ->first(['products.catalog_id', 'catalogs.store_id', 'catalogs.channel']);
        abort_if($owner === null, 404);

        return $owner;
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

        if ($actor->hasRole('SUPER_ADMIN') && $request->boolean('support_access') && $requested > 0) {
            $this->tenantContext->retail($actor, $requested, true, $request);

            return [$requested];
        }

        if ($actor->hasRole('SUPER_ADMIN') || $actor->hasRole('B2B_ADMIN')) {
            return DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('store_types.code', 'B2B')
                ->where('stores.is_active', true)
                ->pluck('stores.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        return $this->tenantContext->retailStoreIds($actor);
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
