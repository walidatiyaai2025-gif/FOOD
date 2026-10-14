<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;

final class CampaignSendPolicy
{
    public function __construct(private readonly MarketingConsentService $consent) {}

    public function assertRecipientCanSend(int $recipientId, string $recipientPhone): void
    {
        DB::transaction(function () use ($recipientId, $recipientPhone): void {
            $recipient = DB::table('whats_app_campaign_recipients')
                ->where('id', $recipientId)
                ->lockForUpdate()
                ->first();

            if ($recipient === null || $recipient->eligibility_status !== 'eligible'
                || ! in_array($recipient->send_status, ['pending', 'queued'], true)) {
                throw new OutboundPaused('whatsapp.campaign_recipient_not_eligible');
            }

            $campaign = DB::table('whats_app_campaigns')
                ->where('id', $recipient->campaign_id)
                ->lockForUpdate()
                ->first();

            if ($campaign === null || $campaign->status !== 'running') {
                throw new OutboundPaused('whatsapp.campaign_not_running');
            }

            $template = DB::table('whats_app_templates')->where('id', $campaign->template_id)->first();
            if ($template === null || ! $template->is_active || $template->provider_status !== 'approved') {
                $this->suppress($recipientId, 'template_unavailable');
                throw new OutboundPaused('whatsapp.campaign_template_unavailable');
            }

            $normalized = $this->consent->normalizePhone($recipientPhone);
            if ($normalized === null || $normalized !== $recipient->phone) {
                $this->suppress($recipientId, 'invalid_phone');
                throw new OutboundPaused('whatsapp.campaign_invalid_phone');
            }

            DB::table('platform_customers')
                ->where('id', $recipient->platform_customer_id)
                ->lockForUpdate()
                ->first(['id']);

            if (! $this->consent->isEligible((int) $recipient->platform_customer_id, $normalized)) {
                $state = $this->consent->state((int) $recipient->platform_customer_id);
                $this->suppress($recipientId, $state === MarketingConsentService::OPTED_OUT ? 'opted_out' : 'no_consent');
                throw new OutboundPaused('whatsapp.campaign_consent_required');
            }

            if ($campaign->test_mode) {
                $allowlist = json_decode((string) ($campaign->test_allowlist ?? '[]'), true);
                $allowlist = is_array($allowlist) ? $allowlist : [];
                $allowlist = array_values(array_filter(array_map(
                    fn (mixed $value): ?string => $this->consent->normalizePhone($value),
                    $allowlist,
                )));
                if (! in_array($normalized, $allowlist, true)) {
                    $this->suppress($recipientId, 'test_allowlist');
                    throw new OutboundPaused('whatsapp.campaign_test_allowlist');
                }
            }
        }, 3);
    }

    public function markSentByMessage(int $messageId): void
    {
        DB::table('whats_app_campaign_recipients')
            ->where('message_id', $messageId)
            ->whereIn('send_status', ['queued', 'pending'])
            ->update([
                'send_status' => 'sent',
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markFailedByMessage(int $messageId): void
    {
        DB::table('whats_app_campaign_recipients')
            ->where('message_id', $messageId)
            ->whereNotIn('send_status', ['delivered', 'read'])
            ->update([
                'send_status' => 'failed',
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function suppress(int $recipientId, string $reason): void
    {
        DB::table('whats_app_campaign_recipients')->where('id', $recipientId)->update([
            'eligibility_status' => 'suppressed',
            'suppression_reason' => $reason,
            'send_status' => 'suppressed',
            'updated_at' => now(),
        ]);
    }
}
