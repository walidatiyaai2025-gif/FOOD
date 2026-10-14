<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChatbotCampaignAttributionService
{
    /** @return array{campaign_id:int,recipient_id:int}|null */
    public function latestForCustomer(?int $platformCustomerId): ?array
    {
        if ($platformCustomerId === null
            || ! Schema::hasTable('whats_app_campaign_recipients')
            || ! Schema::hasTable('whats_app_campaigns')) {
            return null;
        }

        $recipient = DB::table('whats_app_campaign_recipients as r')
            ->join('whats_app_campaigns as c', 'c.id', '=', 'r.campaign_id')
            ->where('r.platform_customer_id', $platformCustomerId)
            ->whereNotNull('r.sent_at')
            ->where('r.sent_at', '>=', now()->subDays(7))
            ->whereNotIn('c.status', ['cancelled'])
            ->orderByDesc('r.sent_at')
            ->select('r.id', 'r.campaign_id')
            ->first();

        if ($recipient === null) {
            return null;
        }

        return [
            'campaign_id' => (int) $recipient->campaign_id,
            'recipient_id' => (int) $recipient->id,
        ];
    }
}
