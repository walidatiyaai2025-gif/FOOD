<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommercialFeatureFlags;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommercialDashboardController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly AuditLogger $audit,
        private readonly CommercialFeatureFlags $flags,
    ) {}

    public function salesControl(Request $request): View
    {
        [$user, $storeId] = $this->authorizedStore($request, 'catalog.view', true);

        $products = DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->where('store_products.store_id', $storeId)
            ->orderBy('products.name')
            ->limit(200)
            ->get(['products.id', 'products.sku', 'products.name', 'store_products.is_active']);

        $legacyCustomerIds = DB::table('b2c_customers')
            ->where('store_id', $storeId)
            ->whereNotNull('legacy_customer_id')
            ->pluck('legacy_customer_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $ruleCustomers = DB::table('customers')
            ->whereIn('id', $legacyCustomerIds)
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'email']);

        $ruleGroups = DB::table('commercial_customer_groups')
            ->where('is_active', true)
            ->where(function ($query) use ($storeId): void {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->render($request, $user, $storeId, 'sales-control', [
            'products' => $products,
            'policies' => DB::table('product_commercial_policies')->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id'),
            'sellingUnits' => DB::table('product_selling_units')->whereIn('product_id', $products->pluck('id'))->orderBy('id')->get()->groupBy('product_id'),
            'availabilityWindows' => DB::table('product_availability_windows')->whereIn('product_id', $products->pluck('id'))->orderBy('id')->get()->groupBy('product_id'),
            'commercialRules' => DB::table('product_commercial_rules')->whereIn('product_id', $products->pluck('id'))->orderBy('id')->get()->groupBy('product_id'),
            'ruleCustomers' => $ruleCustomers,
            'ruleGroups' => $ruleGroups,
            'featureFlags' => $this->flags->snapshot(),
            'canManageFeatureFlags' => $this->canManageFeatureFlags($user),
            'contractReady' => $this->hasApiContract('commercial'),
        ]);
    }

    public function saveSalesControl(Request $request, int $product): RedirectResponse
    {
        [$user, $storeId] = $this->authorizedStore($request, 'catalog.manage');
        abort_unless(DB::table('store_products')->where('store_id', $storeId)->where('product_id', $product)->exists(), 404);

        $data = $request->validate([
            'status' => ['required', 'in:OPEN,RESTRICTED,CLOSED'],
            'hide_when_closed' => ['sometimes', 'boolean'],
            'override_allowed' => ['sometimes', 'boolean'],
            'channels_json' => ['required', 'json'],
            'break_pack_policy' => ['required', 'in:mixed,full-pack-only,loose-only,one-unit-type'],
            'break_pack_unit_code' => ['nullable', 'string', 'max:80'],
            'business_timezone' => ['required', 'timezone'],
            'week_starts_on' => ['required', 'integer', 'between:0,6'],
            'default_max_per_order' => ['nullable', 'numeric', 'min:0'],
            'default_max_per_day' => ['nullable', 'numeric', 'min:0'],
            'default_max_per_week' => ['nullable', 'numeric', 'min:0'],
            'default_max_per_month' => ['nullable', 'numeric', 'min:0'],
            'default_max_lifetime' => ['nullable', 'numeric', 'min:0'],
            'selling_units_json' => ['required', 'json'],
            'availability_windows_json' => ['required', 'json'],
            'rules_json' => ['required', 'json'],
        ]);

        $channels = $this->jsonArray($data['channels_json'], 'channels_json');
        $sellingUnits = $this->jsonArray($data['selling_units_json'], 'selling_units_json');
        $windows = $this->jsonArray($data['availability_windows_json'], 'availability_windows_json');
        $rules = $this->jsonArray($data['rules_json'], 'rules_json');
        $this->validateStructuredSalesConfiguration($storeId, $sellingUnits, $windows, $rules);

        if ($data['break_pack_policy'] === 'one-unit-type') {
            $breakPackCode = trim((string) ($data['break_pack_unit_code'] ?? ''));
            $validSellingUnit = $breakPackCode !== ''
                && DB::table('product_selling_units')
                    ->where('product_id', $product)
                    ->where('code', $breakPackCode)
                    ->where('is_active', true)
                    ->exists();

            if (! $validSellingUnit) {
                throw ValidationException::withMessages([
                    'break_pack_unit_code' => [__('commercial.validation.active_selling_unit')],
                ]);
            }

            $data['break_pack_unit_code'] = $breakPackCode;
        } else {
            $data['break_pack_unit_code'] = null;
        }

        DB::transaction(function () use ($product, $data, $channels, $sellingUnits, $windows, $rules, $user, $storeId, $request): void {
            $before = (array) (DB::table('product_commercial_policies')->where('product_id', $product)->first() ?? []);
            DB::table('product_commercial_policies')->updateOrInsert(
                ['product_id' => $product],
                [
                    'status' => $data['status'],
                    'hide_when_closed' => $request->boolean('hide_when_closed'),
                    'override_allowed' => $request->boolean('override_allowed'),
                    'channels' => json_encode($channels, JSON_THROW_ON_ERROR),
                    'break_pack_policy' => $data['break_pack_policy'],
                    'break_pack_unit_code' => $data['break_pack_unit_code'] ?? null,
                    'default_max_per_order' => $data['default_max_per_order'] ?? null,
                    'default_max_per_day' => $data['default_max_per_day'] ?? null,
                    'default_max_per_week' => $data['default_max_per_week'] ?? null,
                    'default_max_per_month' => $data['default_max_per_month'] ?? null,
                    'default_max_lifetime' => $data['default_max_lifetime'] ?? null,
                    'business_timezone' => $data['business_timezone'],
                    'week_starts_on' => $data['week_starts_on'],
                    'updated_at' => now(),
                    'created_at' => $before === [] ? now() : ($before['created_at'] ?? now()),
                ],
            );

            DB::table('product_selling_units')->where('product_id', $product)->delete();
            foreach ($sellingUnits as $unit) {
                if (! is_array($unit) || empty($unit['code']) || empty($unit['name'])) {
                    throw ValidationException::withMessages(['selling_units_json' => [__('commercial.validation.selling_unit_code_name')]]);
                }
                DB::table('product_selling_units')->insert([
                    'product_id' => $product,
                    'unit_id' => isset($unit['unit_id']) ? (int) $unit['unit_id'] : null,
                    'code' => (string) $unit['code'],
                    'name' => (string) $unit['name'],
                    'conversion_factor' => (float) ($unit['conversion_factor'] ?? 1),
                    'price' => $unit['price'] ?? null,
                    'sku' => $unit['sku'] ?? null,
                    'barcode' => $unit['barcode'] ?? null,
                    'is_base' => (bool) ($unit['is_base'] ?? false),
                    'is_active' => (bool) ($unit['is_active'] ?? true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('product_availability_windows')->where('product_id', $product)->delete();
            foreach ($windows as $window) {
                if (! is_array($window)) {
                    continue;
                }
                DB::table('product_availability_windows')->insert([
                    'product_id' => $product,
                    'recurrence' => (string) ($window['recurrence'] ?? 'fixed'),
                    'starts_at' => $window['starts_at'] ?? null,
                    'ends_at' => $window['ends_at'] ?? null,
                    'start_month' => $window['start_month'] ?? null,
                    'start_day' => $window['start_day'] ?? null,
                    'end_month' => $window['end_month'] ?? null,
                    'end_day' => $window['end_day'] ?? null,
                    'is_active' => (bool) ($window['is_active'] ?? true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('product_commercial_rules')->where('product_id', $product)->delete();
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                DB::table('product_commercial_rules')->insert([
                    'product_id' => $product,
                    'customer_id' => $rule['customer_id'] ?? null,
                    'customer_group_id' => $rule['customer_group_id'] ?? null,
                    'channel' => $rule['channel'] ?? null,
                    'is_allowed' => array_key_exists('is_allowed', $rule) ? (bool) $rule['is_allowed'] : null,
                    'max_per_order' => $rule['max_per_order'] ?? null,
                    'max_per_day' => $rule['max_per_day'] ?? null,
                    'max_per_week' => $rule['max_per_week'] ?? null,
                    'max_per_month' => $rule['max_per_month'] ?? null,
                    'max_lifetime' => $rule['max_lifetime'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $after = (array) DB::table('product_commercial_policies')->where('product_id', $product)->first();
            $this->audit->record('commercial.product_policy.updated', $user, null, $before, [...$after, 'store_id' => $storeId], $request);
        });

        return back()->with('status', __('commercial.status.sales_control_saved'));
    }

    public function flashOffers(Request $request): View
    {
        [$user, $storeId] = $this->authorizedStore($request, 'promotions.view', true);
        $lookups = $this->flashOfferLookups($storeId);

        $editingOffer = null;
        $editingProducts = collect();
        $editId = max(0, (int) $request->query('edit', 0));
        if ($editId > 0) {
            $editingOffer = DB::table('flash_offers')
                ->where('store_id', $storeId)
                ->where('id', $editId)
                ->first();
            abort_if($editingOffer === null, 404);

            $editingProducts = DB::table('flash_offer_products')
                ->where('flash_offer_id', $editId)
                ->orderBy('id')
                ->get([
                    'product_id',
                    'selling_unit_code',
                    'flash_price',
                    'allocation_base',
                ]);
        }

        return $this->render($request, $user, $storeId, 'flash-offers', [
            'existingPromotions' => DB::table('promotions')->where('store_id', $storeId)->orderByDesc('created_at')->limit(100)->get(),
            'flashOffers' => DB::table('flash_offers')->where('store_id', $storeId)->orderByDesc('id')->limit(100)->get(),
            ...$lookups,
            'editingOffer' => $editingOffer,
            'editingProducts' => $editingProducts,
            'featureFlags' => $this->flags->snapshot(),
            'canManageFeatureFlags' => $this->canManageFeatureFlags($user),
            'contractReady' => $this->hasApiContract('flash'),
        ]);
    }

    public function flashPreview(Request $request, int $offer): View
    {
        [$user, $storeId] = $this->authorizedStore($request, 'promotions.view');
        $row = $this->flashOfferRow($storeId, $offer);
        $products = DB::table('flash_offer_products')
            ->leftJoin('products', 'products.id', '=', 'flash_offer_products.product_id')
            ->leftJoin('product_selling_units as preview_units', function ($join): void {
                $join->on('preview_units.product_id', '=', 'flash_offer_products.product_id')
                    ->on('preview_units.code', '=', 'flash_offer_products.selling_unit_code');
            })
            ->where('flash_offer_products.flash_offer_id', $offer)
            ->orderBy('flash_offer_products.id')
            ->get([
                'flash_offer_products.id',
                'flash_offer_products.product_id',
                'products.name as product_name',
                'products.sku as product_sku',
                'flash_offer_products.selling_unit_code',
                'preview_units.name as selling_unit_name',
                'flash_offer_products.conversion_factor',
                'flash_offer_products.flash_price',
                'flash_offer_products.allocation_base',
            ]);

        return view('admin.flash-offer-preview', [
            'user' => $user,
            'storeId' => $storeId,
            'offer' => $row,
            'products' => $products,
            'channels' => $this->storedJsonList($row->channels),
            'customerPopupEnabled' => $this->flags->customerFlashPopupEnabled(),
            'supportAccess' => $request->boolean('support_access'),
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => 'b2c_promotions',
        ]);
    }

    public function flashAnalytics(Request $request, int $offer): View
    {
        [$user, $storeId] = $this->authorizedStore($request, 'promotions.view');
        $row = $this->flashOfferRow($storeId, $offer);

        $reservationStats = DB::table('flash_reservations')
            ->where('flash_offer_id', $offer)
            ->selectRaw('status, COUNT(*) as reservations_count, COALESCE(SUM(reserved_base_quantity), 0) as base_quantity')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $eventStats = DB::table('flash_offer_events')
            ->where('flash_offer_id', $offer)
            ->selectRaw('event, COUNT(*) as events_count')
            ->groupBy('event')
            ->orderByDesc('events_count')
            ->orderBy('event')
            ->get();

        $channelStats = DB::table('flash_offer_events')
            ->where('flash_offer_id', $offer)
            ->whereNotNull('channel')
            ->selectRaw('channel, COUNT(*) as events_count')
            ->groupBy('channel')
            ->orderByDesc('events_count')
            ->orderBy('channel')
            ->get();

        $liveReservedBase = (float) $reservationStats
            ->whereIn('status', ['active', 'confirmed'])
            ->sum('base_quantity');
        $confirmedBase = (float) $reservationStats
            ->where('status', 'confirmed')
            ->sum('base_quantity');

        return view('admin.flash-offer-analytics', [
            'user' => $user,
            'storeId' => $storeId,
            'offer' => $row,
            'reservationStats' => $reservationStats,
            'eventStats' => $eventStats,
            'channelStats' => $channelStats,
            'reservationCount' => (int) $reservationStats->sum('reservations_count'),
            'eventCount' => (int) $eventStats->sum('events_count'),
            'liveReservedBase' => $liveReservedBase,
            'confirmedBase' => $confirmedBase,
            'supportAccess' => $request->boolean('support_access'),
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => 'b2c_promotions',
        ]);
    }

    public function saveFlashOffer(Request $request): RedirectResponse
    {
        [$user, $storeId] = $this->authorizedStore($request, 'promotions.manage');
        $data = $request->validate([
            'offer_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'body_ar' => ['nullable', 'string'],
            'body_en' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,scheduled,active,paused,sold_out,expired,cancelled,completed'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['required', 'timezone'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', 'string', 'in:customer,van'],
            'audience_customer_ids' => ['nullable', 'array'],
            'audience_customer_ids.*' => ['integer', 'min:1'],
            'audience_customer_group_ids' => ['nullable', 'array'],
            'audience_customer_group_ids.*' => ['integer', 'min:1'],
            'audience_regions' => ['nullable', 'array'],
            'audience_regions.*' => ['string', 'max:255'],
            'audience_routes' => ['nullable', 'array'],
            'audience_routes.*' => ['string', 'max:255'],
            'allocation_mode' => ['required', 'in:shared,reserved'],
            'total_allocation_base' => ['nullable', 'numeric', 'min:0'],
            'per_customer_limit_base' => ['nullable', 'numeric', 'min:0'],
            'reservation_seconds' => ['required', 'integer', 'min:30'],
            'retry_count' => ['required', 'integer', 'min:0'],
            'cooldown_seconds' => ['required', 'integer', 'min:0'],
            'priority' => ['required', 'integer'],
            'popup_frequency' => ['required', 'string', 'max:32'],
            'counts_toward_normal_quota' => ['sometimes', 'boolean'],
            'stackable' => ['sometimes', 'boolean'],
            'kill_switch' => ['sometimes', 'boolean'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer', 'min:1'],
            'products.*.selling_unit_code' => ['required', 'string', 'max:80'],
            'products.*.flash_price' => ['required', 'numeric', 'min:0'],
            'products.*.allocation_base' => ['nullable', 'numeric', 'min:0'],
        ]);

        $channels = collect($data['channels'])->map(static fn ($item): string => (string) $item)->unique()->values()->all();
        $audienceCustomerIds = collect($data['audience_customer_ids'] ?? [])->map(static fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        $audienceCustomerGroupIds = collect($data['audience_customer_group_ids'] ?? [])->map(static fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        $audienceRegions = collect($data['audience_regions'] ?? [])->map(static fn ($item): string => trim((string) $item))->filter()->unique()->values()->all();
        $audienceRoutes = collect($data['audience_routes'] ?? [])->map(static fn ($item): string => trim((string) $item))->filter()->unique()->values()->all();

        $lookups = $this->flashOfferLookups($storeId);
        $this->validateFlashAudience(
            $audienceCustomerIds,
            $audienceCustomerGroupIds,
            $audienceRegions,
            $audienceRoutes,
            $lookups,
        );
        $allowedProducts = $lookups['flashProducts']->keyBy('id');
        $unitsByProduct = $lookups['flashSellingUnits'];
        $products = [];

        foreach ($data['products'] as $index => $product) {
            $productId = (int) $product['product_id'];
            if (! $allowedProducts->has($productId)) {
                throw ValidationException::withMessages([
                    "products.$index.product_id" => [__('commercial.validation.store_product')],
                ]);
            }

            $unitCode = trim((string) $product['selling_unit_code']);
            $unit = $unitsByProduct->get($productId, collect())
                ->first(fn (object $candidate): bool => (string) $candidate->code === $unitCode);
            if ($unit === null) {
                throw ValidationException::withMessages([
                    "products.$index.selling_unit_code" => [__('commercial.validation.flash_active_selling_unit')],
                ]);
            }

            $products[] = [
                'product_id' => $productId,
                'selling_unit_id' => $unit->id,
                'selling_unit_code' => (string) $unit->code,
                'conversion_factor' => (float) $unit->conversion_factor,
                'flash_price' => (float) $product['flash_price'],
                'allocation_base' => isset($product['allocation_base']) && $product['allocation_base'] !== ''
                    ? (float) $product['allocation_base']
                    : null,
            ];
        }

        DB::transaction(function () use (
            $data,
            $channels,
            $products,
            $audienceCustomerIds,
            $audienceCustomerGroupIds,
            $audienceRegions,
            $audienceRoutes,
            $user,
            $storeId,
            $request,
        ): void {
            $offerId = isset($data['offer_id']) ? (int) $data['offer_id'] : 0;
            $before = $offerId > 0 ? (array) (DB::table('flash_offers')->where('store_id', $storeId)->where('id', $offerId)->first() ?? []) : [];
            if ($offerId > 0 && $before === []) {
                abort(404);
            }

            $payload = [
                'store_id' => $storeId,
                'name' => $data['name'],
                'title_ar' => $data['title_ar'],
                'title_en' => $data['title_en'],
                'body_ar' => $data['body_ar'] ?? null,
                'body_en' => $data['body_en'] ?? null,
                'status' => $data['status'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'timezone' => $data['timezone'],
                'channels' => json_encode($channels, JSON_THROW_ON_ERROR),
                'audience_customer_ids' => $audienceCustomerIds === [] ? null : json_encode($audienceCustomerIds, JSON_THROW_ON_ERROR),
                'audience_customer_group_ids' => $audienceCustomerGroupIds === [] ? null : json_encode($audienceCustomerGroupIds, JSON_THROW_ON_ERROR),
                'audience_regions' => $audienceRegions === [] ? null : json_encode($audienceRegions, JSON_THROW_ON_ERROR),
                'audience_routes' => $audienceRoutes === [] ? null : json_encode($audienceRoutes, JSON_THROW_ON_ERROR),
                'allocation_mode' => $data['allocation_mode'],
                'total_allocation_base' => $data['total_allocation_base'] ?? null,
                'per_customer_limit_base' => $data['per_customer_limit_base'] ?? null,
                'reservation_seconds' => $data['reservation_seconds'],
                'retry_count' => $data['retry_count'],
                'cooldown_seconds' => $data['cooldown_seconds'],
                'priority' => $data['priority'],
                'popup_frequency' => $data['popup_frequency'],
                'counts_toward_normal_quota' => $request->boolean('counts_toward_normal_quota'),
                'stackable' => $request->boolean('stackable'),
                'kill_switch' => $request->boolean('kill_switch'),
                'updated_at' => now(),
            ];
            if ($offerId === 0) {
                $offerId = (int) DB::table('flash_offers')->insertGetId([...$payload, 'created_by' => $user->getAuthIdentifier(), 'created_at' => now()]);
            } else {
                DB::table('flash_offers')->where('id', $offerId)->update($payload);
            }

            DB::table('flash_offer_products')->where('flash_offer_id', $offerId)->delete();
            foreach ($products as $product) {
                DB::table('flash_offer_products')->insert([
                    'flash_offer_id' => $offerId,
                    ...$product,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $after = (array) DB::table('flash_offers')->where('id', $offerId)->first();
            $this->audit->record('commercial.flash_offer.saved', $user, null, $before, [...$after, 'store_id' => $storeId], $request);
        });

        return redirect()
            ->route('admin.commercial.flash-offers', ['store_id' => $storeId] + ($request->boolean('support_access') ? ['support_access' => 1] : []))
            ->with('status', __('commercial.status.flash_offer_saved'));
    }

    public function saveFeatureFlags(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->canManageFeatureFlags($user), 403);

        $data = $request->validate([
            CommercialFeatureFlags::COMMERCIAL_RULES => ['required', 'boolean'],
            CommercialFeatureFlags::FLASH_OFFERS => ['required', 'boolean'],
            CommercialFeatureFlags::CUSTOMER_FLASH_POPUP => ['required', 'boolean'],
            CommercialFeatureFlags::VAN_OFFERS => ['required', 'boolean'],
        ]);

        $before = $this->flags->snapshot();
        $after = $this->flags->persist([
            CommercialFeatureFlags::COMMERCIAL_RULES => (bool) $data[CommercialFeatureFlags::COMMERCIAL_RULES],
            CommercialFeatureFlags::FLASH_OFFERS => (bool) $data[CommercialFeatureFlags::FLASH_OFFERS],
            CommercialFeatureFlags::CUSTOMER_FLASH_POPUP => (bool) $data[CommercialFeatureFlags::CUSTOMER_FLASH_POPUP],
            CommercialFeatureFlags::VAN_OFFERS => (bool) $data[CommercialFeatureFlags::VAN_OFFERS],
        ]);

        $this->audit->record('commercial.feature_flags.updated', $user, null, $before, $after, $request);

        return back()->with('status', __('commercial.status.feature_flags_saved'));
    }

    public function flashAction(Request $request, int $offer): RedirectResponse
    {
        [$user, $storeId] = $this->authorizedStore($request, 'promotions.manage');
        $data = $request->validate(['action' => ['required', 'in:schedule,activate,pause,resume,end,cancel,kill_on,kill_off']]);
        $row = DB::table('flash_offers')->where('store_id', $storeId)->where('id', $offer)->first();
        abort_unless($row !== null, 404);
        $status = match ($data['action']) {
            'schedule' => 'scheduled',
            'activate', 'resume' => 'active',
            'pause' => 'paused',
            'end' => 'completed',
            'cancel' => 'cancelled',
            default => (string) $row->status,
        };
        $changes = ['status' => $status, 'updated_at' => now()];
        if ($data['action'] === 'kill_on') {
            $changes['kill_switch'] = true;
        }
        if ($data['action'] === 'kill_off') {
            $changes['kill_switch'] = false;
        }
        DB::table('flash_offers')->where('id', $offer)->update($changes);
        $this->audit->record('commercial.flash_offer.action', $user, null, (array) $row, [...(array) $row, ...$changes, 'store_id' => $storeId], $request);

        return back()->with('status', __('commercial.status.flash_action_applied'));
    }

    /** @return array{0: User, 1: int} */
    private function authorizedStore(Request $request, string $permission, bool $allowEmptyStore = false): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $scope = app(OperationalTenantScope::class);
        $storeId = $request->integer('store_id');

        if ($storeId <= 0) {
            $allowedStoreIds = $scope->allowedStoreIds($user, $permission, 'b2c');

            if ($allowedStoreIds !== []) {
                $storeId = (int) $allowedStoreIds[0];
            } elseif ($allowEmptyStore && $user->hasRole('SUPER_ADMIN')) {
                return [$user, 0];
            }
        }

        abort_unless($storeId > 0, 404);
        $scope->assertStore($user, $storeId, $permission, 'b2c');

        return [$user, $storeId];
    }

    /** @return list<mixed> */
    private function jsonArray(string $json, string $field): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw ValidationException::withMessages([$field => [__('commercial.validation.json_array_required')]]);
        }

        return $decoded;
    }

    /**
     * @param  list<mixed>  $sellingUnits
     * @param  list<mixed>  $windows
     * @param  list<mixed>  $rules
     */
    private function validateStructuredSalesConfiguration(
        int $storeId,
        array $sellingUnits,
        array $windows,
        array $rules,
    ): void {
        $errors = [];
        $seenCodes = [];

        foreach ($sellingUnits as $index => $unit) {
            if (! is_array($unit)) {
                $errors["selling_units_json.$index"] = [__('commercial.validation.selling_unit_code_name')];

                continue;
            }

            $code = strtoupper(trim((string) ($unit['code'] ?? '')));
            $name = trim((string) ($unit['name'] ?? ''));
            $factor = $unit['conversion_factor'] ?? 1;

            if ($code === '' || $name === '') {
                $errors["selling_units_json.$index"] = [__('commercial.validation.selling_unit_code_name')];
            }
            if (! is_numeric($factor) || (float) $factor <= 0) {
                $errors["selling_units_json.$index.conversion_factor"] = [__('commercial.validation.selling_unit_factor')];
            }
            if ($code !== '' && isset($seenCodes[$code])) {
                $errors["selling_units_json.$index.code"] = [__('commercial.validation.duplicate_selling_unit_code')];
            }
            if ($code !== '') {
                $seenCodes[$code] = true;
            }
        }

        foreach ($windows as $index => $window) {
            if (! is_array($window)) {
                $errors["availability_windows_json.$index"] = [__('commercial.validation.availability_recurrence')];

                continue;
            }

            $recurrence = strtolower(trim((string) ($window['recurrence'] ?? 'fixed')));
            if (! in_array($recurrence, ['fixed', 'yearly'], true)) {
                $errors["availability_windows_json.$index.recurrence"] = [__('commercial.validation.availability_recurrence')];

                continue;
            }

            if ($recurrence === 'yearly') {
                foreach (['start_month', 'start_day', 'end_month', 'end_day'] as $field) {
                    $value = $window[$field] ?? null;
                    $max = str_contains($field, 'month') ? 12 : 31;
                    if (! is_numeric($value) || (int) $value < 1 || (int) $value > $max) {
                        $errors["availability_windows_json.$index.$field"] = [__('commercial.validation.availability_yearly_fields')];
                    }
                }
            }
        }

        $legacyCustomerIds = DB::table('b2c_customers')
            ->where('store_id', $storeId)
            ->whereNotNull('legacy_customer_id')
            ->pluck('legacy_customer_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $allowedCustomerIds = array_fill_keys($legacyCustomerIds, true);

        $allowedGroupIds = DB::table('commercial_customer_groups')
            ->where('is_active', true)
            ->where(function ($query) use ($storeId): void {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $allowedGroupIds = array_fill_keys($allowedGroupIds, true);

        foreach ($rules as $index => $rule) {
            if (! is_array($rule)) {
                $errors["rules_json.$index"] = [__('commercial.validation.sales_rule_invalid')];

                continue;
            }

            $customerId = isset($rule['customer_id']) && $rule['customer_id'] !== null && $rule['customer_id'] !== ''
                ? (int) $rule['customer_id']
                : null;
            $groupId = isset($rule['customer_group_id']) && $rule['customer_group_id'] !== null && $rule['customer_group_id'] !== ''
                ? (int) $rule['customer_group_id']
                : null;

            if ($customerId !== null && ! isset($allowedCustomerIds[$customerId])) {
                $errors["rules_json.$index.customer_id"] = [__('commercial.validation.sales_rule_customer_scope')];
            }
            if ($groupId !== null && ! isset($allowedGroupIds[$groupId])) {
                $errors["rules_json.$index.customer_group_id"] = [__('commercial.validation.sales_rule_group_scope')];
            }
            if ($customerId !== null && $groupId !== null) {
                $errors["rules_json.$index.customer_group_id"] = [__('commercial.validation.sales_rule_subject_exclusive')];
            }

            $channel = isset($rule['channel']) && $rule['channel'] !== null
                ? trim((string) $rule['channel'])
                : '';
            if ($channel !== '' && ! in_array($channel, ['customer', 'van', 'admin', 'api'], true)) {
                $errors["rules_json.$index.channel"] = [__('commercial.validation.sales_rule_channel')];
            }

            foreach (['max_per_order', 'max_per_day', 'max_per_week', 'max_per_month', 'max_lifetime'] as $field) {
                $value = $rule[$field] ?? null;
                if ($value !== null && $value !== '' && (! is_numeric($value) || (float) $value < 0)) {
                    $errors["rules_json.$index.$field"] = [__('commercial.validation.sales_rule_limit')];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<int>  $groupIds
     * @param  list<string>  $regions
     * @param  list<string>  $routes
     * @param  array{
     *   flashProducts:Collection,
     *   flashSellingUnits:Collection,
     *   audienceCustomers:Collection,
     *   audienceGroups:Collection,
     *   audienceRegions:Collection,
     *   audienceRoutes:Collection
     * }  $lookups
     */
    private function validateFlashAudience(
        array $customerIds,
        array $groupIds,
        array $regions,
        array $routes,
        array $lookups,
    ): void {
        $errors = [];
        $allowedCustomerIds = $lookups['audienceCustomers']->pluck('id')->map(static fn ($id): int => (int) $id);
        $allowedGroupIds = $lookups['audienceGroups']->pluck('id')->map(static fn ($id): int => (int) $id);

        if (collect($customerIds)->diff($allowedCustomerIds)->isNotEmpty()) {
            $errors['audience_customer_ids'] = [__('commercial.validation.audience_customer_scope')];
        }
        if (collect($groupIds)->diff($allowedGroupIds)->isNotEmpty()) {
            $errors['audience_customer_group_ids'] = [__('commercial.validation.audience_group_scope')];
        }
        if (collect($regions)->diff($lookups['audienceRegions'])->isNotEmpty()) {
            $errors['audience_regions'] = [__('commercial.validation.audience_region_scope')];
        }
        if (collect($routes)->diff($lookups['audienceRoutes'])->isNotEmpty()) {
            $errors['audience_routes'] = [__('commercial.validation.audience_route_scope')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function flashOfferRow(int $storeId, int $offer): object
    {
        $row = DB::table('flash_offers')
            ->where('store_id', $storeId)
            ->where('id', $offer)
            ->first();

        abort_unless($row !== null, 404);

        return $row;
    }

    /** @return list<string> */
    private function storedJsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): string => (string) $item,
            array_filter($decoded, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''),
        ));
    }

    /**
     * @return array{
     *   flashProducts:Collection,
     *   flashSellingUnits:Collection,
     *   audienceCustomers:Collection,
     *   audienceGroups:Collection,
     *   audienceRegions:Collection,
     *   audienceRoutes:Collection
     * }
     */
    private function flashOfferLookups(int $storeId): array
    {
        $flashProducts = DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->where('store_products.store_id', $storeId)
            ->where('store_products.is_active', true)
            ->where('products.is_active', true)
            ->orderBy('products.name')
            ->get([
                'products.id',
                'products.name',
                'products.sku',
            ]);

        $flashSellingUnits = DB::table('product_selling_units')
            ->whereIn('product_id', $flashProducts->pluck('id'))
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('name')
            ->get([
                'id',
                'product_id',
                'code',
                'name',
                'conversion_factor',
                'price',
            ])
            ->groupBy('product_id');

        $legacyCustomerIds = DB::table('b2c_customers')
            ->where('store_id', $storeId)
            ->whereNotNull('legacy_customer_id')
            ->pluck('legacy_customer_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $audienceCustomers = DB::table('customers')
            ->whereIn('id', $legacyCustomerIds)
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'email', 'phone']);

        $audienceGroups = DB::table('commercial_customer_groups')
            ->where('is_active', true)
            ->where(function ($query) use ($storeId): void {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get(['id', 'name']);

        $audienceRegions = DB::table('addresses')
            ->whereIn('customer_id', $legacyCustomerIds)
            ->get(['area', 'governorate'])
            ->flatMap(static fn (object $address): array => [
                trim((string) ($address->area ?? '')),
                trim((string) ($address->governorate ?? '')),
            ])
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $audienceRoutes = DB::table('van_visits')
            ->where('store_id', $storeId)
            ->pluck('metadata')
            ->flatMap(static function ($metadata): array {
                $decoded = is_string($metadata) ? json_decode($metadata, true) : (is_array($metadata) ? $metadata : []);
                if (! is_array($decoded)) {
                    return [];
                }

                return [
                    trim((string) ($decoded['route_id'] ?? '')),
                    trim((string) ($decoded['route_code'] ?? '')),
                    trim((string) ($decoded['route'] ?? '')),
                ];
            })
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return compact(
            'flashProducts',
            'flashSellingUnits',
            'audienceCustomers',
            'audienceGroups',
            'audienceRegions',
            'audienceRoutes',
        );
    }

    private function canManageFeatureFlags(User $user): bool
    {
        return $user->hasRole('SUPER_ADMIN') || $user->hasPermission('platform.manage');
    }

    private function hasApiContract(string $needle): bool
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn (IlluminateRoute $route): bool => str_starts_with(strtolower($route->uri()), 'api/')
                && str_contains(strtolower($route->uri()), $needle));
    }

    /** @param array<string, mixed> $payload */
    private function render(Request $request, User $user, int $storeId, string $section, array $payload): View
    {
        $supportAccess = $request->boolean('support_access');

        return view('admin.commercial-dashboard', [
            ...$payload,
            'user' => $user,
            'storeId' => $storeId,
            'section' => $section,
            'supportAccess' => $supportAccess,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => $section === 'sales-control' ? 'b2c_products' : 'b2c_promotions',
        ]);
    }
}
