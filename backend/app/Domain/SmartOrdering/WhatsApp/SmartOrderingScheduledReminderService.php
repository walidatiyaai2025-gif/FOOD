<?php

namespace App\Domain\SmartOrdering\WhatsApp;

use App\Models\SmartOrderingOrderContext;
use Illuminate\Support\Facades\Schema;

final class SmartOrderingScheduledReminderService
{
    public function __construct(private readonly SmartOrderingWhatsAppBridge $bridge) {}

    public function dispatchDue(): int
    {
        if (! Schema::hasTable('smart_ordering_order_contexts')) {
            return 0;
        }

        $leadMinutes = max(5, min(1440, (int) config('smart_ordering.whatsapp_reminder_lead_minutes', 60)));
        $from = now()->addMinutes($leadMinutes - 2);
        $to = now()->addMinutes($leadMinutes + 2);
        $count = 0;

        SmartOrderingOrderContext::query()
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [$from, $to])
            ->whereIn('lifecycle_status', ['NEW', 'CONFIRMED', 'PREPARING'])
            ->orderBy('scheduled_for')
            ->limit(250)
            ->get()
            ->each(function (SmartOrderingOrderContext $context) use (&$count): void {
                if ($this->bridge->scheduledReminder($context) !== null) {
                    $count++;
                }
            });

        return $count;
    }
}
