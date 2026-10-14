<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;

final class CampaignSendPolicy
{
    public function __construct(private readonly MarketingConsentService $consent) {}

    public function assertRecipientCanSend(int $recipientId, string $recipientPhone): void
    {
        $pauseReason = null;

        DB::transaction(function () use ($recipientId, $recipientPhone, &$pauseReason): void {
            $recipient = DB::table('whats_app_campaign_recipients')
                ->where('id', $recipientId)
                ->lockForUpdate()
                ->first();

            if ($recipient === null || $recipient->eligibility_status !== 'eligible'
                || ! in_array($recipient->send_status, ['pending', 'queued'], true)) {
                $pauseReason = 'whatsapp.campaign_recipient_not_eligible';

                return;
            }

            $campaign = DB::table('whats_app_campaigns')
                ->where('id', $recipient->campaign_id)
                ->lockForUpdate()
                ->first();

            if ($campaign === null || $campaign->status !== 'running') {
                $pauseReason = 'whatsapp.campaign_not_running';

                return;
            }

            $template = DB::table('whats_app_templates')->where('id', $campaign->template_id)->first();
            if ($template === null || ! $template->is_active || $template->provider_status !== 'approved') {
                $this->suppress($recipientId, 'template_unavailable');
                $pauseReason = 'whatsapp.campaign_template_unavailable';

                return;
            }

            $normalized = $this->consent->normalizePhone($recipientPhone);
            if ($normalized === null || $normalized !== $recipient->phone) {
                $this->suppress($recipientId, 'invalid_phone');
                $pauseReason = 'whatsapp.campaign_invalid_phone';

                return;
            }

            DB::table('platform_customers')
                ->where('id', $recipient->platform_customer_id)
                ->lockForUpdate()
                ->first(['id']);

            if (! $this->consent->isEligible((int) $recipient->platform_customer_id, $normalized)) {
                $state = $this->consent->state((int) $recipient->platform_customer_id);
                $this->suppress($recipientId, $state === MarketingConsentService::OPTED_OUT ? 'opted_out' : 'no_consent');
                $pauseReason = 'whatsapp.campaign_consent_required';

                return;
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
                    $pauseReason = 'whatsapp.campaign_test_allowlist';

                    return;
                }
            }
        }, 3);

        if ($pauseReason !== null) {
            throw new OutboundPaused($pauseReason);
        }
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
