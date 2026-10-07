<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MarketingImageService;
use App\Services\NotificationCampaignDispatcher;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
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

final class PromotionalNotificationCampaignController extends Controller
{
    private const TIMEZONE = 'Asia/Kuwait';

    public function __construct(private readonly AdminNavigation $navigation) {}

    public function index(Request $request): View
    {
        $actor = $this->authorizeAccess($request);
        App::setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        $campaigns = $this->visibleCampaigns($actor)
            ->with(['runs' => fn ($query) => $query->latest('id')->limit(10)])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $nested) use ($search): void {
                $nested->where('name', 'like', '%'.$search.'%')
                    ->orWhere('title_ar', 'like', '%'.$search.'%')
                    ->orWhere('title_en', 'like', '%'.$search.'%');
            }))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.notification-campaigns', [
            'campaigns' => $campaigns,
            'search' => $search,
            'status' => $status,
            'canB2b' => $actor->hasRole('SUPER_ADMIN') || $actor->hasRole('B2B_ADMIN'),
            'canAllChannels' => $actor->hasRole('SUPER_ADMIN'),
            'b2bStores' => $this->allowedStores($actor, 'b2b'),
            'b2cStores' => $this->allowedStores($actor, 'b2c'),
            'userTargets' => User::query()
                ->select(['id', 'name', 'email'])
                ->orderBy('name')
                ->orderBy('email')
                ->get(),
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'notification_campaigns',
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $audit,
        MarketingImageService $images,
    ): RedirectResponse {
        $actor = $this->authorizeAccess($request);
        $data = $this->validated($request);
        $this->assertScope($actor, $data);
        $this->assertAudienceAppCompatibility($data);
        $this->assertTargetUserScope($data);
        $schedule = $this->scheduleData($data);
        $imagePath = $this->storeImage($request, $images, $data['store_id'] ?? null);

        try {
            $campaign = NotificationCampaign::query()->create([
                'name' => $data['name'],
                'type' => 'promotion',
                'title_ar' => $data['title_ar'],
                'title_en' => $data['title_en'],
                'body_ar' => $data['body_ar'],
                'body_en' => $data['body_en'],
                'image_path' => $imagePath,
                'audience' => $data['audience'],
                'app' => $data['app'],
                'target_channel' => $data['target_channel'],
                'delivery_channel' => $data['delivery_channel'],
                'popup_frequency' => $data['popup_frequency'],
                'popup_cta_label_ar' => $data['popup_cta_label_ar'] ?? null,
                'popup_cta_label_en' => $data['popup_cta_label_en'] ?? null,
                'popup_cta_target' => $data['popup_cta_target'] ?? null,
                'user_id' => $data['audience'] === 'user' ? ($data['user_id'] ?? null) : null,
                'store_id' => $data['store_id'] ?? null,
                'created_by' => $actor->id,
                ...$schedule,
                'status' => $request->boolean('activate') ? 'active' : 'draft',
            ]);
        } catch (Throwable $exception) {
            $images->delete($imagePath);
            throw $exception;
        }

        $audit->record('notification_campaign.created', $actor, $campaign, null, $campaign->toArray(), $request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم حفظ الحملة الإعلانية.' : 'Promotional campaign saved.');
    }

    public function update(
        Request $request,
        NotificationCampaign $campaign,
        AuditLogger $audit,
        MarketingImageService $images,
    ): RedirectResponse {
        $actor = $this->authorizeCampaign($request, $campaign);
        abort_if(in_array($campaign->status, ['completed', 'cancelled'], true), 409);
        $data = $this->validated($request);
        $this->assertScope($actor, $data);
        $this->assertAudienceAppCompatibility($data);
        $this->assertTargetUserScope($data);
        $before = $campaign->toArray();
        $newImage = $request->hasFile('image')
            ? $this->storeImage($request, $images, $data['store_id'] ?? null)
            : null;

        try {
            $campaign->update([
                'name' => $data['name'],
                'title_ar' => $data['title_ar'],
                'title_en' => $data['title_en'],
                'body_ar' => $data['body_ar'],
                'body_en' => $data['body_en'],
                ...($newImage !== null ? ['image_path' => $newImage] : []),
                'audience' => $data['audience'],
                'app' => $data['app'],
                'target_channel' => $data['target_channel'],
                'delivery_channel' => $data['delivery_channel'],
                'popup_frequency' => $data['popup_frequency'],
                'popup_cta_label_ar' => $data['popup_cta_label_ar'] ?? null,
                'popup_cta_label_en' => $data['popup_cta_label_en'] ?? null,
                'popup_cta_target' => $data['popup_cta_target'] ?? null,
                'user_id' => $data['audience'] === 'user' ? ($data['user_id'] ?? null) : null,
                'store_id' => $data['store_id'] ?? null,
                ...$this->scheduleData($data),
            ]);
        } catch (Throwable $exception) {
            $images->delete($newImage);
            throw $exception;
        }

        if ($newImage !== null) {
            $images->delete($before['image_path'] ?? null);
        }

        $audit->record('notification_campaign.updated', $actor, $campaign, $before, $campaign->fresh()->toArray(), $request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تحديث الحملة.' : 'Campaign updated.');
    }

    public function state(Request $request, NotificationCampaign $campaign, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorizeCampaign($request, $campaign);
        $data = $request->validate([
            'state' => ['required', Rule::in(['active', 'paused', 'cancelled'])],
        ]);
        $before = $campaign->toArray();

        if ($data['state'] === 'active') {
            abort_if(in_array($campaign->status, ['completed', 'cancelled'], true), 409);
            $campaign->update([
                'status' => 'active',
                'next_run_at' => $campaign->next_run_at ?? $campaign->starts_at ?? now(),
            ]);
        } else {
            $campaign->update([
                'status' => $data['state'],
                'next_run_at' => $data['state'] === 'cancelled' ? null : $campaign->next_run_at,
            ]);
        }

        $audit->record('notification_campaign.state_changed', $actor, $campaign, $before, $campaign->fresh()->toArray(), $request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تحديث حالة الحملة.' : 'Campaign state updated.');
    }

    public function sendNow(
        Request $request,
        NotificationCampaign $campaign,
        AuditLogger $audit,
        NotificationCampaignDispatcher $dispatcher,
    ): RedirectResponse {
        $actor = $this->authorizeCampaign($request, $campaign);
        abort_if(in_array($campaign->status, ['completed', 'cancelled'], true), 409);
        $before = $campaign->toArray();
        $campaign->update(['status' => 'active', 'next_run_at' => now()]);
        $audit->record('notification_campaign.send_now', $actor, $campaign, $before, $campaign->fresh()->toArray(), $request);
        $dispatcher->dispatchCampaign((int) $campaign->id);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تنفيذ الإرسال الآن.' : 'Campaign dispatched now.');
    }

    private function authorizeAccess(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $hasGlobal = $actor->hasPermission('notifications.manage');
        $hasStoreScope = $actor->storeRoleAssignments()
            ->whereHas('store', fn ($query) => $query
                ->where('stores.is_active', true)
                ->where('stores.advertising_enabled', true))
            ->whereHas('role', fn ($query) => $query
                ->where('roles.is_active', true)
                ->whereHas('permissions', fn ($permissions) => $permissions->where('permissions.code', 'notifications.manage')))
            ->exists();

        abort_unless($hasGlobal || $hasStoreScope, 403);

        return $actor;
    }

    private function authorizeCampaign(Request $request, NotificationCampaign $campaign): User
    {
        $actor = $this->authorizeAccess($request);
        abort_unless($this->visibleCampaigns($actor)->whereKey($campaign->id)->exists(), 404);

        return $actor;
    }

    /** @return Builder<NotificationCampaign> */
    private function visibleCampaigns(User $actor): Builder
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return NotificationCampaign::query();
        }

        $b2cStoreIds = $this->allowedB2cStoreIds($actor);
        $canB2b = $actor->hasRole('B2B_ADMIN') && $actor->hasPermission('notifications.manage');

        return NotificationCampaign::query()->where(function (Builder $query) use ($canB2b, $b2cStoreIds): void {
            if ($canB2b) {
                $query->where('target_channel', 'b2b');
            } else {
                $query->whereRaw('1 = 0');
            }

            if ($b2cStoreIds !== []) {
                $query->orWhere(function (Builder $b2c) use ($b2cStoreIds): void {
                    $b2c->where('target_channel', 'b2c')->whereIn('store_id', $b2cStoreIds);
                });
            }
        });
    }

    /** @param array<string, mixed> $data */
    private function assertScope(User $actor, array $data): void
    {
        $target = (string) $data['target_channel'];
        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;

        if ($actor->hasRole('SUPER_ADMIN')) {
            if ($storeId !== null) {
                app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'notifications.manage', $target === 'all' ? null : $target);
                if ($target === 'b2c') {
                    abort_unless(DB::table('stores')->where('id', $storeId)->where('advertising_enabled', true)->exists(), 403);
                }
            }

            return;
        }

        if ($actor->hasRole('B2B_ADMIN')) {
            abort_unless($target === 'b2b', 403);
            if ($storeId !== null) {
                app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'notifications.manage', 'b2b');
            }

            return;
        }

        abort_unless($target === 'b2c' && $storeId !== null, 403);
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'notifications.manage', 'b2c');
    }

    /** @param array<string, mixed> $data */
    private function assertAudienceAppCompatibility(array $data): void
    {
        $audience = (string) $data['audience'];
        $app = (string) $data['app'];

        if ($audience === 'customer' && in_array($app, ['driver', 'van'], true)) {
            throw ValidationException::withMessages([
                'app' => ['Customer campaigns cannot target operations apps.'],
            ]);
        }

        if ($audience === 'driver' && in_array($app, ['customer', 'van'], true)) {
            throw ValidationException::withMessages([
                'app' => ['Driver campaigns cannot target Customer or Van apps.'],
            ]);
        }

        if ($audience === 'van' && in_array($app, ['customer', 'driver'], true)) {
            throw ValidationException::withMessages([
                'app' => ['Van campaigns cannot target Customer or Driver apps.'],
            ]);
        }

        if (($audience === 'van' || $app === 'van')
            && ($data['target_channel'] ?? 'all') === 'b2c') {
            throw ValidationException::withMessages([
                'target_channel' => ['Van notifications are available for Wholesale/B2B operations only.'],
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertTargetUserScope(array $data): void
    {
        if (($data['audience'] ?? null) !== 'user') {
            return;
        }

        $userId = (int) ($data['user_id'] ?? 0);
        $target = (string) ($data['target_channel'] ?? '');
        $app = (string) ($data['app'] ?? 'all');
        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;

        if ($userId <= 0) {
            throw ValidationException::withMessages(['user_id' => ['A target user is required.']]);
        }

        if ($target === 'b2c') {
            if ($storeId === null) {
                throw ValidationException::withMessages([
                    'store_id' => ['A retail store is required for a user-targeted B2C campaign.'],
                ]);
            }

            $customer = DB::table('b2c_customers')
                ->where('user_id', $userId)
                ->where('store_id', $storeId)
                ->exists();
            $driver = DB::table('drivers')
                ->where('user_id', $userId)
                ->where('store_id', $storeId)
                ->where('driver_type', 'b2c')
                ->exists();
            $storeUser = DB::table('user_store_roles')
                ->where('user_id', $userId)
                ->where('store_id', $storeId)
                ->exists();

            $allowed = match ($app) {
                'customer' => $customer,
                'driver' => $driver,
                default => $customer || $driver || $storeUser,
            };

            abort_unless($allowed, 404);

            return;
        }

        if ($target === 'b2b') {
            $customer = DB::table('b2b_customers')->where('user_id', $userId)->exists();
            $driverQuery = DB::table('drivers')
                ->where('user_id', $userId)
                ->where('driver_type', 'b2b');
            if ($storeId !== null) {
                $driverQuery->where('store_id', $storeId);
            }
            $driver = $driverQuery->exists();

            $van = User::query()->find($userId)?->hasPermission('van.login') ?? false;

            $allowed = match ($app) {
                'customer' => $customer,
                'driver' => $driver,
                'van' => $van,
                default => $customer || $driver || $van,
            };
            abort_unless($allowed, 404);
        }
    }

    /** @return list<int> */
    private function allowedB2cStoreIds(User $actor): array
    {
        $ids = app(OperationalTenantScope::class)->allowedStoreIds($actor, 'notifications.manage', 'b2c');

        return DB::table('stores')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->where('advertising_enabled', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @return list<array{id: int, name: string, channel: string}> */
    private function allowedStores(User $actor, string $channel): array
    {
        $ids = app(OperationalTenantScope::class)->allowedStoreIds(
            $actor,
            'notifications.manage',
            $channel,
        );

        return DB::table('stores')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->when($channel === 'b2c', fn ($query) => $query->where('advertising_enabled', true))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($store): array => [
                'id' => (int) $store->id,
                'name' => (string) $store->name,
                'channel' => $channel,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'body_ar' => ['required', 'string', 'max:5000'],
            'body_en' => ['required', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'audience' => ['required', Rule::in(['all', 'customer', 'driver', 'van', 'user'])],
            'app' => ['required', Rule::in(['all', 'customer', 'driver', 'van'])],
            'target_channel' => ['required', Rule::in(['all', 'b2b', 'b2c'])],
            'delivery_channel' => ['required', Rule::in(['in_app', 'push', 'both'])],
            'popup_frequency' => ['required', Rule::in(['once_per_user', 'once_per_session', 'every_open'])],
            'popup_cta_label_ar' => ['nullable', 'string', 'max:120'],
            'popup_cta_label_en' => ['nullable', 'string', 'max:120'],
            'popup_cta_target' => ['nullable', 'string', 'max:500', 'starts_with:/'],
            'user_id' => ['nullable', 'required_if:audience,user', 'integer', 'exists:users,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'schedule_kind' => ['required', Rule::in(['once', 'recurring'])],
            'starts_at' => ['required', 'date'],
            'interval_value' => ['nullable', 'required_if:schedule_kind,recurring', 'integer', 'min:1', 'max:10000'],
            'interval_unit' => ['nullable', 'required_if:schedule_kind,recurring', Rule::in(['minute', 'hour', 'day', 'week', 'month'])],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_runs' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function scheduleData(array $data): array
    {
        $startsAt = CarbonImmutable::parse((string) $data['starts_at'], self::TIMEZONE)->utc();
        $endsAt = isset($data['ends_at'])
            ? CarbonImmutable::parse((string) $data['ends_at'], self::TIMEZONE)->utc()
            : null;

        return [
            'schedule_kind' => $data['schedule_kind'],
            'timezone' => self::TIMEZONE,
            'starts_at' => $startsAt,
            'interval_value' => $data['schedule_kind'] === 'recurring' ? (int) $data['interval_value'] : null,
            'interval_unit' => $data['schedule_kind'] === 'recurring' ? $data['interval_unit'] : null,
            'ends_at' => $endsAt,
            'max_runs' => isset($data['max_runs']) ? (int) $data['max_runs'] : null,
            'next_run_at' => $startsAt,
        ];
    }

    private function storeImage(Request $request, MarketingImageService $images, mixed $storeId): ?string
    {
        $file = $request->file('image');
        if (! $file instanceof UploadedFile) {
            return null;
        }

        return $images->store($file, 'campaigns', is_numeric($storeId) ? (int) $storeId : null);
    }
}
