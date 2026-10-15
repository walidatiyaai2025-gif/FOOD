<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CampaignService
{
    public function __construct(
        private readonly CampaignSegmentationService $segments,
        private readonly MarketingConsentService $consent,
        private readonly TemplateRegistryService $templates,
        private readonly RuntimePolicy $runtime,
        private readonly OutboundMessageDispatcher $dispatcher,
        private readonly CampaignSendPolicy $sendPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $segment
     * @param  list<string>  $testAllowlist
     * @return array<string, mixed>
     */
    public function preview(
        array $segment,
        bool $testMode,
        array $testAllowlist,
        int $maxRecipients = 1000,
        int $batchSize = 50,
        bool $templateApproved = true,
    ): array {
        $allowlist = $this->normalizeAllowlist($testAllowlist);
        $customers = $this->segments->customers($segment);
        $existingConversationCustomerIds = $this->existingConversationCustomerIds($customers->pluck('id')->all());
        $rows = [];
        $eligible = 0;
        $firstContact = 0;
        $existingContact = 0;
        $reasons = [];

        foreach ($customers as $customer) {
            $contactType = isset($existingConversationCustomerIds[(int) $customer->id])
                ? 'existing_contact'
                : 'first_contact';
            $contactType === 'first_contact' ? $firstContact++ : $existingContact++;

            [$ok, $reason, $phone] = $this->eligibility($customer, $testMode, $allowlist);
            if ($ok && ! $templateApproved) {
                $ok = false;
                $reason = 'template_policy';
            }
            if ($ok && $eligible >= max(1, $maxRecipients)) {
                $ok = false;
                $reason = 'campaign_limit';
            }
            if ($ok) {
                $eligible++;
            } else {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }

            if (count($rows) < 25) {
                $rows[] = [
                    'platform_customer_id' => (int) $customer->id,
                    'name' => $customer->name,
                    'phone' => $phone,
                    'contact_type' => $contactType,
                    'eligible' => $ok,
                    'reason' => $reason,
                ];
            }
        }

        return [
            'eligible' => $eligible,
            'suppressed' => array_sum($reasons),
            'first_contact' => $firstContact,
            'existing_contact' => $existingContact,
            'estimated_batches' => $eligible === 0 ? 0 : (int) ceil($eligible / max(1, $batchSize)),
            'suppression_reasons' => $reasons,
            'sample' => $rows,
        ];
    }

    public function snapshot(int $campaignId): array
    {
        return DB::transaction(function () use ($campaignId): array {
            $campaign = DB::table('whats_app_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            if ($campaign === null || in_array($campaign->status, ['draft', 'paused'], true) === false) {
                throw ValidationException::withMessages(['campaign' => ['Only draft or paused campaigns can be snapshotted.']]);
            }

            $segment = json_decode((string) $campaign->segment, true);
            $segment = is_array($segment) ? $segment : [];
            $allowlist = json_decode((string) ($campaign->test_allowlist ?? '[]'), true);
            $allowlist = $this->normalizeAllowlist(is_array($allowlist) ? $allowlist : []);

            DB::table('whats_app_campaign_recipients')->where('campaign_id', $campaignId)->delete();

            $customers = $this->segments->customers($segment);
            $existingConversationCustomerIds = $this->existingConversationCustomerIds($customers->pluck('id')->all());
            $eligible = 0;
            $suppressed = 0;
            $firstContact = 0;
            $existingContact = 0;
            $reasons = [];
            $now = now();

            foreach ($customers as $customer) {
                $contactType = isset($existingConversationCustomerIds[(int) $customer->id])
                    ? 'existing_contact'
                    : 'first_contact';
                $contactType === 'first_contact' ? $firstContact++ : $existingContact++;

                [$ok, $reason, $phone] = $this->eligibility($customer, (bool) $campaign->test_mode, $allowlist);
                if ($ok && $eligible >= max(1, (int) $campaign->max_recipients)) {
                    $ok = false;
                    $reason = 'campaign_limit';
                }
                $ok ? $eligible++ : $suppressed++;
                if ($ok === false) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }

                DB::table('whats_app_campaign_recipients')->insert([
                    'campaign_id' => $campaignId,
                    'platform_customer_id' => $customer->id,
                    'phone' => $phone ?? '',
                    'snapshot' => json_encode([
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'origin_channel' => $customer->origin_channel,
                        'origin_store_id' => $customer->origin_store_id,
                        'contact_type' => $contactType,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'eligibility_status' => $ok ? 'eligible' : 'suppressed',
                    'suppression_reason' => $ok ? null : $reason,
                    'send_status' => $ok ? 'pending' : 'suppressed',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'eligible' => $eligible,
                'suppressed' => $suppressed,
                'first_contact' => $firstContact,
                'existing_contact' => $existingContact,
                'reasons' => $reasons,
            ];
        }, 3);
    }

    public function schedule(int $campaignId, mixed $scheduledAt = null): void
    {
        DB::transaction(function () use ($campaignId, $scheduledAt): void {
            $campaign = DB::table('whats_app_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            if ($campaign === null || in_array($campaign->status, ['draft', 'paused'], true) === false) {
                throw ValidationException::withMessages(['campaign' => ['Campaign cannot be scheduled from its current state.']]);
            }

            $this->templates->approved((int) $campaign->template_id);

            if (DB::table('whats_app_campaign_recipients')->where('campaign_id', $campaignId)->exists() === false) {
                $this->snapshot($campaignId);
            }

            $eligible = DB::table('whats_app_campaign_recipients')
                ->where('campaign_id', $campaignId)
                ->where('eligibility_status', 'eligible')
                ->count();
            if ($eligible < 1) {
                throw ValidationException::withMessages(['campaign' => ['Campaign has no eligible recipients.']]);
            }

            DB::table('whats_app_campaigns')->where('id', $campaignId)->update([
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt ?: now(),
                'paused_at' => null,
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function dispatchDue(): int
    {
        if ($this->runtime->allowsCampaignManagement() === false || Schema::hasTable('whats_app_campaigns') === false) {
            return 0;
        }

        $ids = DB::table('whats_app_campaigns')
            ->whereIn('status', ['scheduled', 'running'])
            ->where(function ($query): void {
                $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(20)
            ->pluck('id');

        $queued = 0;
        foreach ($ids as $id) {
            $queued += $this->dispatchBatch((int) $id);
        }

        return $queued;
    }

    public function dispatchBatch(int $campaignId): int
    {
        $campaign = DB::transaction(function () use ($campaignId): ?object {
            $row = DB::table('whats_app_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            if ($row === null || in_array($row->status, ['scheduled', 'running'], true) === false
                || ($row->scheduled_at !== null && now()->lt($row->scheduled_at))) {
                return null;
            }

            if ($row->status === 'scheduled') {
                DB::table('whats_app_campaigns')->where('id', $campaignId)->update([
                    'status' => 'running',
                    'started_at' => $row->started_at ?? now(),
                    'updated_at' => now(),
                ]);
                $row->status = 'running';
            }

            return $row;
        }, 3);

        if ($campaign === null || $this->runtime->allowsCampaignManagement() === false) {
            return 0;
        }

        $recent = DB::table('whats_app_campaign_recipients')
            ->where('campaign_id', $campaignId)
            ->where('queued_at', '>=', now()->subMinute())
            ->count();
        $limit = min(
            max(1, (int) $campaign->batch_size),
            max(0, ((int) $campaign->per_minute_limit) - $recent),
        );
        if ($limit < 1) {
            return 0;
        }

        $recipientIds = DB::table('whats_app_campaign_recipients')
            ->where('campaign_id', $campaignId)
            ->where('eligibility_status', 'eligible')
            ->where('send_status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $queued = 0;
        foreach ($recipientIds as $recipientId) {
            if ($this->queueRecipient((int) $recipientId)) {
                $queued++;
            }
        }

        $pending = DB::table('whats_app_campaign_recipients')
            ->where('campaign_id', $campaignId)
            ->whereIn('send_status', ['pending', 'queued'])
            ->exists();
        if ($pending === false) {
            DB::table('whats_app_campaigns')
                ->where('id', $campaignId)
                ->where('status', 'running')
                ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        }

        return $queued;
    }

    public function pause(int $campaignId): void
    {
        $this->stopUnsent($campaignId, 'paused');
    }

    public function cancel(int $campaignId): void
    {
        $this->stopUnsent($campaignId, 'cancelled');
    }

    /** @return array<string, int> */
    public function metrics(int $campaignId): array
    {
        $base = DB::table('whats_app_campaign_recipients')->where('campaign_id', $campaignId);

        return [
            'total' => (clone $base)->count(),
            'eligible' => (clone $base)->where('eligibility_status', 'eligible')->count(),
            'suppressed' => (clone $base)->where('eligibility_status', 'suppressed')->count(),
            'queued' => (clone $base)->where('send_status', 'queued')->count(),
            'sent' => (clone $base)->whereNotNull('sent_at')->count(),
            'delivered' => (clone $base)->whereNotNull('delivered_at')->count(),
            'read' => (clone $base)->whereNotNull('read_at')->count(),
            'failed' => (clone $base)->whereNotNull('failed_at')->count(),
            'replied' => (clone $base)->whereNotNull('replied_at')->count(),
        ];
    }

    private function queueRecipient(int $recipientId): bool
    {
        $recipient = DB::table('whats_app_campaign_recipients')->where('id', $recipientId)->first();
        if ($recipient === null) {
            return false;
        }

        try {
            $this->sendPolicy->assertRecipientCanSend($recipientId, (string) $recipient->phone);
        } catch (OutboundPaused) {
            return false;
        }

        $campaign = DB::table('whats_app_campaigns')->where('id', $recipient->campaign_id)->first();
        $template = $campaign === null ? null : $this->templates->approved((int) $campaign->template_id);
        if ($campaign === null || $template === null) {
            return false;
        }

        $conversationId = $this->campaignConversation((int) $recipient->platform_customer_id);
        $now = now();
        $messageId = (int) DB::table('whats_app_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'connection_profile_id' => null,
            'direction' => 'outbound',
            'content_type' => 'template',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'body' => $template->body_preview,
            'attachment_metadata' => json_encode([
                'template_name' => $template->provider_name,
                'template_locale' => $template->locale,
                'language' => strtolower((string) $template->locale) === 'ar' ? 'ar' : 'en_US',
                'purpose' => Capability::CAMPAIGNS->value,
                'campaign_id' => $campaign->id,
                'campaign_recipient_id' => $recipientId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'provider_message_ref' => null,
            'delivery_status' => 'pending_transport',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('whats_app_campaign_recipients')
            ->where('id', $recipientId)
            ->where('send_status', 'pending')
            ->update([
                'message_id' => $messageId,
                'send_status' => 'queued',
                'queued_at' => $now,
                'updated_at' => $now,
            ]);

        $dispatchId = $this->dispatcher->queue($messageId);
        if ($dispatchId === null) {
            $this->markSuppressed($recipientId, 'transport_unavailable');

            return false;
        }

        $dispatchStatus = DB::table('whats_app_outbound_dispatches')->where('id', $dispatchId)->value('status');
        if ($dispatchStatus === 'blocked_policy') {
            $this->markSuppressed($recipientId, 'runtime_policy');

            return false;
        }

        return true;
    }

    private function campaignConversation(int $customerId): int
    {
        $existing = DB::table('whats_app_conversations')
            ->where('platform_customer_id', $customerId)
            ->where('channel', 'whatsapp')
            ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
            ->orderByDesc('id')
            ->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        $customer = DB::table('platform_customers')->where('id', $customerId)->first();
        $now = now();

        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $customerId,
            'external_participant_phone' => null,
            'store_id' => $customer?->origin_store_id,
            'channel' => 'whatsapp',
            'status' => 'WAITING_FOR_CUSTOMER',
            'primary_agent_id' => null,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => null,
            'first_inbound_at' => null,
            'first_agent_response_at' => null,
            'last_inbound_at' => null,
            'last_outbound_at' => $now,
            'resolved_at' => null,
            'reopened_at' => null,
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function stopUnsent(int $campaignId, string $target): void
    {
        DB::transaction(function () use ($campaignId, $target): void {
            $campaign = DB::table('whats_app_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            if ($campaign === null || in_array($campaign->status, ['completed', 'cancelled'], true)) {
                return;
            }

            $messageIds = DB::table('whats_app_campaign_recipients')
                ->where('campaign_id', $campaignId)
                ->where('send_status', 'queued')
                ->whereNotNull('message_id')
                ->pluck('message_id');

            if ($messageIds->isNotEmpty()) {
                DB::table('whats_app_outbound_dispatches')
                    ->whereIn('message_id', $messageIds)
                    ->whereIn('status', ['queued', 'retry_wait'])
                    ->update([
                        'status' => 'blocked_policy',
                        'last_error' => 'Campaign '.$target.' before provider send.',
                        'next_retry_at' => null,
                        'updated_at' => now(),
                    ]);
                DB::table('whats_app_messages')
                    ->whereIn('id', $messageIds)
                    ->whereIn('delivery_status', ['pending_transport', 'queued', 'retry_wait'])
                    ->update(['delivery_status' => 'blocked_policy', 'updated_at' => now()]);
            }

            $recipientUpdate = $target === 'paused'
                ? [
                    'send_status' => 'pending',
                    'message_id' => null,
                    'queued_at' => null,
                    'updated_at' => now(),
                ]
                : [
                    'eligibility_status' => 'suppressed',
                    'suppression_reason' => 'campaign_cancelled',
                    'send_status' => 'cancelled',
                    'updated_at' => now(),
                ];

            DB::table('whats_app_campaign_recipients')
                ->where('campaign_id', $campaignId)
                ->whereIn('send_status', ['pending', 'queued'])
                ->update($recipientUpdate);

            DB::table('whats_app_campaigns')->where('id', $campaignId)->update([
                'status' => $target,
                'paused_at' => $target === 'paused' ? now() : null,
                'cancelled_at' => $target === 'cancelled' ? now() : null,
                'updated_at' => now(),
            ]);
        }, 3);
    }

    /**
     * @param  list<string>  $allowlist
     * @return list<string>
     */
    private function normalizeAllowlist(array $allowlist): array
    {
        $normalized = [];
        foreach ($allowlist as $phone) {
            $value = $this->consent->normalizePhone($phone);
            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<int|string>  $customerIds
     * @return array<int, true>
     */
    private function existingConversationCustomerIds(array $customerIds): array
    {
        $existing = [];

        foreach (array_chunk(array_values(array_unique(array_map('intval', $customerIds))), 1000) as $chunk) {
            foreach (DB::table('whats_app_conversations')
                ->where('channel', 'whatsapp')
                ->whereIn('platform_customer_id', $chunk)
                ->distinct()
                ->pluck('platform_customer_id') as $customerId) {
                $existing[(int) $customerId] = true;
            }
        }

        return $existing;
    }

    /**
     * @param  list<string>  $allowlist
     * @return array{0: bool, 1: string|null, 2: string|null}
     */
    private function eligibility(object $customer, bool $testMode, array $allowlist): array
    {
        $phone = $this->consent->normalizePhone($customer->phone);
        if ($phone === null) {
            return [false, 'invalid_phone', null];
        }

        $state = $this->consent->state((int) $customer->id);
        if ($this->consent->isEligible((int) $customer->id, $phone) === false) {
            return [false, $state === MarketingConsentService::OPTED_OUT ? 'opted_out' : 'no_consent', $phone];
        }

        if ($testMode && in_array($phone, $allowlist, true) === false) {
            return [false, 'test_allowlist', $phone];
        }

        if ($this->runtime->allowsCampaigns($phone) === false) {
            return [false, 'runtime_policy', $phone];
        }

        return [true, null, $phone];
    }

    private function markSuppressed(int $recipientId, string $reason): void
    {
        DB::table('whats_app_campaign_recipients')->where('id', $recipientId)->update([
            'eligibility_status' => 'suppressed',
            'suppression_reason' => $reason,
            'send_status' => 'suppressed',
            'updated_at' => now(),
        ]);
    }
}
