<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\PushDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchPushNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $notificationId) {}

    public function handle(PushDeliveryService $push): void
    {
        $notification = Notification::query()->find($this->notificationId);

        if (! $notification instanceof Notification || $notification->status !== 'published') {
            return;
        }

        $push->dispatchNotification($notification, true);
    }
}
