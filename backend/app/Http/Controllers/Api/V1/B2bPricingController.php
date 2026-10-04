<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bPriceRule;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerDomainResolver;
use App\Services\ProductAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class B2bPricingController extends Controller
{
    public function __construct(private readonly ProductAvailabilityService $availability) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('b2b.pricing.manage');

        $rules = B2bPriceRule::query()->orderBy('id')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json(['data' => $rules->items(), 'meta' => ['total' => $rules->total()]]);
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

        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->first();
        abort_unless(
            $account instanceof B2bAccount && $account->price_tier_id !== null,
            403,
            'Approved B2B pricing account is required.',
        );

        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
        ]);
        $storeId = (int) $validated['store_id'];

        $row = DB::table('b2b_price_rules')
            ->join('products', 'products.id', '=', 'b2b_price_rules.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'b2b_price_rules.store_id');
            })
            ->join('stores', 'stores.id', '=', 'b2b_price_rules.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->join('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_price_rules.price_tier_id')
            ->where('b2b_price_rules.price_tier_id', $account->price_tier_id)
            ->where('b2b_price_rules.store_id', $storeId)
            ->where('b2b_price_rules.product_id', $product)
            ->where('b2b_price_rules.is_active', true)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.is_active', true)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->first([
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
                'b2b_price_rules.unit_price',
                'b2b_price_rules.retail_reference_price',
                'b2b_price_rules.minimum_quantity',
                'b2b_price_rules.ordering_increment',
                'b2b_price_rules.pack_size',
                'b2b_price_rules.case_size',
                'b2b_price_rules.pack_label',
                'b2b_price_tiers.code as price_tier',
            ]);

        abort_if($row === null, 404, 'B2B product is not available for this account and store.');

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
            'base_wholesale_price' => $row->base_wholesale_price === null ? null : (float) $row->base_wholesale_price,
            'account_price' => (float) $row->unit_price,
            'retail_reference_price' => $row->retail_reference_price === null ? null : (float) $row->retail_reference_price,
            'minimum_order_quantity' => (float) $row->minimum_quantity,
            'ordering_increment' => (float) $row->ordering_increment,
            'pack_size' => (float) $row->pack_size,
            'case_size' => $row->case_size === null ? null : (float) $row->case_size,
            'pack_label' => $row->pack_label,
            'price_tier' => (string) $row->price_tier,
            ...$availability,
            'currency' => 'EGP',
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');
        $customer = app(CustomerDomainResolver::class)->b2bFromRequest($user, $request);
        $account = B2bAccount::query()->where('b2b_customer_id', $customer->getKey())->where('status', 'active')->first();
        abort_unless($account instanceof B2bAccount && $account->price_tier_id !== null, 403, 'Approved B2B pricing account is required.');
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $storeId = (int) $validated['store_id'];
        $search = trim((string) ($validated['q'] ?? ''));
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;

        $query = DB::table('b2b_price_rules')
            ->join('products', 'products.id', '=', 'b2b_price_rules.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')->on('store_products.store_id', '=', 'b2b_price_rules.store_id');
            })
            ->join('stores', 'stores.id', '=', 'b2b_price_rules.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('b2b_price_rules.price_tier_id', $account->price_tier_id)
            ->where('b2b_price_rules.store_id', $storeId)
            ->where('b2b_price_rules.is_active', true)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.is_active', true)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhere('products.barcode', 'like', $like);
            }))
            ->when($categoryId !== null, fn ($query) => $query->where('products.category_id', $categoryId));

        $rows = $query
            ->orderBy('products.id')
            ->get([
                'products.id',
                'products.sku',
                'products.barcode',
                'products.name',
                'products.category_id',
                'products.brand_id',
                'b2b_price_rules.store_id',
                'store_products.price as base_wholesale_price',
                'b2b_price_rules.unit_price',
                'b2b_price_rules.retail_reference_price',
                'b2b_price_rules.minimum_quantity',
                'b2b_price_rules.ordering_increment',
                'b2b_price_rules.pack_size',
                'b2b_price_rules.case_size',
                'b2b_price_rules.pack_label',
                DB::raw('(select path from product_images where product_images.product_id = products.id order by is_primary desc, sort_order asc, id asc limit 1) as primary_image_path'),
                DB::raw('(select name from categories where categories.id = products.category_id limit 1) as category_name'),
                DB::raw('(select image_path from categories where categories.id = products.category_id limit 1) as category_image_path'),
                DB::raw('(select name from brands where brands.id = products.brand_id limit 1) as brand_name'),
                DB::raw('(select image_path from brands where brands.id = products.brand_id limit 1) as brand_image_path'),
            ])
            ->map(fn ($row): array => [
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
                'base_wholesale_price' => $row->base_wholesale_price === null ? null : (float) $row->base_wholesale_price,
                'unit_price' => (float) $row->unit_price,
                'account_price' => (float) $row->unit_price,
                'retail_reference_price' => $row->retail_reference_price === null ? null : (float) $row->retail_reference_price,
                'minimum_quantity' => (float) $row->minimum_quantity,
                'minimum_order_quantity' => (float) $row->minimum_quantity,
                'ordering_increment' => (float) $row->ordering_increment,
                'pack_size' => (float) $row->pack_size,
                'case_size' => $row->case_size === null ? null : (float) $row->case_size,
                'pack_label' => $row->pack_label,
                'image_url' => $this->assetUrl($row->primary_image_path),
                ...$this->availability->forStoreProduct((int) $row->store_id, (int) $row->id),
            ]);

        return response()->json(['data' => $rows, 'currency' => 'EGP']);
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
