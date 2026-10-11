<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\B2bPriceResolver;
use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bPriceRule;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\B2bPricingMode;
use App\Services\CustomerDomainResolver;
use App\Services\ProductAvailabilityService;
use App\Services\WholesalePrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class B2bPricingController extends Controller
{
    public function __construct(
        private readonly ProductAvailabilityService $availability,
        private readonly B2bPriceResolver $prices,
        private readonly B2bPricingMode $pricingMode,
        private readonly WholesalePrincipal $principal,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('b2b.pricing.manage');

        $storeId = $this->principal->storeId();
        abort_unless(
            $this->pricingMode->tiersEnabled($storeId),
            409,
            'B2B pricing tiers are disabled.',
        );

        $rules = B2bPriceRule::query()
            ->where('store_id', $storeId)
            ->orderBy('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $rules->items(),
            'meta' => ['total' => $rules->total()],
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        Gate::authorize('b2b.pricing.manage');
        $data = $request->validate([
            'price_tier_id' => ['required', 'integer', 'exists:b2b_price_tiers,id'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'unit_price' => ['required', 'numeric', 'gte:0'],
            'minimum_quantity' => ['required', 'numeric', 'gt:0'],
            'ordering_increment' => ['nullable', 'numeric', 'gt:0'],
            'pack_size' => ['nullable', 'numeric', 'gt:0'],
            'case_size' => ['nullable', 'numeric', 'gt:0'],
            'pack_label' => ['nullable', 'string', 'max:80'],
            'retail_reference_price' => ['nullable', 'numeric', 'gte:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        abort_unless($this->pricingMode->tiersEnabled((int) $data['store_id']), 409, 'B2B pricing tiers are disabled.');

        $ownedProduct = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('products.id', $data['product_id'])
            ->where('catalogs.store_id', $data['store_id'])
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_types.code', 'B2B')
            ->exists();
        abort_unless($ownedProduct, 422, 'Product must belong to the selected B2B store catalog.');

        $rule = B2bPriceRule::query()->updateOrCreate(
            ['price_tier_id' => $data['price_tier_id'], 'store_id' => $data['store_id'], 'product_id' => $data['product_id']],
            [
                'unit_price' => $data['unit_price'],
                'minimum_quantity' => $data['minimum_quantity'],
                'ordering_increment' => $data['ordering_increment'] ?? 1,
                'pack_size' => $data['pack_size'] ?? 1,
                'case_size' => $data['case_size'] ?? null,
                'pack_label' => $data['pack_label'] ?? null,
                'retail_reference_price' => $data['retail_reference_price'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ],
        );
        app(AuditLogger::class)->record('b2b.price_rule.saved', $request->user(), $rule, null, $rule->toArray(), $request);

        return response()->json(['data' => $rule], $rule->wasRecentlyCreated ? 201 : 200);
    }

    public function product(Request $request, int $product): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        $customer = app(CustomerDomainResolver::class)->b2bFromRequest($user, $request);
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
        ]);
        $storeId = (int) $validated['store_id'];
        $tiersEnabled = $this->pricingMode->tiersEnabled($storeId);

        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->first();
        abort_unless(
            $account instanceof B2bAccount && (! $tiersEnabled || $account->price_tier_id !== null),
            403,
            'Approved B2B pricing account is required.',
        );

        $query = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('products.id', $product)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->whereNotNull('store_products.price')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B');

        if ($tiersEnabled) {
            $query
                ->join('b2b_price_rules', function ($join) use ($account, $storeId): void {
                    $join->on('b2b_price_rules.product_id', '=', 'products.id')
                        ->where('b2b_price_rules.store_id', '=', $storeId)
                        ->where('b2b_price_rules.price_tier_id', '=', (int) $account->price_tier_id)
                        ->where('b2b_price_rules.is_active', '=', true);
                })
                ->join('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_price_rules.price_tier_id');
        }

        $columns = [
            'products.id',
            'products.sku',
            'products.name',
            'products.description',
            'products.category_id',
            'products.brand_id',
            DB::raw('(select name from categories where categories.id = products.category_id limit 1) as category_name'),
            DB::raw('(select image_path from categories where categories.id = products.category_id limit 1) as category_image_path'),
            DB::raw('(select name from brands where brands.id = products.brand_id limit 1) as brand_name'),
            DB::raw('(select image_path from brands where brands.id = products.brand_id limit 1) as brand_image_path'),
            'store_products.price as base_wholesale_price',
        ];
        if ($tiersEnabled) {
            $columns[] = 'b2b_price_tiers.code as price_tier';
        }

        $row = $query->first($columns);
        abort_if($row === null, 404, 'B2B product is not available for this account and store.');

        $pricing = $this->prices->resolve($customer, $storeId, $product);
        $availability = $this->availability->forStoreProduct($storeId, $product);

        $images = DB::table('product_images')
            ->where('product_id', $product)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('path')
            ->map(fn ($path): ?string => $this->assetUrl($path))
            ->filter()
            ->values()
            ->all();

        return response()->json([
            'id' => (int) $row->id,
            'sku' => (string) $row->sku,
            'name' => (string) $row->name,
            'category_id' => $row->category_id === null ? null : (int) $row->category_id,
            'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
            'category_name' => $row->category_name,
            'category_image_url' => $this->assetUrl($row->category_image_path),
            'brand_name' => $row->brand_name,
            'brand_image_url' => $this->assetUrl($row->brand_image_path),
            'description' => $row->description,
            'image_url' => $images[0] ?? null,
            'images' => $images,
            'store_id' => $storeId,
            'base_wholesale_price' => (float) $row->base_wholesale_price,
            'account_price' => $pricing['price'],
            'unit_price' => $pricing['price'],
            'retail_reference_price' => $pricing['retail_reference_price'],
            'minimum_quantity' => $pricing['minimum_quantity'],
            'minimum_order_quantity' => $pricing['minimum_quantity'],
            'ordering_increment' => $pricing['ordering_increment'],
            'pack_size' => $pricing['pack_size'],
            'case_size' => $pricing['case_size'],
            'pack_label' => $pricing['pack_label'],
            'price_tier' => $tiersEnabled ? (string) ($row->price_tier ?? '') : null,
            'pricing_tiers_enabled' => $tiersEnabled,
            ...$availability,
            'currency' => 'EGP',
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');
        $customer = app(CustomerDomainResolver::class)->b2bFromRequest($user, $request);
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $storeId = (int) $validated['store_id'];
        $search = trim((string) ($validated['q'] ?? ''));
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $brandId = isset($validated['brand_id']) ? (int) $validated['brand_id'] : null;
        $tiersEnabled = $this->pricingMode->tiersEnabled($storeId);

        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->first();
        abort_unless(
            $account instanceof B2bAccount && (! $tiersEnabled || $account->price_tier_id !== null),
            403,
            'Approved B2B pricing account is required.',
        );

        $query = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->whereNotNull('store_products.price')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhere('products.barcode', 'like', $like);
            }))
            ->when($categoryId !== null, fn ($query) => $query->where('products.category_id', $categoryId))
            ->when($brandId !== null, fn ($query) => $query->where('products.brand_id', $brandId));

        if ($tiersEnabled) {
            $query->join('b2b_price_rules', function ($join) use ($account, $storeId): void {
                $join->on('b2b_price_rules.product_id', '=', 'products.id')
                    ->where('b2b_price_rules.store_id', '=', $storeId)
                    ->where('b2b_price_rules.price_tier_id', '=', (int) $account->price_tier_id)
                    ->where('b2b_price_rules.is_active', '=', true);
            });
        }

        $baseRows = $query
            ->orderBy('products.id')
            ->get([
                'products.id',
                'products.sku',
                'products.barcode',
                'products.name',
                'products.category_id',
                'products.brand_id',
                'catalogs.store_id',
                'store_products.price as base_wholesale_price',
                DB::raw('(select path from product_images where product_images.product_id = products.id order by is_primary desc, sort_order asc, id asc limit 1) as primary_image_path'),
                DB::raw('(select name from categories where categories.id = products.category_id limit 1) as category_name'),
                DB::raw('(select image_path from categories where categories.id = products.category_id limit 1) as category_image_path'),
                DB::raw('(select name from brands where brands.id = products.brand_id limit 1) as brand_name'),
                DB::raw('(select image_path from brands where brands.id = products.brand_id limit 1) as brand_image_path'),
            ]);

        $resolved = $this->prices->resolveMany(
            $customer,
            $baseRows->map(fn ($row): array => [
                'store_id' => (int) $row->store_id,
                'product_id' => (int) $row->id,
            ])->all(),
        );

        $rows = $baseRows
            ->map(function ($row) use ($resolved, $tiersEnabled): ?array {
                $key = ((int) $row->store_id).':'.((int) $row->id);
                $pricing = $resolved[$key] ?? null;
                if ($pricing === null) {
                    return null;
                }

                return [
                    'id' => (int) $row->id,
                    'sku' => (string) $row->sku,
                    'barcode' => $row->barcode,
                    'name' => (string) $row->name,
                    'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                    'category_name' => $row->category_name,
                    'category_image_url' => $this->assetUrl($row->category_image_path),
                    'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
                    'brand_name' => $row->brand_name,
                    'brand_image_url' => $this->assetUrl($row->brand_image_path),
                    'store_id' => (int) $row->store_id,
                    'base_wholesale_price' => (float) $row->base_wholesale_price,
                    'unit_price' => $pricing['price'],
                    'account_price' => $pricing['price'],
                    'retail_reference_price' => $pricing['retail_reference_price'],
                    'minimum_quantity' => $pricing['minimum_quantity'],
                    'minimum_order_quantity' => $pricing['minimum_quantity'],
                    'ordering_increment' => $pricing['ordering_increment'],
                    'pack_size' => $pricing['pack_size'],
                    'case_size' => $pricing['case_size'],
                    'pack_label' => $pricing['pack_label'],
                    'pricing_tiers_enabled' => $tiersEnabled,
                    'image_url' => $this->assetUrl($row->primary_image_path),
                    ...$this->availability->forStoreProduct((int) $row->store_id, (int) $row->id),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'data' => $rows,
            'currency' => 'EGP',
            'pricing_tiers_enabled' => $tiersEnabled,
        ]);
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = trim($path);
        if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) {
            return $value;
        }

        return url('/'.ltrim($value, '/'));
    }
}
