<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bPriceRule;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerDomainResolver;
use App\Services\WholesalePrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class B2bPricingController extends Controller
{
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
            ['unit_price' => $data['unit_price'], 'minimum_quantity' => $data['minimum_quantity'], 'is_active' => $data['is_active'] ?? true],
        );
        app(AuditLogger::class)->record('b2b.price_rule.saved', $request->user(), $rule, null, $rule->toArray(), $request);

        return response()->json(['data' => $rule], $rule->wasRecentlyCreated ? 201 : 200);
    }

    public function product(Request $request, int $product): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        $customer = app(CustomerDomainResolver::class)->b2b($user);

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
        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        abort_unless($storeId === $principalStoreId, 404, 'Wholesale store is not available.');

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
                'b2b_price_rules.unit_price',
                'b2b_price_rules.minimum_quantity',
                'b2b_price_tiers.code as price_tier',
            ]);

        abort_if($row === null, 404, 'B2B product is not available for this account and store.');

        $inventoryRows = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->where('inventories.product_id', $product)
            ->get(['inventories.quantity', 'inventories.reserved_quantity']);

        $availableQuantity = $inventoryRows->isEmpty()
            ? null
            : (float) $inventoryRows->sum(
                static fn (object $inventory): float => max(
                    0.0,
                    (float) $inventory->quantity - (float) $inventory->reserved_quantity,
                ),
            );

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
            'description' => $row->description,
            'image_url' => $images[0] ?? null,
            'images' => $images,
            'store_id' => $storeId,
            'account_price' => (float) $row->unit_price,
            'minimum_order_quantity' => (float) $row->minimum_quantity,
            'price_tier' => (string) $row->price_tier,
            'available_quantity' => $availableQuantity,
            'is_available' => $availableQuantity === null || $availableQuantity > 0,
            'currency' => 'EGP',
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');
        $customer = app(CustomerDomainResolver::class)->b2b($user);
        $account = B2bAccount::query()->where('b2b_customer_id', $customer->getKey())->where('status', 'active')->first();
        abort_unless($account instanceof B2bAccount && $account->price_tier_id !== null, 403, 'Approved B2B pricing account is required.');
        $requestedStoreId = $request->integer('store_id');
        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        $storeId = $requestedStoreId > 0 ? $requestedStoreId : $principalStoreId;
        abort_unless($storeId === $principalStoreId, 404, 'Wholesale store is not available.');

        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $rowsQuery = DB::table('b2b_price_rules')
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
            ->where('store_types.code', 'B2B');

        if (isset($validated['q']) && trim((string) $validated['q']) !== '') {
            $term = '%'.trim((string) $validated['q']).'%';
            $rowsQuery->where(function ($query) use ($term): void {
                $query->where('products.name', 'like', $term)
                    ->orWhere('products.sku', 'like', $term);
            });
        }

        $rows = $rowsQuery
            ->orderBy('products.id')
            ->get([
                'products.id',
                'products.sku',
                'products.name',
                'b2b_price_rules.store_id',
                'b2b_price_rules.unit_price',
                'b2b_price_rules.minimum_quantity',
                DB::raw('(select path from product_images where product_images.product_id = products.id order by is_primary desc, sort_order asc, id asc limit 1) as primary_image_path'),
            ])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'store_id' => (int) $row->store_id,
                'unit_price' => (float) $row->unit_price,
                'minimum_quantity' => (float) $row->minimum_quantity,
                'image_url' => $this->assetUrl($row->primary_image_path),
            ]);

        return response()->json(['data' => $rows, 'currency' => 'EGP', 'store_id' => $storeId]);
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
