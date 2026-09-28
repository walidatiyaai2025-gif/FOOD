<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StorefrontController extends Controller
{
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

        $hero = DB::table('banners')
            ->where('store_id', $store)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

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
            'hero' => $hero === null ? null : [
                'id' => (int) $hero->id,
                'title' => (string) $hero->title,
                'image_url' => $this->assetUrl($hero->image_path),
                'target_url' => $hero->target_url,
            ],
            'sections' => $sections,
        ]);
    }

    public function b2bCheckoutOptions(
        Request $request,
        CustomerDomainResolver $customers,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $storeId = $request->integer('store_id');
        if ($storeId <= 0) {
            throw ValidationException::withMessages([
                'store_id' => ['A B2B store is required.'],
            ]);
        }

        $wholesaleExists = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->exists();
        abort_unless($wholesaleExists, 404);

        $customer = $customers->b2bFromRequest($user, $request);
        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->firstOrFail();

        $addresses = DB::table('addresses')
            ->where('b2b_customer_id', $customer->getKey())
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

        $paymentMethods = array_values((array) config('checkout.payment_methods', ['cash_on_delivery']));
        if ((float) $account->credit_limit > 0 && ! in_array('account_credit', $paymentMethods, true)) {
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
            'account_id' => (int) $account->getKey(),
            'addresses' => $addresses,
            'delivery_dates' => $deliveryDates,
            'payment_methods' => $paymentMethods,
            'credit_limit' => (float) $account->credit_limit,
            'currency' => 'EGP',
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
