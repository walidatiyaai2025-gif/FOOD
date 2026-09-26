<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PushDeliveryService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeManage($request);
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        $notifications = Notification::query()
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('title_ar', 'like', '%'.$search.'%')
                    ->orWhere('title_en', 'like', '%'.$search.'%')
                    ->orWhere('body_ar', 'like', '%'.$search.'%')
                    ->orWhere('body_en', 'like', '%'.$search.'%');
            }))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.notifications', compact('notifications', 'search', 'status'));
    }

    public function live(Request $request): JsonResponse
    {
        $user = $this->dashboardUser($request);
        $locale = in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar';
        $afterId = max(0, (int) $request->query('after_id', 0));

        $visible = $this->dashboardNotifications($user);
        $unreadCount = (clone $visible)
            ->whereNotIn('notifications.id', NotificationRead::query()
                ->where('user_id', $user->id)
                ->select('notification_id'))
            ->count();

        $notifications = (clone $visible)
            ->when($afterId > 0, fn ($query) => $query->where('notifications.id', '>', $afterId))
            ->latest('notifications.id')
            ->limit(20)
            ->get();

        $readIds = NotificationRead::query()
            ->where('user_id', $user->id)
            ->whereIn('notification_id', $notifications->pluck('id'))
            ->pluck('notification_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return response()->json([
            'data' => $notifications->map(fn (Notification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $locale === 'en' ? $notification->title_en : $notification->title_ar,
                'body' => $locale === 'en' ? $notification->body_en : $notification->body_ar,
                'data' => $notification->data,
                'read' => in_array($notification->id, $readIds, true),
                'published_at' => optional($notification->published_at)->toAtomString(),
            ])->values(),
            'meta' => [
                'unread_count' => $unreadCount,
                'latest_id' => (int) ($notifications->max('id') ?? $afterId),
            ],
        ]);
    }

    public function markRead(
        Request $request,
        Notification $notification,
    ): Response {
        $user = $this->dashboardUser($request);
        abort_unless($this->dashboardNotifications($user)->whereKey($notification->id)->exists(), 404);

        NotificationRead::query()->updateOrCreate(
            ['notification_id' => $notification->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );

        return response()->noContent();
    }

    public function markAllRead(Request $request): Response
    {
        $user = $this->dashboardUser($request);
        $now = now();
        $rows = $this->dashboardNotifications($user)
            ->pluck('notifications.id')
            ->map(fn ($notificationId) => [
                'notification_id' => (int) $notificationId,
                'user_id' => $user->id,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            NotificationRead::query()->upsert(
                $rows,
                ['notification_id', 'user_id'],
                ['read_at', 'updated_at'],
            );
        }

        return response()->noContent();
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $data = $this->validated($request);

        $notification = Notification::query()->create([
            ...$data,
            'title' => $data['title_ar'],
            'body' => $data['body_ar'],
            'user_id' => $data['audience'] === 'user' ? ($data['user_id'] ?? null) : null,
            'created_by' => $actor->id,
            'status' => 'draft',
            'published_at' => null,
        ]);

        $audit->record('notification.created', $actor, $notification, null, $notification->toArray(), $request);

        return back()->with('status', __('notifications.created'));
    }

    public function update(Request $request, Notification $notification, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $data = $this->validated($request);
        $before = $notification->toArray();

        $notification->update([
            ...$data,
            'title' => $data['title_ar'],
            'body' => $data['body_ar'],
            'user_id' => $data['audience'] === 'user' ? ($data['user_id'] ?? null) : null,
        ]);

        $audit->record('notification.updated', $actor, $notification, $before, $notification->fresh()->toArray(), $request);

        return back()->with('status', __('notifications.updated'));
    }

    public function publish(Request $request, Notification $notification, AuditLogger $audit, PushDeliveryService $push): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $before = $notification->toArray();
        $notification->update(['status' => 'published', 'published_at' => now()]);
        $audit->record('notification.published', $actor, $notification, $before, $notification->fresh()->toArray(), $request);
        $push->dispatchNotification($notification->fresh());

        return back()->with('status', __('notifications.published'));
    }

    public function destroy(Request $request, Notification $notification, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $before = $notification->toArray();
        $audit->record('notification.deleted', $actor, $notification, $before, null, $request);
        $notification->delete();

        return back()->with('status', __('notifications.deleted'));
    }

    /** @return Builder<Notification> */
    private function dashboardNotifications(User $user): Builder
    {
        return Notification::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereIn('app', ['all', 'dashboard'])
            ->where('target_channel', 'all')
            ->where(function (Builder $audience) use ($user): void {
                $audience->where('audience', 'all')
                    ->orWhere('user_id', $user->id);
            });
    }

    private function dashboardUser(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function authorizeManage(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->hasPermission('notifications.manage'), 403);

        return $actor;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'body_ar' => ['required', 'string', 'max:5000'],
            'body_en' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'string', 'max:64'],
            'audience' => ['required', 'in:all,customer,driver,user'],
            'app' => ['required', 'in:all,customer,driver,dashboard'],
            'target_channel' => ['required', 'in:all,b2c,b2b'],
            'channel' => ['required', 'in:in_app,push,both'],
            'user_id' => ['nullable', 'required_if:audience,user', 'integer', 'exists:users,id'],
        ]);
    }
}
