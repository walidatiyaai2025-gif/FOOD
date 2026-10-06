<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommercialDashboardController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly AuditLogger $audit,
    ) {}

    public function salesControl(Request $request): View
    {
        [$user, $storeId] = $this->authorizedStore($request, 'catalog.view');

        $products = DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->where('store_products.store_id', $storeId)
            ->orderBy('products.name')
            ->limit(200)
            ->get(['products.id', 'products.sku', 'products.name', 'store_products.is_active']);

        return $this->render($request, $user, $storeId, 'sales-control', [
            'products' => $products,
            'policies' => DB::table('product_commercial_policies')->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id'),
            'sellingUnits' => DB::table('product_selling_units')->whereIn('product_id', $products->pluck('id'))->orderBy('id')->get()->groupBy('product_id'),
            'availabilityWindows' => DB::table('product_availability_windows')->whereIn('product_id', $products->pluck('id'))->orderBy('id')->get()->groupBy('product_id'),
            'commercialRules' => DB::table('product_commercial_rules')->whereIn('product_id', $products->pluck('id'))->orderBy('id')->get()->groupBy('product_id'),
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

        DB::transaction(function () use ($product, $data, $channels, $sellingUnits, $windows, $rules, $user, $storeId, $request): void {
            $before = (array) (DB::table('product_commercial_policies')->where('product_id', $product)->first() ?? []);
            DB::table('product_commercial_policies')->updateOrInsert(
                ['product_id' => $product],
                [
                    'status' => $data['status'],
                    'hide_when_closed' => $request->boolean('hide_when_closed'),
                    'override_allowed' => $request->boolean('override_allowed'),
                    'channels' => json_encode($channels, JSON_THROW_ON_ERROR),
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
                    throw ValidationException::withMessages(['selling_units_json' => ['Every selling unit needs code and name.']]);
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

        return back()->with('status', 'Commercial sales control saved.');
    }

    public function flashOffers(Request $request): View
    {
        [$user, $storeId] = $this->authorizedStore($request, 'promotions.view');

        return $this->render($request, $user, $storeId, 'flash-offers', [
            'existingPromotions' => DB::table('promotions')->where('store_id', $storeId)->orderByDesc('created_at')->limit(100)->get(),
            'flashOffers' => DB::table('flash_offers')->where('store_id', $storeId)->orderByDesc('id')->limit(100)->get(),
            'contractReady' => $this->hasApiContract('flash'),
        ]);
    }

    public function flashPreview(Request $request, int $offer): View
    {
        [$user, $storeId] = $this->authorizedStore($request, 'promotions.view');
        $row = $this->flashOfferRow($storeId, $offer);
        $products = DB::table('flash_offer_products')
            ->leftJoin('products', 'products.id', '=', 'flash_offer_products.product_id')
            ->where('flash_offer_products.flash_offer_id', $offer)
            ->orderBy('flash_offer_products.id')
            ->get([
                'flash_offer_products.id',
                'flash_offer_products.product_id',
                'products.name as product_name',
                'products.sku as product_sku',
                'flash_offer_products.selling_unit_code',
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
            'channels_json' => ['required', 'json'],
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
            'products_json' => ['required', 'json'],
        ]);
        $channels = $this->jsonArray($data['channels_json'], 'channels_json');
        $products = $this->jsonArray($data['products_json'], 'products_json');

        DB::transaction(function () use ($data, $channels, $products, $user, $storeId, $request): void {
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
                if (! is_array($product) || empty($product['product_id']) || ! isset($product['flash_price'])) {
                    throw ValidationException::withMessages(['products_json' => ['Every Flash product needs product_id and flash_price.']]);
                }
                DB::table('flash_offer_products')->insert([
                    'flash_offer_id' => $offerId,
                    'product_id' => (int) $product['product_id'],
                    'selling_unit_id' => $product['selling_unit_id'] ?? null,
                    'selling_unit_code' => $product['selling_unit_code'] ?? null,
                    'conversion_factor' => (float) ($product['conversion_factor'] ?? 1),
                    'flash_price' => (float) $product['flash_price'],
                    'allocation_base' => $product['allocation_base'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $after = (array) DB::table('flash_offers')->where('id', $offerId)->first();
            $this->audit->record('commercial.flash_offer.saved', $user, null, $before, [...$after, 'store_id' => $storeId], $request);
        });

        return back()->with('status', 'Flash Offer saved.');
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

        return back()->with('status', 'Flash Offer action applied.');
    }

    /** @return array{0: User, 1: int} */
    private function authorizedStore(Request $request, string $permission): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $storeId = $request->integer('store_id');
        abort_unless($storeId > 0, 404);
        app(OperationalTenantScope::class)->assertStore($user, $storeId, $permission, 'b2c');

        return [$user, $storeId];
    }

    /** @return list<mixed> */
    private function jsonArray(string $json, string $field): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw ValidationException::withMessages([$field => ['A JSON array is required.']]);
        }

        return $decoded;
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
