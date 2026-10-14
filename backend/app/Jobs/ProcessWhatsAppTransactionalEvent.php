<?php

namespace App\Jobs;

use App\Domain\WhatsApp\TransactionalNotificationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessWhatsAppTransactionalEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $eventId) {}

    public function handle(TransactionalNotificationEngine $engine): void
    {
        $engine->process($this->eventId);
    }
}
