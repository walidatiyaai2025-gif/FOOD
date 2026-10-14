<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;

final class CampaignReplyRecorder
{
    public function __construct(private readonly MarketingConsentService $consent) {}

    public function record(
        ?int $platformCustomerId,
        int $conversationId,
        ?string $body,
    ): void {
        if ($platformCustomerId === null || $this->consent->isOptOutText($body)) {
            return;
        }

        DB::transaction(function () use ($platformCustomerId, $conversationId): void {
            $recipient = DB::table('whats_app_campaign_recipients as r')
                ->join('whats_app_campaigns as c', 'c.id', '=', 'r.campaign_id')
                ->where('r.platform_customer_id', $platformCustomerId)
                ->whereNotNull('r.sent_at')
                ->whereNull('r.replied_at')
                ->whereNotIn('c.status', ['cancelled'])
                ->orderByDesc('r.sent_at')
                ->lockForUpdate()
                ->select('r.*')
                ->first();

            if ($recipient === null) {
                return;
            }

            $now = now();
            DB::table('whats_app_campaign_recipients')->where('id', $recipient->id)->update([
                'replied_at' => $now,
                'updated_at' => $now,
            ]);

            $agentId = DB::table('whats_app_customer_assignments')
                ->where('platform_customer_id', $platformCustomerId)
                ->where('is_active', true)
                ->value('primary_agent_id');

            DB::table('whats_app_campaign_followups')->insertOrIgnore([
                'campaign_recipient_id' => $recipient->id,
                'conversation_id' => $conversationId,
                'assigned_agent_id' => $agentId,
                'status' => 'open',
                'reason' => 'campaign_reply',
                'due_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 3);
    }
}
