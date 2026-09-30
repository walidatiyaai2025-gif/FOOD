<?php

namespace App\Services;

use App\Models\AppPreviewInvalidationEvent;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AppPreviewInvalidationStream
{
    public function __construct(
        private readonly AppPreviewInvalidationService $invalidations,
    ) {}

    public function response(
        int $storeId,
        string $channel,
        int $lastEventId = 0,
    ): StreamedResponse {
        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 422);

        $events = AppPreviewInvalidationEvent::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->where('id', '>', max(0, $lastEventId))
            ->orderBy('id')
            ->limit(100)
            ->get();

        return response()->stream(
            function () use ($events): void {
                echo "retry: 3000\n\n";

                foreach ($events as $event) {
                    $payload = json_encode(
                        $this->invalidations->payload($event),
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

    public function lastEventId(?string $value): int
    {
        $cursor = trim((string) $value);
        if ($cursor === '') {
            return 0;
        }

        abort_unless(ctype_digit($cursor), 422, 'Last-Event-ID must be a positive integer cursor.');

        return max(0, (int) $cursor);
    }
}
