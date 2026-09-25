<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

    public function publish(Request $request, Notification $notification, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorizeManage($request);
        $before = $notification->toArray();
        $notification->update(['status' => 'published', 'published_at' => now()]);
        $audit->record('notification.published', $actor, $notification, $before, $notification->fresh()->toArray(), $request);

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
            'app' => ['required', 'in:all,customer,driver'],
            'target_channel' => ['required', 'in:all,b2c,b2b'],
            'channel' => ['required', 'in:in_app,push,both'],
            'user_id' => ['nullable', 'required_if:audience,user', 'integer', 'exists:users,id'],
        ]);
    }
}
