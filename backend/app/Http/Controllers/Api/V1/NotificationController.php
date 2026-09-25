<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\User;
use App\Services\NotificationAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NotificationController extends Controller
{
    public function index(Request $request, NotificationAudience $audience): JsonResponse
    {
        $user = $this->user($request);
        $locale = $this->locale($request, $user);

        $page = $audience->apply(Notification::query(), $user)
            ->with(['reads' => fn ($query) => $query->where('user_id', $user->id)])
            ->latest('published_at')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Notification $item) => $this->payload($item, $locale, $user))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, Notification $notification, NotificationAudience $audience): JsonResponse
    {
        $user = $this->user($request);
        $visible = $audience->apply(Notification::query(), $user)->whereKey($notification->id)->exists();
        abort_unless($visible, 404);

        $notification->load(['reads' => fn ($query) => $query->where('user_id', $user->id)]);

        return response()->json($this->payload($notification, $this->locale($request, $user), $user));
    }

    public function markRead(Request $request, Notification $notification, NotificationAudience $audience): Response
    {
        $user = $this->user($request);
        abort_unless($audience->apply(Notification::query(), $user)->whereKey($notification->id)->exists(), 404);

        NotificationRead::query()->updateOrCreate(
            ['notification_id' => $notification->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );

        return response()->noContent();
    }

    private function payload(Notification $notification, string $locale, User $user): array
    {
        $read = $notification->relationLoaded('reads')
            ? $notification->reads->firstWhere('user_id', $user->id)
            : NotificationRead::query()->where('notification_id', $notification->id)->where('user_id', $user->id)->first();

        return [
            'id' => $notification->id,
            'channel' => $notification->target_channel,
            'type' => $notification->type,
            'title' => $locale === 'en' ? $notification->title_en : $notification->title_ar,
            'body' => $locale === 'en' ? $notification->body_en : $notification->body_ar,
            'locale' => $locale,
            'data' => $notification->data,
            'read_at' => $read?->read_at?->toISOString(),
            'published_at' => $notification->published_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }

    private function locale(Request $request, User $user): string
    {
        $locale = (string) $request->query('locale', $user->locale ?: 'ar');

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
