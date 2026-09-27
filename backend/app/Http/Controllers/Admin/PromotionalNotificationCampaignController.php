<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationCampaignDispatcher;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'b2cStores' => $this->allowedB2cStores($actor),
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'notification_campaigns',
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorizeAccess($request);
        $data = $this->validated($request);
        $this->assertScope($actor, $data);
        $schedule = $this->scheduleData($data);

        $campaign = NotificationCampaign::query()->create([
            'name' => $data['name'],
            'type' => 'promotion',
            'title_ar' => $data['title_ar'],
            'title_en' => $data['title_en'],
            'body_ar' => $data['body_ar'],
            'body_en' => $data['body_en'],
            'audience' => $data['audience'],
            'app' => $data['app'],
            'target_channel' => $data['target_channel'],
            'delivery_channel' => $data['delivery_channel'],
            'user_id' => $data['audience'] === 'user' ? ($data['user_id'] ?? null) : null,
            'store_id' => $data['store_id'] ?? null,
            'created_by' => $actor->id,
            ...$schedule,
            'status' => $request->boolean('activate') ? 'active' : 'draft',
        ]);

        $audit->record('notification_campaign.created', $actor, $campaign, null, $campaign->toArray(), $request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم حفظ الحملة الإعلانية.' : 'Promotional campaign saved.');
    }

    public function update(Request $request, NotificationCampaign $campaign, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorizeCampaign($request, $campaign);
        abort_if(in_array($campaign->status, ['completed', 'cancelled'], true), 409);
        $data = $this->validated($request);
        $this->assertScope($actor, $data);
        $before = $campaign->toArray();

        $campaign->update([
            'name' => $data['name'],
            'title_ar' => $data['title_ar'],
            'title_en' => $data['title_en'],
            'body_ar' => $data['body_ar'],
            'body_en' => $data['body_en'],
            'audience' => $data['audience'],
            'app' => $data['app'],
            'target_channel' => $data['target_channel'],
            'delivery_channel' => $data['delivery_channel'],
            'user_id' => $data['audience'] === 'user' ? ($data['user_id'] ?? null) : null,
            'store_id' => $data['store_id'] ?? null,
            ...$this->scheduleData($data),
        ]);

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
            abort_if($campaign->status === 'completed', 409);
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
        abort_if($campaign->status === 'cancelled', 409);
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

    /** @return list<int> */
    private function allowedB2cStoreIds(User $actor): array
    {
        return app(OperationalTenantScope::class)->allowedStoreIds($actor, 'notifications.manage', 'b2c');
    }

    private function allowedB2cStores(User $actor): array
    {
        $ids = $this->allowedB2cStoreIds($actor);

        return DB::table('stores')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($store): array => ['id' => (int) $store->id, 'name' => (string) $store->name])
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
            'audience' => ['required', Rule::in(['all', 'customer', 'driver', 'user'])],
            'app' => ['required', Rule::in(['all', 'customer', 'driver'])],
            'target_channel' => ['required', Rule::in(['all', 'b2b', 'b2c'])],
            'delivery_channel' => ['required', Rule::in(['in_app', 'push', 'both'])],
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
        $endsAt = isset($data['ends_at']) && $data['ends_at'] !== null
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
}
