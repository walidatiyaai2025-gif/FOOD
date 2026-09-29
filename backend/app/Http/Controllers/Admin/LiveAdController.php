<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveAd;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MarketingImageService;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LiveAdController extends Controller
{
    private const TIMEZONE = 'Asia/Kuwait';

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly AuditLogger $audit,
        private readonly MarketingImageService $images,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->authorizeAccess($request, 'live_ads.view');
        App::setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        $search = trim((string) $request->query('q', ''));
        $channel = strtolower(trim((string) $request->query('channel', '')));
        $status = trim((string) $request->query('status', ''));

        $ads = $this->visibleAds($actor)
            ->with(['store:id,name'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $nested) use ($search): void {
                $nested->where('name', 'like', '%'.$search.'%')
                    ->orWhere('title_ar', 'like', '%'.$search.'%')
                    ->orWhere('title_en', 'like', '%'.$search.'%');
            }))
            ->when(in_array($channel, ['b2b', 'b2c'], true), fn (Builder $query) => $query->where('channel', $channel))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('priority')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.live-ads', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'live_ads',
            'ads' => $ads,
            'search' => $search,
            'channelFilter' => $channel,
            'statusFilter' => $status,
            'canB2b' => $actor->hasRole('SUPER_ADMIN') || ($actor->hasRole('B2B_ADMIN') && $actor->hasPermission('live_ads.manage')),
            'canAllChannels' => $actor->hasRole('SUPER_ADMIN'),
            'retailStores' => $this->allowedRetailStores($actor),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->authorizeAccess($request, 'live_ads.manage');
        $data = $this->validated($request);
        [$channel, $storeId, $scopeKey] = $this->assertScope($actor, $data);
        $imagePath = $this->storeImage($request, $storeId);

        try {
            $ad = LiveAd::query()->create([
                ...$this->payload($data),
                'channel' => $channel,
                'store_id' => $storeId,
                'scope_key' => $scopeKey,
                'image_path' => $imagePath,
                'created_by' => $actor->id,
            ]);
        } catch (Throwable $exception) {
            $this->images->delete($imagePath);
            throw $exception;
        }

        $this->audit->record('live_ad.created', $actor, $ad, null, $ad->toArray(), $request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم إنشاء الإعلان الحي.' : 'Live ad created.');
    }

    public function update(Request $request, LiveAd $liveAd): RedirectResponse
    {
        $actor = $this->authorizeAd($request, $liveAd, 'live_ads.manage');
        $data = $this->validated($request);
        [$channel, $storeId, $scopeKey] = $this->assertScope($actor, $data);
        $before = $liveAd->toArray();
        $newImage = $request->hasFile('image') ? $this->storeImage($request, $storeId) : null;

        try {
            $liveAd->update([
                ...$this->payload($data),
                'channel' => $channel,
                'store_id' => $storeId,
                'scope_key' => $scopeKey,
                ...($newImage !== null ? ['image_path' => $newImage] : []),
            ]);
        } catch (Throwable $exception) {
            $this->images->delete($newImage);
            throw $exception;
        }

        if ($newImage !== null) {
            $this->images->delete($before['image_path'] ?? null);
        }

        $this->audit->record('live_ad.updated', $actor, $liveAd, $before, $liveAd->fresh()->toArray(), $request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تحديث الإعلان الحي.' : 'Live ad updated.');
    }

    public function toggle(Request $request, LiveAd $liveAd): RedirectResponse
    {
        $actor = $this->authorizeAd($request, $liveAd, 'live_ads.manage');
        $before = $liveAd->toArray();
        $liveAd->update(['is_active' => ! $liveAd->is_active]);
        $this->audit->record('live_ad.status_changed', $actor, $liveAd, $before, $liveAd->fresh()->toArray(), $request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تحديث حالة الإعلان.' : 'Live ad status updated.');
    }

    public function destroy(Request $request, LiveAd $liveAd): RedirectResponse
    {
        $actor = $this->authorizeAd($request, $liveAd, 'live_ads.manage');
        $before = $liveAd->toArray();
        $path = $liveAd->image_path;
        $liveAd->delete();
        $this->images->delete($path);
        $this->audit->record('live_ad.deleted', $actor, $liveAd, $before, null, $request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم حذف الإعلان الحي.' : 'Live ad deleted.');
    }

    private function authorizeAccess(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        if ($actor->hasPermission($permission)) {
            return $actor;
        }

        $retailIds = app(TenantContextResolver::class)->retailStoreIds($actor);
        $hasScoped = $retailIds !== [] && $actor->storeRoleAssignments()
            ->whereIn('store_id', $retailIds)
            ->whereHas('store', fn ($query) => $query
                ->where('stores.is_active', true)
                ->where('stores.live_ads_enabled', true))
            ->whereHas('role', fn ($query) => $query
                ->where('roles.is_active', true)
                ->where('roles.scope', 'store')
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permissions.code', $permission)))
            ->exists();

        abort_unless($hasScoped, 403);

        return $actor;
    }

    private function authorizeAd(Request $request, LiveAd $ad, string $permission): User
    {
        $actor = $this->authorizeAccess($request, $permission);
        abort_unless($this->visibleAds($actor)->whereKey($ad->id)->exists(), 404);

        return $actor;
    }

    /** @return Builder<LiveAd> */
    private function visibleAds(User $actor): Builder
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return LiveAd::query();
        }

        $retailIds = $this->allowedRetailStoreIds($actor);
        $canB2b = $actor->hasRole('B2B_ADMIN') && $actor->hasPermission('live_ads.view');

        return LiveAd::query()->where(function (Builder $query) use ($canB2b, $retailIds): void {
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
        $storeId = isset($data['store_id']) && $data['store_id'] !== '' ? (int) $data['store_id'] : null;

        if ($channel === 'b2b') {
            abort_unless($actor->hasRole('SUPER_ADMIN') || ($actor->hasRole('B2B_ADMIN') && $actor->hasPermission('live_ads.manage')), 403);

            return ['b2b', null, 'b2b'];
        }

        abort_unless($channel === 'b2c' && $storeId !== null, 422);
        abort_unless(in_array($storeId, $this->allowedRetailStoreIds($actor), true), 404);

        return ['b2c', $storeId, 'b2c:'.$storeId];
    }

    /** @return list<int> */
    private function allowedRetailStoreIds(User $actor): array
    {
        $query = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->where('stores.is_active', true)
            ->where('stores.live_ads_enabled', true);

        if (! $actor->hasRole('SUPER_ADMIN')) {
            $ids = app(TenantContextResolver::class)->retailStoreIds($actor);
            if ($ids === []) {
                return [];
            }
            $query->whereIn('stores.id', $ids);
        }

        return $query->orderBy('stores.id')->pluck('stores.id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return list<array{id:int,name:string}> */
    private function allowedRetailStores(User $actor): array
    {
        $ids = $this->allowedRetailStoreIds($actor);
        if ($ids === []) {
            return [];
        }

        return DB::table('stores')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($store): array => ['id' => (int) $store->id, 'name' => (string) $store->name])
            ->all();
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'body_ar' => ['nullable', 'string', 'max:5000'],
            'body_en' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'cta_label_ar' => ['nullable', 'string', 'max:120'],
            'cta_label_en' => ['nullable', 'string', 'max:120'],
            'cta_target' => ['nullable', 'string', 'max:500'],
            'frequency' => ['required', Rule::in(['once_per_install', 'once_per_session', 'every_open'])],
            'priority' => ['required', 'integer', 'min:0', 'max:9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'is_dismissible' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['starts_at']) && ! empty($data['ends_at'])) {
            $start = CarbonImmutable::parse((string) $data['starts_at'], self::TIMEZONE);
            $end = CarbonImmutable::parse((string) $data['ends_at'], self::TIMEZONE);
            if ($end->lte($start)) {
                throw ValidationException::withMessages(['ends_at' => ['End time must be after start time.']]);
            }
        }

        return $data;
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function payload(array $data): array
    {
        $startsAt = ! empty($data['starts_at'])
            ? CarbonImmutable::parse((string) $data['starts_at'], self::TIMEZONE)->utc()
            : now()->utc();

        $endsAt = ! empty($data['ends_at'])
            ? CarbonImmutable::parse((string) $data['ends_at'], self::TIMEZONE)->utc()
            : null;

        if ($endsAt === null && ! empty($data['duration_minutes'])) {
            $endsAt = $startsAt->addMinutes((int) $data['duration_minutes']);
        }

        return [
            'name' => trim((string) $data['name']),
            'title_ar' => trim((string) $data['title_ar']),
            'title_en' => trim((string) $data['title_en']),
            'body_ar' => $data['body_ar'] ?? null,
            'body_en' => $data['body_en'] ?? null,
            'cta_label_ar' => $data['cta_label_ar'] ?? null,
            'cta_label_en' => $data['cta_label_en'] ?? null,
            'cta_target' => $data['cta_target'] ?? null,
            'frequency' => (string) $data['frequency'],
            'priority' => (int) $data['priority'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'is_dismissible' => (bool) ($data['is_dismissible'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    private function storeImage(Request $request, ?int $storeId): ?string
    {
        $file = $request->file('image');
        if (! $file instanceof UploadedFile) {
            return null;
        }

        return $this->images->store($file, 'live-ads', $storeId);
    }
}
