<?php

namespace App\Domain\WhatsApp;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Support\Facades\DB;

final class TransportRetryService
{
    public function __construct(private readonly RuntimePolicy $policy) {}

    public function dispatchDue(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $count = 0;
        $queue = (string) config('whatsapp.transport.queue', 'whatsapp');
        $state = $this->policy->state();

        if (! $this->policy->isOperational($state)) {
            return 0;
        }

        $outboundEnabled = ! $state->emergencyStop && $state->has(Capability::OUTBOUND);
        $inboundEnabled = $state->has(Capability::INBOUND);

        $outbound = [];
        if ($outboundEnabled) {
            $outbound = DB::table('whats_app_outbound_dispatches')
                ->where('status', 'retry_wait')
                ->whereNotNull('next_retry_at')
                ->where('next_retry_at', '<=', now())
                ->orderBy('next_retry_at')
                ->limit($limit)
                ->pluck('id')
                ->all();
        }

        foreach ($outbound as $id) {
            SendWhatsAppMessage::dispatch((int) $id)->onQueue($queue);
            $count++;
        }

        $remaining = max(0, $limit - $count);
        if ($remaining === 0) {
            return $count;
        }

        if (! $inboundEnabled) {
            return $count;
        }

        $webhooks = DB::table('whats_app_webhook_events')
            ->where('status', 'retry_wait')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit($remaining)
            ->pluck('id');

        foreach ($webhooks as $id) {
            ProcessWhatsAppWebhook::dispatch((int) $id)->onQueue($queue);
            $count++;
        }

        return $count;
    }
}
