<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppPreviewInvalidationEvent;
use App\Services\AppPreviewInvalidationService;
use App\Services\AppPreviewSessionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AppPreviewInvalidationController extends Controller
{
    public function __invoke(
        Request $request,
        AppPreviewSessionService $sessions,
        AppPreviewInvalidationService $invalidations,
    ): StreamedResponse {
        $token = $request->header('X-Foodex-Preview-Token');
        abort_unless(
            is_string($token) && trim($token) !== '',
            401,
            'Preview token is required.',
        );

        $session = $sessions->resolve($token, $request);
        abort_unless($session->target_type === 'customer', 404);
        abort_unless((string) $session->mode === 'read_only', 403);
        abort_unless($session->store_id !== null, 401);

        $lastEventId = $this->lastEventId($request);
        $events = AppPreviewInvalidationEvent::query()
            ->where('store_id', (int) $session->store_id)
            ->where('channel', (string) $session->channel)
            ->where('id', '>', $lastEventId)
            ->orderBy('id')
            ->limit(100)
            ->get();

        return response()->stream(
            function () use ($events, $invalidations): void {
                echo "retry: 3000\n\n";

                foreach ($events as $event) {
                    if (! $event instanceof AppPreviewInvalidationEvent) {
                        continue;
                    }

                    $payload = json_encode(
                        $invalidations->payload($event),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    );

                    echo 'id: '.(int) $event->getKey()."\n";
                    echo 'event: '.(string) $event->event_name."\n";
                    echo 'data: '.$payload."\n\n";
                }

                echo ': heartbeat '.now()->toIso8601String()."\n\n";

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function lastEventId(Request $request): int
    {
        $value = trim((string) $request->header('Last-Event-ID', ''));
        if ($value === '') {
            return 0;
        }

        abort_unless(ctype_digit($value), 422, 'Last-Event-ID must be a positive integer cursor.');

        return max(0, (int) $value);
    }
}
