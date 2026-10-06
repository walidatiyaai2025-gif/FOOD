<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MarketingImageService;
use App\Services\OperationalTenantScope;
use App\Services\PushDeliveryService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
                'image_url' => $this->assetUrl($notification->image_path),
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

    public function store(
        Request $request,
        AuditLogger $audit,
        MarketingImageService $images,
    ): RedirectResponse {
        $actor = $this->authorizeManage($request);
        $data = $this->validated($request);
        $this->assertScope($actor, $data);
        $imagePath = $this->storeImage($request, $images, $data['store_id'] ?? null);
        unset($data['image']);

        try {
            $notification = Notification::query()->create([
                ...$data,
                'image_path' => $imagePath,
                'title' => $data['title_ar'],
                'body' => $data['body_ar'],
                'user_id' => $data['audience'] === 'user' ? ($data['user_id'] ?? null) : null,
                'created_by' => $actor->id,
                'status' => 'draft',
                'published_at' => null,
            ]);
        } catch (Throwable $exception) {
            $images->delete($imagePath);
            throw $exception;
        }

        $audit->record('notification.created', $actor, $notification, null, $notification->toArray(), $request);

        return back()->with('status', __('notifications.created'));
    }

    public function update(
        Request $request,
        Notification $notification,
        AuditLogger $audit,
        MarketingImageService $images,
    ): RedirectResponse {
        $actor = $this->authorizeManage($request);
        $data = $this->validated($request);
        $this->assertScope($actor, $data);
        $before = $notification->toArray();
        $newImage = $request->hasFile('image')
            ? $this->storeImage($request, $images, $data['store_id'] ?? null)
            : null;
        unset($data['image']);

        try {
            $notification->update([
                ...$data,
                ...($newImage !== null ? ['image_path' => $newImage] : []),
                'title' => $data['title_ar'],
                'body' => $data['body_ar'],
                'user_id' => $data['audience'] === 'user' ? ($data['user_id'] ?? null) : null,
            ]);
        } catch (Throwable $exception) {
            $images->delete($newImage);
            throw $exception;
        }

        if ($newImage !== null) {
            $images->delete($before['image_path'] ?? null);
        }

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

    public function destroy(
        Request $request,
        Notification $notification,
        AuditLogger $audit,
        MarketingImageService $images,
    ): RedirectResponse {
        $actor = $this->authorizeManage($request);
        $before = $notification->toArray();
        $imagePath = $notification->image_path;
        $audit->record('notification.deleted', $actor, $notification, $before, null, $request);
        $notification->delete();
        $images->delete($imagePath);

        return back()->with('status', __('notifications.deleted'));
    }

    /** @return Builder<Notification> */
    private function dashboardNotifications(User $user): Builder
    {
        $storeIds = collect(['orders.view', 'finance.view', 'notifications.view'])
            ->flatMap(fn (string $permission): array => app(OperationalTenantScope::class)
                ->allowedStoreIds($user, $permission))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $channels = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereIn('stores.id', $storeIds)
            ->pluck('store_types.code')
            ->map(static fn ($code): string => strtolower((string) $code))
            ->unique()
            ->values()
            ->all();

        return Notification::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereIn('app', ['all', 'dashboard'])
            ->where(function (Builder $target) use ($user, $channels): void {
                $target->where('target_channel', 'all')
                    ->orWhere('user_id', $user->id);

                if ($channels !== []) {
                    $target->orWhereIn('target_channel', $channels);
                }
            })
            ->where(function (Builder $scope) use ($user, $storeIds): void {
                $scope->whereNull('store_id')
                    ->orWhere('user_id', $user->id);

                if ($storeIds !== []) {
                    $scope->orWhereIn('store_id', $storeIds);
                }
            })
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
        abort_unless($actor->hasRole('SUPER_ADMIN') && $actor->hasPermission('notifications.manage'), 403);

        return $actor;
    }

    /** @param array<string, mixed> $data */
    private function assertScope(User $actor, array $data): void
    {
        if (($data['store_id'] ?? null) === null) {
            return;
        }

        $targetChannel = (string) $data['target_channel'];
        app(OperationalTenantScope::class)->assertStore(
            $actor,
            (int) $data['store_id'],
            'notifications.manage',
            in_array($targetChannel, ['b2b', 'b2c'], true) ? $targetChannel : null,
        );
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'body_ar' => ['required', 'string', 'max:5000'],
            'body_en' => ['required', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'type' => ['required', 'string', 'max:64'],
            'audience' => ['required', 'in:all,customer,driver,van,user'],
            'app' => ['required', 'in:all,customer,driver,van,dashboard'],
            'target_channel' => ['required', 'in:all,b2c,b2b'],
            'channel' => ['required', 'in:in_app,push,both'],
            'user_id' => ['nullable', 'required_if:audience,user', 'integer', 'exists:users,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);
    }

    private function storeImage(Request $request, MarketingImageService $images, mixed $storeId): ?string
    {
        $file = $request->file('image');
        if (! $file instanceof UploadedFile) {
            return null;
        }

        return $images->store($file, 'notifications', is_numeric($storeId) ? (int) $storeId : null);
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = trim($path);

        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://')
            ? $value
            : url('/'.ltrim($value, '/'));
    }
}
