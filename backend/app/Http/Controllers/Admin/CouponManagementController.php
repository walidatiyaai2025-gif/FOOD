<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingCoupon;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CouponManagementController extends Controller
{
    private const TIMEZONE = 'Asia/Kuwait';

    private readonly AdminNavigation $navigation;

    private readonly AuditLogger $audit;

    public function __construct(AdminNavigation $navigation, AuditLogger $audit)
    {
        $this->navigation = $navigation;
        $this->audit = $audit;
    }

    public function index(Request $request): View
    {
        $actor = $this->authorizeAccess($request, 'coupons.view');
        App::setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        $search = trim((string) $request->query('q', ''));
        $channel = strtolower(trim((string) $request->query('channel', '')));
        $status = trim((string) $request->query('status', ''));

        $coupons = $this->visibleCoupons($actor)
            ->with(['store:id,name'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $nested) use ($search): void {
                $nested->where('code', 'like', '%'.$search.'%')
                    ->orWhere('name_ar', 'like', '%'.$search.'%')
                    ->orWhere('name_en', 'like', '%'.$search.'%');
            }))
            ->when(in_array($channel, ['b2b', 'b2c'], true), fn (Builder $query) => $query->where('channel', $channel))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $manageableCouponIds = $this->visibleCoupons($actor, 'coupons.manage')
            ->whereIn('id', $coupons->getCollection()->pluck('id'))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return view('admin.coupons', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'coupons',
            'coupons' => $coupons,
            'search' => $search,
            'channelFilter' => $channel,
            'statusFilter' => $status,
            'canB2b' => $actor->hasRole('SUPER_ADMIN') || ($actor->hasRole('B2B_ADMIN') && $actor->hasPermission('coupons.manage')),
            'canManageCoupons' => $actor->hasPermission('coupons.manage') || $this->allowedRetailStoreIds($actor, 'coupons.manage') !== [],
            'manageableCouponIds' => $manageableCouponIds,
            'canAllChannels' => $actor->hasRole('SUPER_ADMIN'),
            'retailStores' => $this->allowedRetailStores($actor, 'coupons.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->authorizeAccess($request, 'coupons.manage');
        $data = $this->validated($request);
        [$channel, $storeId, $scopeKey] = $this->assertScope($actor, $data);
        $this->assertUniqueCode($scopeKey, (string) $data['code']);

        $coupon = MarketingCoupon::query()->create([
            ...$this->payload($data),
            'channel' => $channel,
            'store_id' => $storeId,
            'scope_key' => $scopeKey,
            'created_by' => $actor->id,
        ]);

        $this->audit->record('coupon.created', $actor, $coupon, null, $coupon->toArray(), $request);

        return back()->with('status', __('coupons.created'));
    }

    public function update(Request $request, MarketingCoupon $coupon): RedirectResponse
    {
        $actor = $this->authorizeCoupon($request, $coupon, 'coupons.manage');
        $data = $this->validated($request);
        [$channel, $storeId, $scopeKey] = $this->assertScope($actor, $data);
        $this->assertUniqueCode($scopeKey, (string) $data['code'], (int) $coupon->id);

        $before = $coupon->toArray();
        $coupon->update([
            ...$this->payload($data),
            'channel' => $channel,
            'store_id' => $storeId,
            'scope_key' => $scopeKey,
        ]);

        $this->audit->record('coupon.updated', $actor, $coupon, $before, $coupon->fresh()->toArray(), $request);

        return back()->with('status', __('coupons.updated'));
    }

    public function toggle(Request $request, MarketingCoupon $coupon): RedirectResponse
    {
        $actor = $this->authorizeCoupon($request, $coupon, 'coupons.manage');
        $before = $coupon->toArray();
        $coupon->update(['is_active' => $coupon->is_active ? false : true]);
        $this->audit->record('coupon.status_changed', $actor, $coupon, $before, $coupon->fresh()->toArray(), $request);

        return back()->with('status', __('coupons.status_updated'));
    }

    public function destroy(Request $request, MarketingCoupon $coupon): RedirectResponse
    {
        $actor = $this->authorizeCoupon($request, $coupon, 'coupons.manage');
        abort_if((int) $coupon->used_count > 0 || $coupon->redemptions()->exists(), 409, 'Used coupons cannot be deleted; deactivate them instead.');
        $before = $coupon->toArray();
        $id = (int) $coupon->id;
        $coupon->delete();
        $this->audit->record('coupon.deleted', $actor, $coupon, $before, ['id' => $id], $request);

        return back()->with('status', __('coupons.deleted'));
    }

    private function authorizeAccess(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        if (! $actor->hasPermission($permission)) {
            // The canonical scope evaluator applies user-level global/store DENYs,
            // rather than trusting an inherited store-role permission row.
            abort_unless($this->allowedRetailStoreIds($actor, $permission) !== [], 403);
        }

        return $actor;
    }

    private function authorizeCoupon(Request $request, MarketingCoupon $coupon, string $permission): User
    {
        $actor = $this->authorizeAccess($request, $permission);
        abort_unless($this->visibleCoupons($actor, $permission)->whereKey($coupon->id)->exists(), 404);

        return $actor;
    }

    /** @return Builder<MarketingCoupon> */
    private function visibleCoupons(User $actor, string $permission = 'coupons.view'): Builder
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return MarketingCoupon::query();
        }

        $retailIds = $this->allowedRetailStoreIds($actor, $permission);
        $canB2b = $actor->hasRole('B2B_ADMIN') && $actor->hasPermission($permission);

        return MarketingCoupon::query()->where(function (Builder $query) use ($canB2b, $retailIds): void {
            if ($canB2b) {
                $query->where('channel', 'b2b');
            } else {
                $query->whereRaw('1 = 0');
            }

            if ($retailIds !== []) {
                $query->orWhere(function (Builder $b2c) use ($retailIds): void {
                    $b2c->where('channel', 'b2c')->whereIn('store_id', $retailIds);
                });
            }
        });
    }

    /** @param array<string,mixed> $data
     * @return array{0:string,1:?int,2:string}
     */
    private function assertScope(User $actor, array $data): array
    {
        $channel = strtolower((string) $data['channel']);
        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;

        if ($channel === 'b2b') {
            abort_unless($actor->hasRole('SUPER_ADMIN') || ($actor->hasRole('B2B_ADMIN') && $actor->hasPermission('coupons.manage')), 403);

            return ['b2b', null, 'b2b'];
        }

        abort_unless($channel === 'b2c' && $storeId !== null, 422);
        abort_unless(in_array($storeId, $this->allowedRetailStoreIds($actor, 'coupons.manage'), true), 404);

        return ['b2c', $storeId, 'b2c:'.$storeId];
    }

    /** @return list<int> */
    private function allowedRetailStoreIds(User $actor, string $permission = 'coupons.view'): array
    {
        $ids = app(OperationalTenantScope::class)->allowedStoreIds($actor, $permission, 'b2c');

        if (! $actor->hasRole('SUPER_ADMIN')) {
            // Preserve the existing assigned-store boundary even when an actor
            // also holds an unrelated global grant for the requested ability.
            $assigned = app(TenantContextResolver::class)->retailStoreIds($actor);
            $ids = array_values(array_intersect($ids, $assigned));
        }

        if ($ids === []) {
            return [];
        }

        return DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->where('stores.is_active', true)
            ->where('stores.coupons_enabled', true)
            ->whereIn('stores.id', $ids)
            ->orderBy('stores.id')
            ->pluck('stores.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @return list<array{id:int,name:string}> */
    private function allowedRetailStores(User $actor, string $permission = 'coupons.view'): array
    {
        $ids = $this->allowedRetailStoreIds($actor, $permission);
        if ($ids === []) {
            return [];
        }

        return DB::table('stores')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($store): array => ['id' => (int) $store->id, 'name' => (string) $store->name])
            ->all();
    }

    private function assertUniqueCode(string $scopeKey, string $code, ?int $ignoreId = null): void
    {
        $query = MarketingCoupon::query()
            ->where('scope_key', $scopeKey)
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))]);

        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['code' => ['Coupon code already exists in this business scope.']]);
        }
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed', 'free_shipping'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit_total' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($data['discount_type'] === 'percentage' && (float) ($data['discount_value'] ?? 0) > 100) {
            throw ValidationException::withMessages(['discount_value' => ['Percentage discount cannot exceed 100.']]);
        }

        return $data;
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function payload(array $data): array
    {
        $type = (string) $data['discount_type'];

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name_ar' => trim((string) $data['name_ar']),
            'name_en' => trim((string) $data['name_en']),
            'description_ar' => $data['description_ar'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'discount_type' => $type,
            'discount_value' => $type === 'free_shipping' ? 0 : (float) ($data['discount_value'] ?? 0),
            'minimum_order_amount' => $data['minimum_order_amount'] ?? null,
            'maximum_discount_amount' => $type === 'percentage' ? ($data['maximum_discount_amount'] ?? null) : null,
            'usage_limit_total' => $data['usage_limit_total'] ?? null,
            'usage_limit_per_user' => $data['usage_limit_per_user'] ?? null,
            'first_order_only' => (bool) ($data['first_order_only'] ?? false),
            'starts_at' => isset($data['starts_at']) ? CarbonImmutable::parse((string) $data['starts_at'], self::TIMEZONE)->utc() : null,
            'ends_at' => isset($data['ends_at']) ? CarbonImmutable::parse((string) $data['ends_at'], self::TIMEZONE)->utc() : null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
