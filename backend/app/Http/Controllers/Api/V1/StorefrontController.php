<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\User;
use App\Services\B2bAccountLedgerService;
use App\Services\CustomerAddressService;
use App\Services\CustomerDomainResolver;
use App\Services\RetailMerchantIdentityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class StorefrontController extends Controller
{
    public function __construct(private readonly RetailMerchantIdentityService $retailMerchants) {}

    public function marketplace(Request $request): JsonResponse
    {
        $wholesale = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->leftJoin('storefront_settings', 'storefront_settings.store_id', '=', 'stores.id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->orderBy('stores.id')
            ->first([
                'stores.id',
                'stores.code',
                'stores.name',
                'stores.logo_path',
                'storefront_settings.theme_code',
                'storefront_settings.header_address',
            ]);

        abort_if($wholesale === null, 404, 'Main Wholesale store is not configured.');

        $retailStores = $this->retailStoreQuery($request)
            ->orderByDesc('stores.created_at')
            ->orderByDesc('stores.id')
            ->get()
            ->map(function (object $store): array {
                $hero = DB::table('banners')
                    ->where('store_id', $store->id)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first(['title', 'image_path']);

                return [
                    ...$this->retailStorePayload($store),
                    'banner_title' => $hero?->title,
                    'banner_image_url' => $this->assetUrl($hero?->image_path),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'main_wholesale_store' => [
                'id' => (int) $wholesale->id,
                'code' => (string) $wholesale->code,
                'name' => (string) $wholesale->name,
                'logo_url' => $this->assetUrl($wholesale->logo_path),
                'channel' => 'b2b',
                'theme_code' => (string) ($wholesale->theme_code ?? 'wholesale_b2b'),
                'address' => $wholesale->header_address,
            ],
            'retail_stores' => $retailStores,
        ]);
    }

    public function showWholesalePublic(Request $request, int $store): JsonResponse
    {
        $storeRow = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $store)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->first([
                'stores.id',
                'stores.code',
                'stores.name',
                'stores.logo_path',
            ]);

        abort_if($storeRow === null, 404);

        $settings = DB::table('storefront_settings')->where('store_id', $store)->first();
        $banners = DB::table('banners')
            ->where('store_id', $store)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('target_type')
                    ->orWhere('target_type', '!=', 'retail_store');
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $banner): array => [
                'id' => (int) $banner->id,
                'title' => (string) $banner->title,
                'image_url' => $this->assetUrl($banner->image_path),
                'sort_order' => (int) $banner->sort_order,
            ])
            ->values()
            ->all();

        $products = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->where('catalogs.store_id', $store)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->whereNotNull('store_products.price')
            ->orderBy('products.name')
            ->limit(60)
            ->get([
                'products.id',
                'products.sku',
                'products.name',
                'products.category_id',
                'products.brand_id',
                'store_products.price',
                DB::raw('(select name from categories where categories.id = products.category_id limit 1) as category_name'),
                DB::raw('(select image_path from categories where categories.id = products.category_id limit 1) as category_image_path'),
                DB::raw('(select name from brands where brands.id = products.brand_id limit 1) as brand_name'),
                DB::raw('(select image_path from brands where brands.id = products.brand_id limit 1) as brand_image_path'),
            ])
            ->map(fn (object $product): array => [
                'id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'category_id' => $product->category_id === null ? null : (int) $product->category_id,
                'category_name' => $product->category_name,
                'category_image_url' => $this->assetUrl($product->category_image_path),
                'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
                'brand_name' => $product->brand_name,
                'brand_image_url' => $this->assetUrl($product->brand_image_path),
                'price' => (float) $product->price,
                'currency' => 'EGP',
                'image_url' => $this->assetUrl(
                    DB::table('product_images')
                        ->where('product_id', $product->id)
                        ->orderByDesc('is_primary')
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->value('path'),
                ),
            ])
            ->values()
            ->all();

        return response()->json([
            'store' => [
                'id' => (int) $storeRow->id,
                'code' => (string) $storeRow->code,
                'name' => (string) $storeRow->name,
                'logo_url' => $this->assetUrl($storeRow->logo_path),
                'channel' => 'b2b',
                'theme_code' => 'wholesale_b2b',
                'is_active' => true,
            ],
            'theme' => [
                'code' => (string) ($settings->theme_code ?? 'wholesale_b2b'),
                'primary' => $settings->primary_color ?? '#5D2A91',
                'primary_dark' => $settings->primary_dark_color ?? '#35195E',
                'accent' => $settings->accent_color ?? '#B983F0',
                'background' => $settings->background_color ?? '#FBFAFD',
            ],
            'branding' => [
                'logo_url' => $this->assetUrl($storeRow->logo_path),
                'address' => $settings->header_address ?? null,
                'custom' => $this->decodedJson($settings->branding ?? null),
            ],
            'hero' => $banners[0] ?? null,
            'banners' => $banners,
            'products' => $products,
            'guest_browsing' => true,
            'checkout_requires_authentication' => true,
        ]);
    }

    public function selector(Request $request, CustomerDomainResolver $customers): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $retailStores = $this->retailStoreQuery($request)
            ->orderBy('stores.name')
            ->get()
            ->map(fn (object $store): array => $this->retailStorePayload($store))
            ->values()
            ->all();

        $retailContexts = $customers->entitledRetailStoreIds($user);
        $hasDirectB2b = DB::table('b2b_customers')
            ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->where('b2b_customers.user_id', $user->getKey())
            ->where('b2b_accounts.status', 'active')
            ->whereNotNull('b2b_accounts.price_tier_id')
            ->exists();

        $support = $user->hasRole('SUPER_ADMIN') && $request->boolean('support');
        $wholesaleStores = [];
        if ($hasDirectB2b || $retailContexts !== [] || $support) {
            $wholesaleStores = DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.is_active', true)
                ->where('store_types.code', 'B2B')
                ->orderBy('stores.name')
                ->get([
                    'stores.id',
                    'stores.code',
                    'stores.name',
                    'stores.logo_path',
                ])
                ->map(fn (object $store): array => [
                    'id' => (int) $store->id,
                    'code' => (string) $store->code,
                    'name' => (string) $store->name,
                    'logo_url' => $this->assetUrl($store->logo_path),
                    'channel' => 'b2b',
                    'theme_code' => 'wholesale_b2b',
                    'retail_context_ids' => $retailContexts,
                    'support_access' => $support,
                ])
                ->values()
                ->all();
        }

        return response()->json([
            'retail_stores' => $retailStores,
            'wholesale_stores' => $wholesaleStores,
            'entitlements' => [
                'direct_b2b' => $hasDirectB2b,
                'retail_context_ids' => $retailContexts,
                'support_access' => $support,
            ],
        ]);
    }

    public function show(Request $request, int $store): JsonResponse
    {
        $user = $request->user('sanctum');
        if ($request->bearerToken() !== null && $user instanceof User) {
            $this->retailMerchants->assertCanPurchaseFromRetailStore($user, $store);
        }

        $storeRow = $this->retailStoreQuery($request, false)
            ->where('stores.id', $store)
            ->first();

        abort_if($storeRow === null, 404);

        $settings = DB::table('storefront_settings')
            ->where('store_id', $store)
            ->first();

        $sections = DB::table('storefront_sections')
            ->where('store_id', $store)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $section): array => [
                'key' => (string) $section->section_key,
                'type' => (string) $section->section_type,
                'title_ar' => $section->title_ar,
                'title_en' => $section->title_en,
                'sort_order' => (int) $section->sort_order,
                'config' => is_string($section->config)
                    ? (json_decode($section->config, true) ?: [])
                    : ((array) ($section->config ?? [])),
            ])
            ->values()
            ->all();

        $banners = DB::table('banners')
            ->where('store_id', $store)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $banner): array => [
                'id' => (int) $banner->id,
                'title' => (string) $banner->title,
                'image_url' => $this->bannerImageUrl($banner->image_path),
                'target_type' => $banner->target_type,
                'target_id' => $banner->target_id === null ? null : (int) $banner->target_id,
                'target_url' => $banner->target_url,
                'sort_order' => (int) $banner->sort_order,
            ])
            ->values()
            ->all();
        $hero = $banners[0] ?? null;

        return response()->json([
            'store' => $this->retailStorePayload($storeRow),
            'theme' => [
                'code' => (string) ($settings->theme_code ?? 'retail_grocery'),
                'primary' => $settings->primary_color ?? null,
                'primary_dark' => $settings->primary_dark_color ?? null,
                'accent' => $settings->accent_color ?? null,
                'background' => $settings->background_color ?? null,
            ],
            'branding' => [
                'logo_url' => $this->assetUrl($storeRow->logo_path ?? null),
                'address' => $settings->header_address ?? null,
                'custom' => $this->decodedJson($settings->branding ?? null),
            ],
            'hero' => $hero,
            'banners' => $banners,
            'sections' => $sections,
        ]);
    }

    public function showWholesale(
        Request $request,
        int $store,
        CustomerDomainResolver $customers,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $storeRow = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $store)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->first([
                'stores.id',
                'stores.code',
                'stores.name',
                'stores.logo_path',
            ]);

        abort_if($storeRow === null, 404);

        // Resolves either the direct B2B account or an entitled Retail-store
        // purchasing context. This is the same gate used by pricing/checkout.
        $customers->b2bFromRequest($user, $request);

        $settings = DB::table('storefront_settings')
            ->where('store_id', $store)
            ->first();

        $sections = DB::table('storefront_sections')
            ->where('store_id', $store)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $section): array => [
                'key' => (string) $section->section_key,
                'type' => (string) $section->section_type,
                'title_ar' => $section->title_ar,
                'title_en' => $section->title_en,
                'sort_order' => (int) $section->sort_order,
                'config' => is_string($section->config)
                    ? (json_decode($section->config, true) ?: [])
                    : ((array) ($section->config ?? [])),
            ])
            ->values()
            ->all();

        $banners = DB::table('banners')
            ->where('store_id', $store)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('target_type')
                    ->orWhere('target_type', '!=', 'retail_store');
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $banner): array => [
                'id' => (int) $banner->id,
                'title' => (string) $banner->title,
                'image_url' => $this->assetUrl($banner->image_path),
                'target_type' => $banner->target_type,
                'target_id' => $banner->target_id === null ? null : (int) $banner->target_id,
                'target_url' => $banner->target_url,
                'sort_order' => (int) $banner->sort_order,
            ])
            ->values()
            ->all();

        return response()->json([
            'store' => [
                'id' => (int) $storeRow->id,
                'code' => (string) $storeRow->code,
                'name' => (string) $storeRow->name,
                'logo_url' => $this->assetUrl($storeRow->logo_path ?? null),
                'store_type' => 'B2B',
                'channel' => 'b2b',
                'theme_code' => 'wholesale_b2b',
                'is_active' => true,
            ],
            'theme' => [
                'code' => (string) ($settings->theme_code ?? 'wholesale_b2b'),
                'primary' => $settings->primary_color ?? '#5D2A91',
                'primary_dark' => $settings->primary_dark_color ?? '#35195E',
                'accent' => $settings->accent_color ?? '#B983F0',
                'background' => $settings->background_color ?? '#FBFAFD',
            ],
            'branding' => [
                'logo_url' => $this->assetUrl($storeRow->logo_path ?? null),
                'address' => $settings->header_address ?? null,
                'custom' => $this->decodedJson($settings->branding ?? null),
            ],
            'hero' => $banners[0] ?? null,
            'banners' => $banners,
            'sections' => $sections,
        ]);
    }

    public function b2bCheckoutOptions(
        Request $request,
        CustomerDomainResolver $customers,
        CustomerAddressService $customerAddresses,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $storeId = $request->integer('store_id');
        if ($storeId <= 0) {
            throw ValidationException::withMessages([
                'store_id' => ['A B2B store is required.'],
            ]);
        }

        $wholesaleStore = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->first(['stores.id', 'stores.name']);
        abort_unless($wholesaleStore !== null, 404);

        $customer = $customers->b2bFromRequest($user, $request);
        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->firstOrFail();

        $addresses = $customerAddresses
            ->queryFor($user, $customer, 'b2b')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get([
                'id',
                'label',
                'line1',
                'line2',
                'city',
                'area',
                'country_code',
                'is_default',
            ])
            ->map(static fn (object $address): array => [
                'id' => (int) $address->id,
                'label' => $address->label,
                'line1' => (string) $address->line1,
                'line2' => $address->line2,
                'city' => (string) $address->city,
                'area' => $address->area,
                'country_code' => (string) $address->country_code,
                'is_default' => (bool) $address->is_default,
            ])
            ->values()
            ->all();

        $finance = app(B2bAccountLedgerService::class)->summary($customer, $storeId);
        $paymentMethods = array_values((array) config('checkout.payment_methods', ['cash_on_delivery']));
        if ((float) $finance['purchasing_power'] > 0 && ! in_array('account_credit', $paymentMethods, true)) {
            $paymentMethods[] = 'account_credit';
        }

        $deliveryDates = [];
        $date = Carbon::today();
        while (count($deliveryDates) < 5) {
            $date = $date->copy()->addDay();
            if ($date->isWeekend()) {
                continue;
            }
            $deliveryDates[] = $date->toDateString();
        }

        return response()->json([
            'store_id' => $storeId,
            'store_name' => (string) $wholesaleStore->name,
            'customer_name' => (string) $customer->name,
            'account_id' => (int) $account->getKey(),
            'addresses' => $addresses,
            'delivery_dates' => $deliveryDates,
            'payment_methods' => $paymentMethods,
            'balance' => (float) $finance['balance'],
            'balance_direction' => (string) $finance['balance_direction'],
            'outstanding_receivable' => (float) $finance['outstanding_receivable'],
            'customer_credit_balance' => (float) $finance['customer_credit_balance'],
            'credit_limit' => (float) $finance['credit_limit'],
            'available_credit_line' => (float) $finance['available_credit_line'],
            'purchasing_power' => (float) $finance['purchasing_power'],
            'currency' => $finance['currency'],
        ]);
    }

    private function retailStoreQuery(Request $request, bool $applyZone = true): Builder
    {
        $query = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->leftJoin('storefront_settings', 'storefront_settings.store_id', '=', 'stores.id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->select([
                'stores.id',
                'stores.code',
                'stores.name',
                'stores.logo_path',
                'storefront_settings.theme_code',
                'storefront_settings.header_address',
            ]);

        $user = $request->user('sanctum');
        if ($request->bearerToken() !== null && $user instanceof User) {
            $excludedStoreIds = $this->retailMerchants->retailStoreIds($user);
            if ($excludedStoreIds !== []) {
                $query->whereNotIn('stores.id', $excludedStoreIds);
            }
        }

        if (! $applyZone) {
            return $query;
        }

        $countryCode = strtoupper(trim((string) $request->query('country_code', '')));
        $city = trim((string) $request->query('city', ''));
        $area = trim((string) $request->query('area', ''));

        if ($countryCode === '' && $city === '' && $area === '') {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($countryCode, $city, $area): void {
            $scope->whereNotExists(function (Builder $zones): void {
                $zones->selectRaw('1')
                    ->from('store_service_zones')
                    ->whereColumn('store_service_zones.store_id', 'stores.id')
                    ->where('store_service_zones.is_active', true);
            })->orWhereExists(function (Builder $zones) use ($countryCode, $city, $area): void {
                $zones->selectRaw('1')
                    ->from('store_service_zones')
                    ->whereColumn('store_service_zones.store_id', 'stores.id')
                    ->where('store_service_zones.is_active', true)
                    ->when($countryCode !== '', fn (Builder $q) => $q->where('store_service_zones.country_code', $countryCode))
                    ->when($city !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($city): void {
                        $q->whereNull('store_service_zones.city')->orWhere('store_service_zones.city', $city);
                    }))
                    ->when($area !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($area): void {
                        $q->whereNull('store_service_zones.area')->orWhere('store_service_zones.area', $area);
                    }));
            });
        });
    }

    private function retailStorePayload(object $store): array
    {
        return [
            'id' => (int) $store->id,
            'code' => (string) $store->code,
            'name' => (string) $store->name,
            'logo_url' => $this->assetUrl($store->logo_path ?? null),
            'store_type' => 'B2C',
            'channel' => 'b2c',
            'theme_code' => (string) ($store->theme_code ?? 'retail_grocery'),
            'address' => $store->header_address ?? null,
            'is_active' => true,
        ];
    }

    private function decodedJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function bannerImageUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = ltrim(trim($path), '/');
        if (str_starts_with($value, 'storage/')) {
            $relative = substr($value, strlen('storage/'));
            if ($relative === '' || ! Storage::disk('public')->exists($relative)) {
                return null;
            }
        }

        return $this->assetUrl($path);
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
