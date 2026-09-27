<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MasterDataController extends Controller
{
    private const RESOURCES = [
        'categories',
        'brands',
        'units',
        'products',
        'stores',
        'warehouses',
        'customers',
        'b2b-clients',
        'price-tiers',
        'promotions',
        'banners',
    ];

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, string $resource): View
    {
        $user = $this->actor($request);
        $this->assertResource($resource);
        $this->authorizeView($user, $resource);
        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        $editId = max(0, (int) $request->query('edit', 0));
        $edit = $editId > 0 ? $this->editRow($resource, $editId) : null;
        $data = $this->listData($resource);

        return view('admin.master-data', [
            'user' => $user,
            'resource' => $resource,
            'title' => $this->title($resource),
            'columns' => $data['columns'],
            'rows' => $data['rows'],
            'edit' => $edit,
            'options' => $this->options(),
            'resourceLinks' => $this->resourceLinks($user),
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => 'manage_'.$resource,
            'canCreate' => $this->canMutate($user, $resource, 'create'),
            'canEdit' => $this->canMutate($user, $resource, 'edit'),
            'canDelete' => $this->canMutate($user, $resource, 'delete'),
        ]);
    }

    public function store(Request $request, string $resource): RedirectResponse
    {
        $user = $this->actor($request);
        $this->assertResource($resource);
        abort_unless($this->canMutate($user, $resource, 'create'), 403);

        $after = DB::transaction(fn (): array => $this->storeResource($request, $resource));
        $this->audit->record('admin.master_data.'.$resource.'.created', $user, null, null, $after, $request);

        return redirect()
            ->route('admin.manage.'.$resource)
            ->with('status', $this->message('created'));
    }

    public function update(Request $request, string $resource, int $id): RedirectResponse
    {
        $user = $this->actor($request);
        $this->assertResource($resource);
        abort_unless($this->canMutate($user, $resource, 'edit'), 403);

        $before = $this->editRow($resource, $id);
        abort_if($before === null, 404);

        $after = DB::transaction(fn (): array => $this->updateResource($request, $resource, $id));
        $this->audit->record('admin.master_data.'.$resource.'.updated', $user, null, $before, $after, $request);

        return redirect()
            ->route('admin.manage.'.$resource)
            ->with('status', $this->message('updated'));
    }

    public function destroy(Request $request, string $resource, int $id): RedirectResponse
    {
        $user = $this->actor($request);
        $this->assertResource($resource);
        abort_unless($this->canMutate($user, $resource, 'delete'), 403);

        $before = $this->editRow($resource, $id);
        abort_if($before === null, 404);

        DB::transaction(fn () => $this->destroyResource($resource, $id));
        $this->audit->record('admin.master_data.'.$resource.'.deleted', $user, null, $before, null, $request);

        return redirect()
            ->route('admin.manage.'.$resource)
            ->with('status', $this->message('deleted'));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function assertResource(string $resource): void
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);
    }

    private function authorizeView(User $user, string $resource): void
    {
        $permissions = [
            'categories' => 'catalog.view',
            'brands' => 'catalog.view',
            'units' => 'catalog.view',
            'products' => 'catalog.view',
            'stores' => 'stores.view',
            'warehouses' => 'inventory.view',
            'customers' => 'customers.view',
            'b2b-clients' => 'b2b.accounts.view',
            'price-tiers' => 'b2b.pricing.view',
            'promotions' => 'promotions.view',
            'banners' => 'promotions.view',
        ];

        abort_unless($user->hasPermission($permissions[$resource]), 403);
    }

    private function canMutate(User $user, string $resource, string $action): bool
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return true;
        }

        return match ($resource) {
            'categories', 'brands', 'units' => $user->hasPermission('catalog.manage'),
            'products' => match ($action) {
                'create' => $user->hasPermission('catalog.create') || $user->hasPermission('catalog.manage'),
                'edit' => $user->hasPermission('catalog.edit') || $user->hasPermission('catalog.manage'),
                'delete' => $user->hasPermission('catalog.delete') || $user->hasPermission('catalog.manage'),
                default => false,
            },
            'stores' => $user->hasPermission('stores.manage'),
            'warehouses' => $user->hasPermission('inventory.manage'),
            'customers' => match ($action) {
                'create' => $user->hasPermission('customers.create') || $user->hasPermission('customers.manage'),
                'edit' => $user->hasPermission('customers.edit') || $user->hasPermission('customers.manage'),
                'delete' => $user->hasPermission('customers.delete') || $user->hasPermission('customers.manage'),
                default => false,
            },
            'b2b-clients' => $user->hasPermission('b2b.accounts.manage'),
            'price-tiers' => $user->hasPermission('b2b.pricing.manage'),
            'promotions', 'banners' => $user->hasPermission('promotions.manage'),
            default => false,
        };
    }

    /** @return array<int, array{key:string,label:string,route:string}> */
    private function resourceLinks(User $user): array
    {
        $links = [];
        foreach (self::RESOURCES as $resource) {
            try {
                $this->authorizeView($user, $resource);
            } catch (HttpException) {
                continue;
            }

            $links[] = [
                'key' => $resource,
                'label' => $this->title($resource),
                'route' => 'admin.manage.'.$resource,
            ];
        }

        return $links;
    }

    private function title(string $resource): string
    {
        $ar = App::isLocale('ar');

        return match ($resource) {
            'categories' => $ar ? 'إدارة التصنيفات' : 'Category Management',
            'brands' => $ar ? 'إدارة العلامات التجارية' : 'Brand Management',
            'units' => $ar ? 'إدارة وحدات القياس' : 'Unit Management',
            'products' => $ar ? 'إدارة المنتجات' : 'Product Management',
            'stores' => $ar ? 'إدارة المتاجر' : 'Store Management',
            'warehouses' => $ar ? 'إدارة المخازن' : 'Warehouse Management',
            'customers' => $ar ? 'إدارة العملاء' : 'Customer Management',
            'b2b-clients' => $ar ? 'إدارة عملاء الجملة B2B' : 'B2B Client Management',
            'price-tiers' => $ar ? 'شرائح أسعار الجملة' : 'B2B Price Tiers',
            'promotions' => $ar ? 'إدارة العروض' : 'Promotion Management',
            'banners' => $ar ? 'إدارة البانرات والمحتوى' : 'Banner & Content Management',
            default => $resource,
        };
    }

    /** @return array{columns:array<string,string>,rows:array<int,array<string,mixed>>} */
    private function listData(string $resource): array
    {
        $ar = App::isLocale('ar');

        return match ($resource) {
            'categories' => [
                'columns' => [
                    'name' => $ar ? 'الاسم' : 'Name',
                    'slug' => 'Slug',
                    'parent' => $ar ? 'التصنيف الأب' : 'Parent',
                    'products' => $ar ? 'المنتجات' : 'Products',
                    'status' => $ar ? 'الحالة' : 'Status',
                ],
                'rows' => DB::table('categories as c')
                    ->leftJoin('categories as p', 'p.id', '=', 'c.parent_id')
                    ->orderBy('c.name')
                    ->limit(250)
                    ->get(['c.id', 'c.name', 'c.slug', 'p.name as parent', 'c.is_active'])
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'name' => $row->name,
                        'slug' => $row->slug,
                        'parent' => $row->parent ?: '-',
                        'products' => DB::table('products')->where('category_id', $row->id)->count(),
                        'status' => (bool) $row->is_active,
                    ])->all(),
            ],
            'brands' => [
                'columns' => [
                    'name' => $ar ? 'الاسم' : 'Name',
                    'slug' => 'Slug',
                    'products' => $ar ? 'المنتجات' : 'Products',
                ],
                'rows' => DB::table('brands')->orderBy('name')->limit(250)->get()
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'name' => $row->name,
                        'slug' => $row->slug,
                        'products' => DB::table('products')->where('brand_id', $row->id)->count(),
                    ])->all(),
            ],
            'units' => [
                'columns' => [
                    'code' => $ar ? 'الكود' : 'Code',
                    'name' => $ar ? 'الاسم' : 'Name',
                    'decimal_places' => $ar ? 'الخانات العشرية' : 'Decimal places',
                    'products' => $ar ? 'المنتجات' : 'Products',
                ],
                'rows' => DB::table('units')->orderBy('code')->limit(250)->get()
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'code' => $row->code,
                        'name' => $row->name,
                        'decimal_places' => (int) $row->decimal_places,
                        'products' => DB::table('products')->where('unit_id', $row->id)->count(),
                    ])->all(),
            ],
            'products' => [
                'columns' => [
                    'sku' => 'SKU',
                    'name' => $ar ? 'المنتج' : 'Product',
                    'category' => $ar ? 'التصنيف' : 'Category',
                    'unit' => $ar ? 'الوحدة' : 'Unit',
                    'stores' => $ar ? 'المتاجر' : 'Stores',
                    'price' => $ar ? 'السعر' : 'Price',
                    'status' => $ar ? 'الحالة' : 'Status',
                ],
                'rows' => DB::table('products')
                    ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                    ->join('units', 'units.id', '=', 'products.unit_id')
                    ->orderBy('products.name')
                    ->limit(250)
                    ->get([
                        'products.id',
                        'products.sku',
                        'products.name',
                        'products.is_active',
                        'categories.name as category',
                        'units.code as unit',
                    ])->map(function ($row): array {
                        $prices = DB::table('store_products')->where('product_id', $row->id)->whereNotNull('price')->pluck('price');
                        $min = $prices->isEmpty() ? null : (float) $prices->min();
                        $max = $prices->isEmpty() ? null : (float) $prices->max();
                        $price = $min === null ? '-' : number_format($min, 3).' KWD';
                        if ($min !== null && $max !== null && $max !== $min) {
                            $price = number_format($min, 3).' – '.number_format($max, 3).' KWD';
                        }

                        return [
                            'id' => (int) $row->id,
                            'sku' => $row->sku,
                            'name' => $row->name,
                            'category' => $row->category ?: '-',
                            'unit' => $row->unit,
                            'stores' => DB::table('store_products')->where('product_id', $row->id)->count(),
                            'price' => $price,
                            'status' => (bool) $row->is_active,
                        ];
                    })->all(),
            ],
            'stores' => [
                'columns' => [
                    'code' => $ar ? 'الكود' : 'Code',
                    'name' => $ar ? 'الاسم' : 'Name',
                    'type' => $ar ? 'النوع' : 'Type',
                    'products' => $ar ? 'المنتجات' : 'Products',
                    'orders' => $ar ? 'الطلبات' : 'Orders',
                    'status' => $ar ? 'الحالة' : 'Status',
                ],
                'rows' => DB::table('stores')
                    ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                    ->orderBy('stores.name')
                    ->limit(250)
                    ->get(['stores.id', 'stores.code', 'stores.name', 'stores.is_active', 'store_types.name as type'])
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'code' => $row->code,
                        'name' => $row->name,
                        'type' => $row->type,
                        'products' => DB::table('store_products')->where('store_id', $row->id)->count(),
                        'orders' => DB::table('orders')->where('store_id', $row->id)->count(),
                        'status' => (bool) $row->is_active,
                    ])->all(),
            ],
            'warehouses' => [
                'columns' => [
                    'code' => $ar ? 'الكود' : 'Code',
                    'name' => $ar ? 'الاسم' : 'Name',
                    'store' => $ar ? 'المتجر' : 'Store',
                    'items' => $ar ? 'أصناف المخزون' : 'Inventory items',
                    'status' => $ar ? 'الحالة' : 'Status',
                ],
                'rows' => DB::table('warehouses')
                    ->leftJoin('stores', 'stores.id', '=', 'warehouses.store_id')
                    ->orderBy('warehouses.name')
                    ->limit(250)
                    ->get(['warehouses.id', 'warehouses.code', 'warehouses.name', 'warehouses.is_active', 'stores.name as store'])
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'code' => $row->code,
                        'name' => $row->name,
                        'store' => $row->store ?: '-',
                        'items' => DB::table('inventories')->where('warehouse_id', $row->id)->count(),
                        'status' => (bool) $row->is_active,
                    ])->all(),
            ],
            'customers' => [
                'columns' => [
                    'name' => $ar ? 'الاسم' : 'Name',
                    'type' => $ar ? 'النوع' : 'Type',
                    'phone' => $ar ? 'الهاتف' : 'Phone',
                    'email' => $ar ? 'البريد' : 'Email',
                    'orders' => $ar ? 'الطلبات' : 'Orders',
                ],
                'rows' => DB::table('customers')->orderBy('name')->limit(250)->get()
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'name' => $row->name,
                        'type' => strtoupper((string) $row->type),
                        'phone' => $row->phone ?: '-',
                        'email' => $row->email ?: '-',
                        'orders' => DB::table('orders')->where('customer_id', $row->id)->count(),
                    ])->all(),
            ],
            'b2b-clients' => [
                'columns' => [
                    'company' => $ar ? 'الشركة' : 'Company',
                    'name' => $ar ? 'المسؤول' : 'Contact',
                    'tier' => $ar ? 'شريحة السعر' : 'Price tier',
                    'credit_limit' => $ar ? 'حد الائتمان' : 'Credit limit',
                    'status' => $ar ? 'الحالة' : 'Status',
                ],
                'rows' => DB::table('b2b_accounts')
                    ->join('customers', 'customers.id', '=', 'b2b_accounts.customer_id')
                    ->leftJoin('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_accounts.price_tier_id')
                    ->orderBy('b2b_accounts.company_name')
                    ->limit(250)
                    ->get([
                        'b2b_accounts.id',
                        'b2b_accounts.company_name as company',
                        'b2b_accounts.credit_limit',
                        'b2b_accounts.status',
                        'customers.name',
                        'b2b_price_tiers.name as tier',
                    ])->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'company' => $row->company,
                        'name' => $row->name,
                        'tier' => $row->tier ?: '-',
                        'credit_limit' => number_format((float) $row->credit_limit, 3).' KWD',
                        'status' => (string) $row->status,
                    ])->all(),
            ],
            'price-tiers' => [
                'columns' => [
                    'code' => $ar ? 'الكود' : 'Code',
                    'name' => $ar ? 'الاسم' : 'Name',
                    'priority' => $ar ? 'الأولوية' : 'Priority',
                    'clients' => $ar ? 'العملاء' : 'Clients',
                ],
                'rows' => DB::table('b2b_price_tiers')->orderBy('priority')->orderBy('name')->limit(250)->get()
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'code' => $row->code,
                        'name' => $row->name,
                        'priority' => (int) $row->priority,
                        'clients' => DB::table('b2b_accounts')->where('price_tier_id', $row->id)->count(),
                    ])->all(),
            ],
            'promotions' => [
                'columns' => [
                    'name' => $ar ? 'الاسم' : 'Name',
                    'store' => $ar ? 'المتجر' : 'Store',
                    'type' => $ar ? 'النوع' : 'Type',
                    'value' => $ar ? 'القيمة' : 'Value',
                    'period' => $ar ? 'الفترة' : 'Period',
                    'status' => $ar ? 'الحالة' : 'Status',
                ],
                'rows' => DB::table('promotions')
                    ->leftJoin('stores', 'stores.id', '=', 'promotions.store_id')
                    ->orderByDesc('promotions.id')
                    ->limit(250)
                    ->get([
                        'promotions.id', 'promotions.name', 'promotions.type', 'promotions.value',
                        'promotions.starts_at', 'promotions.ends_at', 'promotions.is_active',
                        'stores.name as store',
                    ])->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'name' => $row->name,
                        'store' => $row->store ?: ($ar ? 'كل المتاجر' : 'All stores'),
                        'type' => $row->type,
                        'value' => $row->value === null ? '-' : number_format((float) $row->value, 3),
                        'period' => ($row->starts_at ?: '-').' → '.($row->ends_at ?: '-'),
                        'status' => (bool) $row->is_active,
                    ])->all(),
            ],
            'banners' => [
                'columns' => [
                    'title' => $ar ? 'العنوان' : 'Title',
                    'store' => $ar ? 'المتجر' : 'Store',
                    'image' => $ar ? 'مسار الصورة' : 'Image path',
                    'sort_order' => $ar ? 'الترتيب' : 'Sort order',
                    'status' => $ar ? 'الحالة' : 'Status',
                ],
                'rows' => DB::table('banners')
                    ->leftJoin('stores', 'stores.id', '=', 'banners.store_id')
                    ->orderBy('banners.sort_order')
                    ->orderByDesc('banners.id')
                    ->limit(250)
                    ->get([
                        'banners.id', 'banners.title', 'banners.image_path', 'banners.sort_order',
                        'banners.is_active', 'stores.name as store',
                    ])->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'title' => $row->title,
                        'store' => $row->store ?: ($ar ? 'كل المتاجر' : 'All stores'),
                        'image' => $row->image_path,
                        'sort_order' => (int) $row->sort_order,
                        'status' => (bool) $row->is_active,
                    ])->all(),
            ],
            default => ['columns' => [], 'rows' => []],
        };
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'categories' => DB::table('categories')->orderBy('name')->get(['id', 'name']),
            'brands' => DB::table('brands')->orderBy('name')->get(['id', 'name']),
            'units' => DB::table('units')->orderBy('code')->get(['id', 'code', 'name']),
            'stores' => DB::table('stores')->orderBy('name')->get(['id', 'code', 'name']),
            'store_types' => DB::table('store_types')->orderBy('code')->get(['id', 'code', 'name']),
            'price_tiers' => DB::table('b2b_price_tiers')->orderBy('priority')->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }

    /** @return array<string,mixed>|null */
    private function editRow(string $resource, int $id): ?array
    {
        $row = match ($resource) {
            'categories' => DB::table('categories')->where('id', $id)->first(),
            'brands' => DB::table('brands')->where('id', $id)->first(),
            'units' => DB::table('units')->where('id', $id)->first(),
            'products' => DB::table('products')->where('id', $id)->first(),
            'stores' => DB::table('stores')->where('id', $id)->first(),
            'warehouses' => DB::table('warehouses')->where('id', $id)->first(),
            'customers' => DB::table('customers')->where('id', $id)->first(),
            'b2b-clients' => DB::table('b2b_accounts')
                ->join('customers', 'customers.id', '=', 'b2b_accounts.customer_id')
                ->where('b2b_accounts.id', $id)
                ->first([
                    'b2b_accounts.*',
                    'customers.name as contact_name',
                    'customers.email as contact_email',
                    'customers.phone as contact_phone',
                ]),
            'price-tiers' => DB::table('b2b_price_tiers')->where('id', $id)->first(),
            'promotions' => DB::table('promotions')->where('id', $id)->first(),
            'banners' => DB::table('banners')->where('id', $id)->first(),
            default => null,
        };

        if ($row === null) {
            return null;
        }

        $values = (array) $row;

        if ($resource === 'products') {
            $listing = DB::table('store_products')->where('product_id', $id)->orderBy('store_id')->first();
            $values['store_id'] = $listing?->store_id;
            $values['price'] = $listing?->price;
            $values['listing_active'] = $listing?->is_active ?? true;
        }

        return $values;
    }

    /** @return array<string,mixed> */
    private function storeResource(Request $request, string $resource): array
    {
        return match ($resource) {
            'categories' => $this->saveCategory($request),
            'brands' => $this->saveBrand($request),
            'units' => $this->saveUnit($request),
            'products' => $this->saveProduct($request),
            'stores' => $this->saveStore($request),
            'warehouses' => $this->saveWarehouse($request),
            'customers' => $this->saveCustomer($request),
            'b2b-clients' => $this->saveB2bClient($request),
            'price-tiers' => $this->savePriceTier($request),
            'promotions' => $this->savePromotion($request),
            'banners' => $this->saveBanner($request),
            default => [],
        };
    }

    /** @return array<string,mixed> */
    private function updateResource(Request $request, string $resource, int $id): array
    {
        return match ($resource) {
            'categories' => $this->saveCategory($request, $id),
            'brands' => $this->saveBrand($request, $id),
            'units' => $this->saveUnit($request, $id),
            'products' => $this->saveProduct($request, $id),
            'stores' => $this->saveStore($request, $id),
            'warehouses' => $this->saveWarehouse($request, $id),
            'customers' => $this->saveCustomer($request, $id),
            'b2b-clients' => $this->saveB2bClient($request, $id),
            'price-tiers' => $this->savePriceTier($request, $id),
            'promotions' => $this->savePromotion($request, $id),
            'banners' => $this->saveBanner($request, $id),
            default => [],
        };
    }

    /** @return array<string,mixed> */
    private function saveCategory(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($id)],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($id !== null && (int) ($data['parent_id'] ?? 0) === $id) {
            throw ValidationException::withMessages(['parent_id' => App::isLocale('ar') ? 'لا يمكن اختيار التصنيف نفسه كتصنيف أب.' : 'A category cannot be its own parent.']);
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = Str::slug((string) $data['name']);
            if ($slug === '') {
                $slug = 'category-'.Str::lower(Str::random(10));
            }
        }

        $values = [
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('categories')->insertGetId($values);
        } else {
            DB::table('categories')->where('id', $id)->update($values);
        }

        return (array) DB::table('categories')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function saveBrand(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('brands', 'slug')->ignore($id)],
        ]);

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = Str::slug((string) $data['name']);
            if ($slug === '') {
                $slug = 'brand-'.Str::lower(Str::random(10));
            }
        }

        $values = ['name' => trim((string) $data['name']), 'slug' => $slug, 'updated_at' => now()];
        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('brands')->insertGetId($values);
        } else {
            DB::table('brands')->where('id', $id)->update($values);
        }

        return (array) DB::table('brands')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function saveUnit(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('units', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
        ]);

        $values = [
            'code' => Str::upper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'decimal_places' => (int) $data['decimal_places'],
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('units')->insertGetId($values);
        } else {
            DB::table('units')->where('id', $id)->update($values);
        }

        return (array) DB::table('units')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function saveProduct(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'is_active' => ['nullable', 'boolean'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'listing_active' => ['nullable', 'boolean'],
        ]);

        $values = [
            'sku' => Str::upper(trim((string) $data['sku'])),
            'name' => trim((string) $data['name']),
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'category_id' => $data['category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'unit_id' => (int) $data['unit_id'],
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('products')->insertGetId($values);
        } else {
            DB::table('products')->where('id', $id)->update($values);
        }

        if (! empty($data['store_id'])) {
            DB::table('store_products')->updateOrInsert(
                ['store_id' => (int) $data['store_id'], 'product_id' => $id],
                [
                    'price' => $data['price'] ?? null,
                    'is_active' => $request->boolean('listing_active'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        return (array) DB::table('products')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function saveStore(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'store_type_id' => ['required', 'integer', 'exists:store_types,id'],
            'code' => ['required', 'string', 'max:100', Rule::unique('stores', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $values = [
            'store_type_id' => (int) $data['store_type_id'],
            'code' => Str::upper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('stores')->insertGetId($values);
        } else {
            DB::table('stores')->where('id', $id)->update($values);
        }

        return (array) DB::table('stores')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function saveWarehouse(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'code' => ['required', 'string', 'max:100', Rule::unique('warehouses', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $values = [
            'store_id' => $data['store_id'] ?? null,
            'code' => Str::upper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('warehouses')->insertGetId($values);
        } else {
            DB::table('warehouses')->where('id', $id)->update($values);
        }

        return (array) DB::table('warehouses')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function saveCustomer(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['b2c', 'b2b'])],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $values = [
            'type' => $data['type'],
            'name' => trim((string) $data['name']),
            'phone' => isset($data['phone']) && trim((string) $data['phone']) !== '' ? trim((string) $data['phone']) : null,
            'email' => isset($data['email']) && trim((string) $data['email']) !== '' ? trim((string) $data['email']) : null,
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('customers')->insertGetId($values);
        } else {
            DB::table('customers')->where('id', $id)->update($values);
        }

        return (array) DB::table('customers')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function saveB2bClient(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:100'],
            'company_name' => ['required', 'string', 'max:255'],
            'price_tier_id' => ['nullable', 'integer', 'exists:b2b_price_tiers,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'tax_number' => ['nullable', 'string', 'max:150'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
        ]);

        $customerId = null;
        if ($id !== null) {
            $customerId = DB::table('b2b_accounts')->where('id', $id)->value('customer_id');
        }

        $customerValues = [
            'type' => 'b2b',
            'name' => trim((string) $data['contact_name']),
            'email' => isset($data['contact_email']) && trim((string) $data['contact_email']) !== '' ? trim((string) $data['contact_email']) : null,
            'phone' => isset($data['contact_phone']) && trim((string) $data['contact_phone']) !== '' ? trim((string) $data['contact_phone']) : null,
            'updated_at' => now(),
        ];

        if ($customerId === null) {
            $customerValues['created_at'] = now();
            $customerId = (int) DB::table('customers')->insertGetId($customerValues);
        } else {
            DB::table('customers')->where('id', $customerId)->update($customerValues);
        }

        $values = [
            'customer_id' => (int) $customerId,
            'price_tier_id' => $data['price_tier_id'] ?? null,
            'company_name' => trim((string) $data['company_name']),
            'status' => $data['status'],
            'tax_number' => isset($data['tax_number']) && trim((string) $data['tax_number']) !== '' ? trim((string) $data['tax_number']) : null,
            'credit_limit' => (float) $data['credit_limit'],
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('b2b_accounts')->insertGetId($values);
        } else {
            DB::table('b2b_accounts')->where('id', $id)->update($values);
        }

        return (array) DB::table('b2b_accounts')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function savePriceTier(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', Rule::unique('b2b_price_tiers', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $values = [
            'code' => Str::upper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'priority' => (int) $data['priority'],
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('b2b_price_tiers')->insertGetId($values);
        } else {
            DB::table('b2b_price_tiers')->where('id', $id)->update($values);
        }

        return (array) DB::table('b2b_price_tiers')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function savePromotion(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['percentage', 'fixed', 'free_delivery'])],
            'value' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $values = [
            'store_id' => $data['store_id'] ?? null,
            'name' => trim((string) $data['name']),
            'type' => $data['type'],
            'value' => $data['value'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('promotions')->insertGetId($values);
        } else {
            DB::table('promotions')->where('id', $id)->update($values);
        }

        return (array) DB::table('promotions')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function saveBanner(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'title' => ['required', 'string', 'max:255'],
            'image_path' => ['required', 'string', 'max:2048'],
            'target_url' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $values = [
            'store_id' => $data['store_id'] ?? null,
            'title' => trim((string) $data['title']),
            'image_path' => trim((string) $data['image_path']),
            'target_url' => isset($data['target_url']) && trim((string) $data['target_url']) !== '' ? trim((string) $data['target_url']) : null,
            'sort_order' => (int) $data['sort_order'],
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ];

        if ($id === null) {
            $values['created_at'] = now();
            $id = (int) DB::table('banners')->insertGetId($values);
        } else {
            DB::table('banners')->where('id', $id)->update($values);
        }

        return (array) DB::table('banners')->where('id', $id)->first();
    }

    private function destroyResource(string $resource, int $id): void
    {
        switch ($resource) {
            case 'categories':
                DB::table('categories')->where('id', $id)->delete();

                return;
            case 'brands':
                DB::table('brands')->where('id', $id)->delete();

                return;
            case 'units':
                $this->deleteIfUnused('units', $id, [['products', 'unit_id']]);

                return;
            case 'products':
                $this->deleteIfUnused('products', $id, [
                    ['order_items', 'product_id'],
                    ['cart_items', 'product_id'],
                    ['inventories', 'product_id'],
                    ['b2b_price_rules', 'product_id'],
                ]);

                return;
            case 'stores':
                $this->deleteIfUnused('stores', $id, [
                    ['orders', 'store_id'],
                    ['carts', 'store_id'],
                ]);

                return;
            case 'warehouses':
                $this->deleteIfUnused('warehouses', $id, [['inventories', 'warehouse_id']]);

                return;
            case 'customers':
                $this->deleteIfUnused('customers', $id, [
                    ['orders', 'customer_id'],
                    ['invoices', 'customer_id'],
                ]);

                return;
            case 'b2b-clients':
                DB::table('b2b_accounts')->where('id', $id)->delete();

                return;
            case 'price-tiers':
                $this->deleteIfUnused('b2b_price_tiers', $id, [
                    ['b2b_accounts', 'price_tier_id'],
                    ['b2b_price_rules', 'price_tier_id'],
                ]);

                return;
            case 'promotions':
                DB::table('promotions')->where('id', $id)->delete();

                return;
            case 'banners':
                DB::table('banners')->where('id', $id)->delete();

                return;
        }
    }

    /** @param array<int,array{0:string,1:string}> $references */
    private function deleteIfUnused(string $table, int $id, array $references): void
    {
        foreach ($references as [$referenceTable, $column]) {
            if (Schema::hasTable($referenceTable) && DB::table($referenceTable)->where($column, $id)->exists()) {
                throw ValidationException::withMessages([
                    'delete' => App::isLocale('ar')
                        ? 'لا يمكن الحذف لأن السجل مستخدم في بيانات تشغيلية. عدّل حالته أو أزل الارتباطات أولاً.'
                        : 'This record is referenced by operational data. Deactivate it or remove dependent links first.',
                ]);
            }
        }

        DB::table($table)->where('id', $id)->delete();
    }

    private function message(string $action): string
    {
        if (App::isLocale('ar')) {
            return match ($action) {
                'created' => 'تمت الإضافة بنجاح.',
                'updated' => 'تم حفظ التعديلات.',
                'deleted' => 'تم الحذف بنجاح.',
                default => 'تم حفظ العملية.',
            };
        }

        return match ($action) {
            'created' => 'Record created successfully.',
            'updated' => 'Changes saved successfully.',
            'deleted' => 'Record deleted successfully.',
            default => 'Action completed.',
        };
    }
}
