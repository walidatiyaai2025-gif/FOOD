<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CatalogProductCompletionManager
{
    private const LIFECYCLE_STATES = ['draft', 'active', 'paused', 'end_of_life'];

    private const ATTRIBUTE_TYPES = ['text', 'number', 'boolean', 'date', 'select', 'multi_select'];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $storeIds
     * @param  array{from?:string|null,to?:string|null}  $filters
     * @return array<string,mixed>
     */
    public function dashboard(array $storeIds, array $filters = []): array
    {
        $storeIds = $this->normalizeStoreIds($storeIds);
        [$from, $to] = $this->dateWindow($filters['from'] ?? null, $filters['to'] ?? null);
        $lastRefresh = now()->format('Y-m-d H:i');
        $base = $this->productScope($storeIds);

        $total = (clone $base)->count('p.id');
        $active = (clone $base)
            ->where('p.lifecycle_state', 'active')
            ->where('p.is_active', true)
            ->count('p.id');
        $inactive = max(0, $total - $active);
        $commerciallyAvailable = (clone $base)
            ->where('p.lifecycle_state', 'active')
            ->where('p.is_active', true)
            ->whereNotNull('sp.id')
            ->where('sp.is_active', true)
            ->where(function ($query): void {
                $query->whereNull('policy.status')->orWhere('policy.status', '!=', 'CLOSED');
            });
        $available = (clone $commerciallyAvailable)
            ->whereRaw('COALESCE(stock.available_quantity, 0) > 0')
            ->count('p.id');
        $outOfStock = (clone $commerciallyAvailable)
            ->whereRaw('COALESCE(stock.available_quantity, 0) <= 0')
            ->count('p.id');
        $lowStock = (clone $commerciallyAvailable)
            ->where('p.low_stock_threshold', '>', 0)
            ->whereRaw('COALESCE(stock.available_quantity, 0) > 0')
            ->whereRaw('COALESCE(stock.available_quantity, 0) <= p.low_stock_threshold')
            ->count('p.id');
        $unpriced = (clone $base)->whereNull('sp.price')->count('p.id');
        $marginExceptions = (clone $base)
            ->whereNotNull('sp.price')
            ->where(function ($query): void {
                $query->whereNull('sp.cost_price')
                    ->orWhereColumn('sp.price', '<=', 'sp.cost_price');
            })
            ->count('p.id');
        $unavailable = max(0, $total - (clone $commerciallyAvailable)->count('p.id'));
        $categoryCoverage = $total === 0
            ? 0
            : round(((clone $base)->whereNotNull('p.category_id')->count('p.id') / $total) * 100, 1);
        $brandCoverage = $total === 0
            ? 0
            : round(((clone $base)->whereNotNull('p.brand_id')->count('p.id') / $total) * 100, 1);
        $recentImports = DB::table('catalog_import_runs')
            ->whereIn('store_id', $storeIds)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        $recentLifecycle = DB::table('product_lifecycle_transitions as transitions')
            ->join('products as products_for_transition', 'products_for_transition.id', '=', 'transitions.product_id')
            ->join('catalogs as catalogs_for_transition', 'catalogs_for_transition.id', '=', 'products_for_transition.catalog_id')
            ->whereIn('catalogs_for_transition.store_id', $storeIds)
            ->where('transitions.created_at', '>=', now()->subDays(7))
            ->count();

        $kpis = [
            $this->metric('active_skus', $active, 'products', ['product_status' => 'active'], $lastRefresh),
            $this->metric('inactive_skus', $inactive, 'products', ['product_status' => 'inactive'], $lastRefresh),
            $this->metric('out_of_stock', $outOfStock, 'products', ['product_availability' => 'out_of_stock'], $lastRefresh),
            $this->metric('low_stock', $lowStock, 'products', ['product_health' => 'low_stock'], $lastRefresh),
            $this->metric('unpriced', $unpriced, 'products', ['product_health' => 'unpriced'], $lastRefresh),
            $this->metric('unavailable', $unavailable, 'products', ['product_health' => 'unavailable'], $lastRefresh),
            $this->metric('category_coverage', $categoryCoverage.'%', 'products', ['product_health' => 'missing_category'], $lastRefresh),
            $this->metric('brand_coverage', $brandCoverage.'%', 'products', ['product_health' => 'missing_brand'], $lastRefresh),
            $this->metric('margin_exceptions', $marginExceptions, 'products', ['product_health' => 'margin_exception'], $lastRefresh),
            $this->metric('recent_changes', $recentImports + $recentLifecycle, 'import', [], $lastRefresh),
        ];

        $availabilityDistribution = [
            ['label' => 'available', 'value' => $available],
            ['label' => 'out_of_stock', 'value' => $outOfStock],
            ['label' => 'unavailable', 'value' => $unavailable],
        ];

        return [
            'selected_from' => $from->toDateString(),
            'selected_to' => $to->toDateString(),
            'last_refresh' => $lastRefresh,
            'state' => $total > 0 ? 'current' : 'empty',
            'kpis' => $kpis,
            'charts' => [
                'category_mix' => $this->mixChart($storeIds, 'category'),
                'brand_mix' => $this->mixChart($storeIds, 'brand'),
                'availability' => $availabilityDistribution,
                'margin_distribution' => $this->marginDistribution($storeIds),
                'sales_by_product' => $this->salesChart($storeIds, $from, $to, 'product'),
                'sales_by_category' => $this->salesChart($storeIds, $from, $to, 'category'),
                'import_errors' => $this->importErrorTrend($storeIds, $from, $to),
            ],
            'exceptions' => [
                ['key' => 'out_of_stock', 'count' => $outOfStock, 'tab' => 'products', 'filters' => ['product_availability' => 'out_of_stock']],
                ['key' => 'low_stock', 'count' => $lowStock, 'tab' => 'products', 'filters' => ['product_health' => 'low_stock']],
                ['key' => 'unpriced', 'count' => $unpriced, 'tab' => 'products', 'filters' => ['product_health' => 'unpriced']],
                ['key' => 'margin_exceptions', 'count' => $marginExceptions, 'tab' => 'products', 'filters' => ['product_health' => 'margin_exception']],
                ['key' => 'data_quality', 'count' => (clone $base)->where(function ($q): void {
                    $q->whereNull('p.category_id')->orWhereNull('p.brand_id');
                })->count('p.id'), 'tab' => 'products', 'filters' => ['product_health' => 'data_quality']],
                ['key' => 'import_failures', 'count' => DB::table('catalog_import_runs')->whereIn('store_id', $storeIds)->whereIn('status', ['validation_failed', 'failed'])->count(), 'tab' => 'import', 'filters' => []],
            ],
        ];
    }

    /**
     * @param  list<int>  $storeIds
     */
    public function attributeDefinitions(array $storeIds): LengthAwarePaginator
    {
        $storeIds = $this->normalizeStoreIds($storeIds);

        return DB::table('product_attribute_definitions as attributes')
            ->join('catalogs', 'catalogs.id', '=', 'attributes.catalog_id')
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->leftJoin('categories', 'categories.id', '=', 'attributes.category_id')
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.is_migration_quarantine', false)
            ->orderBy('attributes.code')
            ->paginate(25, [
                'attributes.*',
                'categories.name as category_name',
                'stores.name as store_name',
                'catalogs.channel as catalog_channel',
            ], 'attribute_page')
            ->withQueryString();
    }

    /**
     * @param  list<int>  $storeIds
     * @return array<string,mixed>|null
     */
    public function product360(array $storeIds, int $productId): ?array
    {
        $storeIds = $this->normalizeStoreIds($storeIds);
        $product = $this->productScope($storeIds)
            ->leftJoin('categories', 'categories.id', '=', 'p.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'p.brand_id')
            ->leftJoin('units', 'units.id', '=', 'p.unit_id')
            ->where('p.id', $productId)
            ->first([
                'p.*',
                'c.store_id',
                'c.channel',
                'categories.name as category_name',
                'brands.name as brand_name',
                'units.name as unit_name',
                'units.code as unit_code',
                'sp.price',
                'sp.cost_price',
                'sp.is_active as store_product_active',
                'stock.available_quantity',
                'policy.status as commercial_status',
                'policy.break_pack_policy',
            ]);
        if ($product === null) {
            return null;
        }

        $definitions = DB::table('product_attribute_definitions')
            ->where('catalog_id', $product->catalog_id)
            ->where('is_active', true)
            ->where(function ($query) use ($product): void {
                $query->whereNull('category_id');
                if ($product->category_id !== null) {
                    $query->orWhere('category_id', $product->category_id);
                }
            })
            ->orderBy('code')
            ->get();

        $values = DB::table('product_attribute_values')
            ->where('product_id', $productId)
            ->whereIn('attribute_definition_id', $definitions->pluck('id'))
            ->get()
            ->keyBy('attribute_definition_id');

        $attributeRows = $definitions->map(function (object $definition) use ($values): object {
            $definition->decoded_value = null;
            $row = $values->get($definition->id);
            if ($row !== null && $row->value_json !== null) {
                $definition->decoded_value = json_decode((string) $row->value_json, true);
            }
            $definition->decoded_allowed_values = $definition->allowed_values === null
                ? []
                : (json_decode((string) $definition->allowed_values, true) ?: []);

            return $definition;
        });

        return [
            'product' => $product,
            'images' => DB::table('product_images')->where('product_id', $productId)->orderByDesc('is_primary')->orderBy('sort_order')->get(),
            'attributes' => $attributeRows,
            'selling_units' => DB::table('product_selling_units')->where('product_id', $productId)->orderBy('id')->get(),
            'availability_windows' => DB::table('product_availability_windows')->where('product_id', $productId)->orderBy('id')->get(),
            'commercial_rules' => DB::table('product_commercial_rules')->where('product_id', $productId)->orderBy('id')->limit(50)->get(),
            'lifecycle' => DB::table('product_lifecycle_transitions')->where('product_id', $productId)->orderByDesc('effective_at')->limit(30)->get(),
            'audit' => DB::table('audit_logs')
                ->where('auditable_type', Product::class)
                ->where('auditable_id', $productId)
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
            'recent_orders' => DB::table('order_items as items')
                ->join('orders', 'orders.id', '=', 'items.order_id')
                ->where('items.product_id', $productId)
                ->where('orders.store_id', $product->store_id)
                ->orderByDesc('orders.id')
                ->limit(10)
                ->get(['orders.id', 'orders.order_number', 'orders.status', 'orders.currency', 'items.quantity', 'items.line_total', 'orders.created_at']),
        ];
    }

    /**
     * @param  list<int>  $storeIds
     * @return array<string,mixed>|null
     */
    public function category360(array $storeIds, int $categoryId): ?array
    {
        $storeIds = $this->normalizeStoreIds($storeIds);
        $category = DB::table('categories as categories')
            ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->leftJoin('categories as parent', 'parent.id', '=', 'categories.parent_id')
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('categories.id', $categoryId)
            ->first([
                'categories.*',
                'catalogs.store_id',
                'catalogs.channel',
                'stores.name as store_name',
                'parent.name as parent_name',
            ]);
        if ($category === null) {
            return null;
        }

        $products = DB::table('products as products')
            ->leftJoin('store_products as assignment', function ($join): void {
                $join->on('assignment.product_id', '=', 'products.id');
            })
            ->where('products.catalog_id', $category->catalog_id)
            ->where('products.category_id', $categoryId);

        return [
            'category' => $category,
            'product_count' => (clone $products)->count('products.id'),
            'active_count' => (clone $products)->where('products.lifecycle_state', 'active')->where('products.is_active', true)->count('products.id'),
            'unpriced_count' => (clone $products)->whereNull('assignment.price')->count('products.id'),
            'subcategories' => DB::table('categories')->where('parent_id', $categoryId)->orderBy('name')->limit(50)->get(),
            'attributes' => DB::table('product_attribute_definitions')->where('catalog_id', $category->catalog_id)->where(function ($q) use ($categoryId): void {
                $q->whereNull('category_id')->orWhere('category_id', $categoryId);
            })->orderBy('code')->limit(100)->get(),
            'top_products' => DB::table('order_items as items')
                ->join('orders', 'orders.id', '=', 'items.order_id')
                ->join('products', 'products.id', '=', 'items.product_id')
                ->where('orders.store_id', $category->store_id)
                ->where('products.category_id', $categoryId)
                ->groupBy('products.id', 'products.name', 'products.sku')
                ->orderByDesc(DB::raw('SUM(items.line_total)'))
                ->limit(10)
                ->get([
                    'products.id',
                    'products.name',
                    'products.sku',
                    DB::raw('SUM(items.quantity) as quantity'),
                    DB::raw('SUM(items.line_total) as revenue'),
                ]),
            'audit' => DB::table('audit_logs')
                ->where('auditable_type', Category::class)
                ->where('auditable_id', $categoryId)
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function storeAttributeDefinition(
        User $actor,
        int $catalogId,
        array $input,
        ?Request $request = null,
    ): int {
        $data = Validator::make($input, [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('product_attribute_definitions', 'code')->where(fn ($query) => $query->where('catalog_id', $catalogId)),
            ],
            'data_type' => ['required', Rule::in(self::ATTRIBUTE_TYPES)],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'allowed_values' => ['nullable'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ])->validate();

        $this->assertAttributeCategory($catalogId, isset($data['category_id']) ? (int) $data['category_id'] : null);
        $allowed = $this->allowedValues($data['allowed_values'] ?? null);
        if (in_array($data['data_type'], ['select', 'multi_select'], true) && $allowed === []) {
            throw ValidationException::withMessages([
                'allowed_values' => [__('catalog_completion.validation.allowed_values_required')],
            ]);
        }

        $id = (int) DB::table('product_attribute_definitions')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $data['category_id'] ?? null,
            'code' => strtolower(trim((string) $data['code'])),
            'data_type' => $data['data_type'],
            'label_ar' => $data['label_ar'],
            'label_en' => $data['label_en'],
            'allowed_values' => $allowed === [] ? null : json_encode($allowed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record('catalog.attribute.created', $actor, null, null, [
            'attribute_definition_id' => $id,
            'catalog_id' => $catalogId,
            'code' => strtolower(trim((string) $data['code'])),
        ], $request);

        return $id;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function updateAttributeDefinition(
        User $actor,
        int $attributeId,
        int $catalogId,
        array $input,
        ?Request $request = null,
    ): void {
        $current = DB::table('product_attribute_definitions')
            ->where('id', $attributeId)
            ->where('catalog_id', $catalogId)
            ->first();
        abort_if($current === null, 404);

        $data = Validator::make($input, [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('product_attribute_definitions', 'code')
                    ->where(fn ($query) => $query->where('catalog_id', $catalogId))
                    ->ignore($attributeId),
            ],
            'data_type' => ['required', Rule::in(self::ATTRIBUTE_TYPES)],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'allowed_values' => ['nullable'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ])->validate();

        $this->assertAttributeCategory($catalogId, isset($data['category_id']) ? (int) $data['category_id'] : null);
        $allowed = $this->allowedValues($data['allowed_values'] ?? null);
        if (in_array($data['data_type'], ['select', 'multi_select'], true) && $allowed === []) {
            throw ValidationException::withMessages([
                'allowed_values' => [__('catalog_completion.validation.allowed_values_required')],
            ]);
        }

        DB::table('product_attribute_definitions')->where('id', $attributeId)->update([
            'category_id' => $data['category_id'] ?? null,
            'code' => strtolower(trim((string) $data['code'])),
            'data_type' => $data['data_type'],
            'label_ar' => $data['label_ar'],
            'label_en' => $data['label_en'],
            'allowed_values' => $allowed === [] ? null : json_encode($allowed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'updated_at' => now(),
        ]);

        $this->audit->record('catalog.attribute.updated', $actor, null, (array) $current, [
            'attribute_definition_id' => $attributeId,
            'catalog_id' => $catalogId,
            ...$data,
        ], $request);
    }

    /**
     * @param  array<int|string,mixed>  $input
     * @param  list<int>  $storeIds
     */
    public function saveProductAttributes(
        User $actor,
        array $storeIds,
        int $productId,
        array $input,
        ?Request $request = null,
    ): void {
        $detail = $this->product360($storeIds, $productId);
        abort_if($detail === null, 404);
        $product = $detail['product'];
        $definitions = $detail['attributes'];

        DB::transaction(function () use ($definitions, $input, $productId): void {
            foreach ($definitions as $definition) {
                $raw = $input[(string) $definition->id] ?? $input[$definition->id] ?? null;
                $value = $this->normalizeAttributeValue($definition, $raw);

                if ($value === null) {
                    DB::table('product_attribute_values')
                        ->where('product_id', $productId)
                        ->where('attribute_definition_id', $definition->id)
                        ->delete();

                    continue;
                }

                DB::table('product_attribute_values')->updateOrInsert(
                    [
                        'product_id' => $productId,
                        'attribute_definition_id' => $definition->id,
                    ],
                    [
                        'value_json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        });

        $model = Product::query()->find($productId);
        $this->audit->record('catalog.product.attributes.updated', $actor, $model, null, [
            'product_id' => $productId,
            'attribute_count' => count($input),
        ], $request);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<int>  $storeIds
     */
    public function transitionLifecycle(
        User $actor,
        array $storeIds,
        int $productId,
        array $input,
        ?Request $request = null,
    ): void {
        $detail = $this->product360($storeIds, $productId);
        abort_if($detail === null, 404);
        $product = $detail['product'];

        $data = Validator::make($input, [
            'state' => ['required', Rule::in(self::LIFECYCLE_STATES)],
            'effective_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $next = (string) $data['state'];
        $current = (string) ($product->lifecycle_state ?: ($product->is_active ? 'active' : 'paused'));
        if ($next === $current) {
            throw ValidationException::withMessages([
                'state' => [__('catalog_completion.validation.lifecycle_same_state')],
            ]);
        }

        $effectiveAt = isset($data['effective_at'])
            ? CarbonImmutable::parse((string) $data['effective_at'])
            : CarbonImmutable::now();

        DB::transaction(function () use ($actor, $productId, $current, $next, $effectiveAt, $data): void {
            DB::table('products')->where('id', $productId)->update([
                'lifecycle_state' => $next,
                'lifecycle_effective_at' => $effectiveAt,
                'end_of_life_at' => $next === 'end_of_life' ? $effectiveAt : null,
                'is_active' => $next === 'active',
                'updated_at' => now(),
            ]);

            DB::table('product_lifecycle_transitions')->insert([
                'product_id' => $productId,
                'user_id' => $actor->id,
                'from_state' => $current,
                'to_state' => $next,
                'effective_at' => $effectiveAt,
                'reason' => $data['reason'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $model = Product::query()->find($productId);
        $this->audit->record('catalog.product.lifecycle.transitioned', $actor, $model, [
            'lifecycle_state' => $current,
        ], [
            'lifecycle_state' => $next,
            'effective_at' => $effectiveAt->toIso8601String(),
            'reason' => $data['reason'] ?? null,
        ], $request);
    }

    /**
     * @param  list<int>  $storeIds
     * @return list<array<string,mixed>>
     */
    public function bulkExportRows(array $storeIds): array
    {
        $storeIds = $this->normalizeStoreIds($storeIds);
        $products = $this->productScope($storeIds)
            ->leftJoin('categories', 'categories.id', '=', 'p.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'p.brand_id')
            ->leftJoin('units', 'units.id', '=', 'p.unit_id')
            ->orderBy('c.store_id')
            ->orderBy('p.sku')
            ->limit(10000)
            ->get([
                'c.store_id',
                'c.channel',
                'p.id',
                'p.sku',
                'p.name',
                'p.description',
                'p.lifecycle_state',
                'p.lifecycle_effective_at',
                'p.end_of_life_at',
                'categories.name as category',
                'brands.name as brand',
                'units.code as unit_code',
                'sp.price',
                'sp.cost_price',
                'sp.is_active as assignment_active',
                'stock.available_quantity',
                'policy.status as commercial_status',
            ]);

        $values = DB::table('product_attribute_values as values')
            ->join('product_attribute_definitions as definitions', 'definitions.id', '=', 'values.attribute_definition_id')
            ->whereIn('values.product_id', $products->pluck('id'))
            ->get(['values.product_id', 'definitions.code', 'values.value_json'])
            ->groupBy('product_id');

        return $products->map(function (object $product) use ($values): array {
            $attributes = [];
            foreach ($values->get($product->id, collect()) as $value) {
                $attributes[$value->code] = json_decode((string) $value->value_json, true);
            }

            return [
                'store_id' => (int) $product->store_id,
                'channel' => (string) $product->channel,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'description' => (string) ($product->description ?? ''),
                'category' => (string) ($product->category ?? ''),
                'brand' => (string) ($product->brand ?? ''),
                'unit_code' => (string) ($product->unit_code ?? ''),
                'lifecycle_state' => (string) $product->lifecycle_state,
                'lifecycle_effective_at' => $product->lifecycle_effective_at,
                'end_of_life_at' => $product->end_of_life_at,
                'price' => $product->price,
                'cost_price' => $product->cost_price,
                'available_quantity' => (float) ($product->available_quantity ?? 0),
                'commercial_status' => (string) ($product->commercial_status ?? ''),
                'attributes_json' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        })->all();
    }

    /**
     * @param  array{from?:string|null,to?:string|null}  $filters
     * @return array{rows:list<array<string,mixed>>}
     */
    public function reportForStore(
        string $report,
        int $storeId,
        array $filters = [],
        int $limit = 5000,
        int $offset = 0,
    ): array {
        [$from, $to] = $this->dateWindow($filters['from'] ?? null, $filters['to'] ?? null);
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);

        $rows = match ($report) {
            'availability' => $this->availabilityReport($storeId, $limit, $offset),
            'category_brand_performance' => $this->categoryBrandPerformanceReport($storeId, $from, $to, $limit, $offset),
            'margin_exceptions' => $this->marginExceptionReport($storeId, $limit, $offset),
            'lifecycle' => $this->lifecycleReport($storeId, $limit, $offset),
            'data_quality' => $this->dataQualityReport($storeId, $limit, $offset),
            default => throw ValidationException::withMessages(['report' => [__('catalog_completion.validation.unsupported_report')]]),
        };

        return ['rows' => $rows];
    }

    /** @param list<int> $storeIds */
    private function productScope(array $storeIds)
    {
        $stock = DB::table('inventories as inventory')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->where('warehouse.is_active', true)
            ->groupBy('warehouse.store_id', 'inventory.product_id')
            ->selectRaw('warehouse.store_id, inventory.product_id, SUM(inventory.quantity - inventory.reserved_quantity) as available_quantity');

        return DB::table('products as p')
            ->join('catalogs as c', 'c.id', '=', 'p.catalog_id')
            ->leftJoin('store_products as sp', function ($join): void {
                $join->on('sp.product_id', '=', 'p.id')->on('sp.store_id', '=', 'c.store_id');
            })
            ->leftJoinSub($stock, 'stock', function ($join): void {
                $join->on('stock.product_id', '=', 'p.id')->on('stock.store_id', '=', 'c.store_id');
            })
            ->leftJoin('product_commercial_policies as policy', 'policy.product_id', '=', 'p.id')
            ->whereIn('c.store_id', $storeIds)
            ->where('c.is_migration_quarantine', false);
    }

    /** @param list<int> $storeIds @return list<array{label:string,value:float|int}> */
    private function mixChart(array $storeIds, string $dimension): array
    {
        $query = DB::table('products as products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.is_migration_quarantine', false);

        if ($dimension === 'category') {
            $rows = $query
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->groupBy('categories.id', 'categories.name')
                ->orderByDesc(DB::raw('COUNT(products.id)'))
                ->limit(10)
                ->get([DB::raw("COALESCE(categories.name, '') as label"), DB::raw('COUNT(products.id) as value')]);
        } else {
            $rows = $query
                ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                ->groupBy('brands.id', 'brands.name')
                ->orderByDesc(DB::raw('COUNT(products.id)'))
                ->limit(10)
                ->get([DB::raw("COALESCE(brands.name, '') as label"), DB::raw('COUNT(products.id) as value')]);
        }

        return $rows->map(static fn (object $row): array => [
            'label' => (string) $row->label,
            'value' => (int) $row->value,
        ])->all();
    }

    /** @param list<int> $storeIds @return list<array{label:string,value:int}> */
    private function marginDistribution(array $storeIds): array
    {
        $buckets = [
            'unpriced' => 0,
            'non_positive' => 0,
            'under_20' => 0,
            '20_40' => 0,
            'over_40' => 0,
        ];

        foreach ($this->productScope($storeIds)->limit(5000)->get(['sp.price', 'sp.cost_price']) as $row) {
            if ($row->price === null) {
                $buckets['unpriced']++;

                continue;
            }
            if ($row->cost_price === null || (float) $row->price <= 0) {
                $buckets['non_positive']++;

                continue;
            }
            $margin = (((float) $row->price - (float) $row->cost_price) / (float) $row->price) * 100;
            if ($margin <= 0) {
                $buckets['non_positive']++;
            } elseif ($margin < 20) {
                $buckets['under_20']++;
            } elseif ($margin < 40) {
                $buckets['20_40']++;
            } else {
                $buckets['over_40']++;
            }
        }

        return collect($buckets)->map(static fn (int $value, string $label): array => compact('label', 'value'))->values()->all();
    }

    /** @param list<int> $storeIds @return list<array{label:string,value:float}> */
    private function salesChart(array $storeIds, CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        $query = DB::table('order_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->join('products', 'products.id', '=', 'items.product_id')
            ->whereIn('orders.store_id', $storeIds)
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotIn('orders.status', ['draft', 'cancelled']);

        if ($dimension === 'category') {
            $rows = $query
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->groupBy('categories.id', 'categories.name')
                ->orderByDesc(DB::raw('SUM(items.line_total)'))
                ->limit(10)
                ->get([DB::raw("COALESCE(categories.name, '') as label"), DB::raw('SUM(items.line_total) as value')]);
        } else {
            $rows = $query
                ->groupBy('products.id', 'products.name')
                ->orderByDesc(DB::raw('SUM(items.line_total)'))
                ->limit(10)
                ->get(['products.name as label', DB::raw('SUM(items.line_total) as value')]);
        }

        return $rows->map(static fn (object $row): array => [
            'label' => (string) $row->label,
            'value' => round((float) $row->value, 3),
        ])->all();
    }

    /** @param list<int> $storeIds @return list<array{label:string,value:int}> */
    private function importErrorTrend(array $storeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('catalog_import_runs')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('created_at')
            ->get(['created_at', 'errors_json', 'status']);

        return $rows->groupBy(static fn (object $row): string => CarbonImmutable::parse($row->created_at)->toDateString())
            ->map(static function (Collection $dayRows, string $date): array {
                $errors = 0;
                foreach ($dayRows as $row) {
                    $decoded = $row->errors_json === null ? [] : json_decode((string) $row->errors_json, true);
                    $errors += is_array($decoded) ? count($decoded) : 0;
                    if ($decoded === [] && in_array($row->status, ['validation_failed', 'failed'], true)) {
                        $errors++;
                    }
                }

                return ['label' => $date, 'value' => $errors];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function availabilityReport(int $storeId, int $limit, int $offset): array
    {
        return $this->productScope([$storeId])
            ->leftJoin('categories', 'categories.id', '=', 'p.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'p.brand_id')
            ->orderBy('p.sku')
            ->offset($offset)
            ->limit($limit)
            ->get([
                'p.id as product_id',
                'p.sku',
                'p.name',
                'p.lifecycle_state',
                'categories.name as category',
                'brands.name as brand',
                'sp.price',
                'stock.available_quantity',
                'sp.is_active as assignment_active',
            ])
            ->map(static function (object $row): array {
                $available = (float) ($row->available_quantity ?? 0);
                $state = $row->lifecycle_state !== 'active' || ! $row->assignment_active
                    ? 'unavailable'
                    : ($available > 0 ? 'available' : 'out_of_stock');

                return [
                    'product_id' => (int) $row->product_id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                    'category' => (string) ($row->category ?? ''),
                    'brand' => (string) ($row->brand ?? ''),
                    'lifecycle_state' => (string) $row->lifecycle_state,
                    'availability_state' => $state,
                    'price' => $row->price === null ? null : (float) $row->price,
                    'available_quantity' => $available,
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function categoryBrandPerformanceReport(
        int $storeId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $limit,
        int $offset,
    ): array {
        return DB::table('order_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->join('products', 'products.id', '=', 'items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('store_products as assignment', function ($join) use ($storeId): void {
                $join->on('assignment.product_id', '=', 'products.id')->where('assignment.store_id', '=', $storeId);
            })
            ->where('orders.store_id', $storeId)
            ->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotIn('orders.status', ['draft', 'cancelled'])
            ->groupBy('categories.id', 'categories.name', 'brands.id', 'brands.name')
            ->orderByDesc(DB::raw('SUM(items.line_total)'))
            ->offset($offset)
            ->limit($limit)
            ->get([
                'categories.id as category_id',
                DB::raw("COALESCE(categories.name, '') as category"),
                'brands.id as brand_id',
                DB::raw("COALESCE(brands.name, '') as brand"),
                DB::raw('SUM(items.quantity) as quantity'),
                DB::raw('SUM(items.line_total) as revenue'),
                DB::raw('SUM(CASE WHEN assignment.cost_price IS NULL THEN 0 ELSE (items.unit_price - assignment.cost_price) * items.quantity END) as margin'),
            ])
            ->map(static fn (object $row): array => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category' => (string) $row->category,
                'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
                'brand' => (string) $row->brand,
                'quantity' => (float) $row->quantity,
                'revenue' => (float) $row->revenue,
                'margin' => (float) $row->margin,
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function marginExceptionReport(int $storeId, int $limit, int $offset): array
    {
        return $this->productScope([$storeId])
            ->where(function ($query): void {
                $query->whereNull('sp.price')
                    ->orWhereNull('sp.cost_price')
                    ->orWhereColumn('sp.price', '<=', 'sp.cost_price');
            })
            ->orderBy('p.sku')
            ->offset($offset)
            ->limit($limit)
            ->get(['p.id as product_id', 'p.sku', 'p.name', 'sp.price', 'sp.cost_price'])
            ->map(static function (object $row): array {
                $issue = $row->price === null
                    ? 'unpriced'
                    : ($row->cost_price === null ? 'missing_cost' : 'non_positive_margin');

                return [
                    'product_id' => (int) $row->product_id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                    'issue' => $issue,
                    'price' => $row->price === null ? null : (float) $row->price,
                    'cost_price' => $row->cost_price === null ? null : (float) $row->cost_price,
                    'margin_value' => ($row->price === null || $row->cost_price === null)
                        ? null
                        : (float) $row->price - (float) $row->cost_price,
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function lifecycleReport(int $storeId, int $limit, int $offset): array
    {
        return $this->productScope([$storeId])
            ->orderBy('p.lifecycle_state')
            ->orderBy('p.sku')
            ->offset($offset)
            ->limit($limit)
            ->get(['p.id as product_id', 'p.sku', 'p.name', 'p.lifecycle_state', 'p.lifecycle_effective_at', 'p.end_of_life_at'])
            ->map(static fn (object $row): array => [
                'product_id' => (int) $row->product_id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'lifecycle_state' => (string) $row->lifecycle_state,
                'lifecycle_effective_at' => $row->lifecycle_effective_at,
                'end_of_life_at' => $row->end_of_life_at,
                'is_end_of_life' => $row->lifecycle_state === 'end_of_life' ? 1 : 0,
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function dataQualityReport(int $storeId, int $limit, int $offset): array
    {
        $imageCounts = DB::table('product_images')->groupBy('product_id')->selectRaw('product_id, COUNT(*) as image_count');

        return $this->productScope([$storeId])
            ->leftJoinSub($imageCounts, 'images', 'images.product_id', '=', 'p.id')
            ->where(function ($query): void {
                $query->whereNull('p.category_id')
                    ->orWhereNull('p.brand_id')
                    ->orWhereNull('sp.price')
                    ->orWhereRaw('COALESCE(images.image_count, 0) = 0');
            })
            ->orderBy('p.sku')
            ->offset($offset)
            ->limit($limit)
            ->get(['p.id as product_id', 'p.sku', 'p.name', 'p.category_id', 'p.brand_id', 'sp.price', DB::raw('COALESCE(images.image_count, 0) as image_count')])
            ->map(static function (object $row): array {
                $issues = [];
                if ($row->category_id === null) {
                    $issues[] = 'missing_category';
                }
                if ($row->brand_id === null) {
                    $issues[] = 'missing_brand';
                }
                if ($row->price === null) {
                    $issues[] = 'unpriced';
                }
                if ((int) $row->image_count === 0) {
                    $issues[] = 'missing_image';
                }

                return [
                    'product_id' => (int) $row->product_id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                    'issues' => implode(',', $issues),
                    'exception_count' => count($issues),
                ];
            })->all();
    }

    /** @return array{CarbonImmutable,CarbonImmutable} */
    private function dateWindow(?string $from, ?string $to): array
    {
        try {
            $end = $to ? CarbonImmutable::parse($to)->startOfDay() : CarbonImmutable::today();
            $start = $from ? CarbonImmutable::parse($from)->startOfDay() : $end->subDays(29);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => [__('catalog_completion.validation.invalid_date')]]);
        }

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages(['date' => [__('catalog_completion.validation.invalid_date_range')]]);
        }
        if ($start->diffInDays($end) > 366) {
            $start = $end->subDays(366);
        }

        return [$start, $end];
    }

    /** @param list<int> $storeIds @return list<int> */
    private function normalizeStoreIds(array $storeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $storeIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw ValidationException::withMessages(['store_id' => [__('catalog_completion.validation.store_scope_required')]]);
        }

        return $ids;
    }

    /** @return array<string,mixed> */
    private function metric(string $key, int|float|string $value, string $tab, array $filters, string $lastRefresh): array
    {
        return compact('key', 'value', 'tab', 'filters') + ['last_refresh' => $lastRefresh];
    }

    private function assertAttributeCategory(int $catalogId, ?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        if (! DB::table('categories')->where('id', $categoryId)->where('catalog_id', $catalogId)->exists()) {
            throw ValidationException::withMessages([
                'category_id' => [__('catalog_completion.validation.category_scope')],
            ]);
        }
    }

    /** @return list<string> */
    private function allowedValues(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value)
            ? $value
            : preg_split('/[\r\n,]+/', (string) $value);

        return collect($values ?: [])
            ->map(static fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->take(100)
            ->values()
            ->all();
    }

    private function normalizeAttributeValue(object $definition, mixed $raw): mixed
    {
        $required = (bool) $definition->is_required;
        $type = (string) $definition->data_type;
        $allowed = $definition->allowed_values === null
            ? []
            : (json_decode((string) $definition->allowed_values, true) ?: []);

        $empty = $raw === null || $raw === '' || (is_array($raw) && $raw === []);
        if ($empty) {
            if ($required) {
                throw ValidationException::withMessages([
                    'attributes.'.$definition->id => [__('catalog_completion.validation.required_attribute', [
                        'attribute' => app()->getLocale() === 'ar' ? $definition->label_ar : $definition->label_en,
                    ])],
                ]);
            }

            return null;
        }

        return match ($type) {
            'text' => mb_substr(trim((string) $raw), 0, 2000),
            'number' => is_numeric($raw)
                ? (float) $raw
                : throw ValidationException::withMessages(['attributes.'.$definition->id => [__('catalog_completion.validation.number_attribute')]]),
            'boolean' => $this->booleanValue($raw, (int) $definition->id),
            'date' => $this->dateValue($raw, (int) $definition->id),
            'select' => $this->selectValue($raw, $allowed, (int) $definition->id),
            'multi_select' => $this->multiSelectValue($raw, $allowed, (int) $definition->id),
            default => throw ValidationException::withMessages(['attributes.'.$definition->id => [__('catalog_completion.validation.unsupported_attribute_type')]]),
        };
    }

    private function booleanValue(mixed $raw, int $definitionId): bool
    {
        if (in_array($raw, [true, 1, '1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($raw, [false, 0, '0', 'false', 'no'], true)) {
            return false;
        }

        throw ValidationException::withMessages([
            'attributes.'.$definitionId => [__('catalog_completion.validation.boolean_attribute')],
        ]);
    }

    private function dateValue(mixed $raw, int $definitionId): string
    {
        try {
            return CarbonImmutable::parse((string) $raw)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'attributes.'.$definitionId => [__('catalog_completion.validation.date_attribute')],
            ]);
        }
    }

    /** @param list<string> $allowed */
    private function selectValue(mixed $raw, array $allowed, int $definitionId): string
    {
        $value = trim((string) $raw);
        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages([
                'attributes.'.$definitionId => [__('catalog_completion.validation.allowed_attribute')],
            ]);
        }

        return $value;
    }

    /** @param list<string> $allowed @return list<string> */
    private function multiSelectValue(mixed $raw, array $allowed, int $definitionId): array
    {
        $values = is_array($raw) ? array_values(array_unique(array_map('strval', $raw))) : [(string) $raw];
        foreach ($values as $value) {
            if (! in_array($value, $allowed, true)) {
                throw ValidationException::withMessages([
                    'attributes.'.$definitionId => [__('catalog_completion.validation.allowed_attribute')],
                ]);
            }
        }

        return $values;
    }
}
