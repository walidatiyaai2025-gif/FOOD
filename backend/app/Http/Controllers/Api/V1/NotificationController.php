<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\User;
use App\Services\NotificationAudience;
use DateTimeInterface;
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
            ->latest('published_at')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        $items = [];
        foreach ($page->items() as $item) {
            if ($item instanceof Notification) {
                $items[] = $this->payload($item, $locale, $user);
            }
        }

        return response()->json([
            'data' => $items,
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
        $read = NotificationRead::query()
            ->where('notification_id', $notification->id)
            ->where('user_id', $user->id)
            ->first();

        return [
            'id' => $notification->id,
            'channel' => $notification->channel,
            'target_channel' => $notification->target_channel,
            'type' => $notification->type,
            'title' => $locale === 'en' ? $notification->title_en : $notification->title_ar,
            'body' => $locale === 'en' ? $notification->body_en : $notification->body_ar,
            'locale' => $locale,
            'data' => $notification->data,
            'read_at' => $this->dateTime($read?->getAttribute('read_at')),
            'published_at' => $this->dateTime($notification->getAttribute('published_at')),
            'created_at' => $this->dateTime($notification->getAttribute('created_at')),
        ];
    }

    private function dateTime(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) ? $value : null;
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
