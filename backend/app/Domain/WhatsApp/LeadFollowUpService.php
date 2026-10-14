<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeadFollowUpService
{
    public const TEMPERATURES = ['cold', 'warm', 'hot'];

    public const LEAD_STATUSES = ['open', 'converted', 'lost'];

    public const FOLLOW_UP_STATUSES = ['open', 'completed', 'cancelled'];

    public function saveLead(User $actor, array $data): int
    {
        $customerId = (int) $data['platform_customer_id'];
        $customer = DB::table('platform_customers')->where('id', $customerId)->first();
        abort_unless($customer !== null, 404);

        $requestedStoreId = isset($data['store_id']) ? (int) $data['store_id'] : null;
        $conversationId = isset($data['conversation_id']) ? (int) $data['conversation_id'] : null;
        $storeId = $this->resolveStoreContext($customerId, $requestedStoreId, $conversationId);

        $this->assertSupervisor($actor, $storeId);
        $this->assertAgentScope(
            isset($data['assigned_agent_id']) ? (int) $data['assigned_agent_id'] : null,
            $storeId,
        );

        $temperature = strtolower((string) ($data['temperature'] ?? 'cold'));
        $status = strtolower((string) ($data['status'] ?? 'open'));
        if (! in_array($temperature, self::TEMPERATURES, true)
            || ! in_array($status, self::LEAD_STATUSES, true)) {
            throw ValidationException::withMessages(['lead' => ['Invalid lead state.']]);
        }

        return DB::transaction(function () use ($actor, $data, $customerId, $storeId, $temperature, $status): int {
            $existing = DB::table('whats_app_leads')
                ->where('platform_customer_id', $customerId)
                ->lockForUpdate()
                ->first();
            $now = now();

            if ($existing === null) {
                $leadId = (int) DB::table('whats_app_leads')->insertGetId([
                    'platform_customer_id' => $customerId,
                    'store_id' => $storeId,
                    'conversation_id' => $data['conversation_id'] ?? null,
                    'assigned_agent_id' => $data['assigned_agent_id'] ?? null,
                    'temperature' => $temperature,
                    'status' => $status,
                    'source_type' => (string) ($data['source_type'] ?? 'manual'),
                    'source_id' => isset($data['source_id']) ? (string) $data['source_id'] : null,
                    'source_label' => $data['source_label'] ?? null,
                    'opened_at' => $now,
                    'last_activity_at' => $now,
                    'converted_at' => $status === 'converted' ? $now : null,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->event('lead_created', $customerId, $storeId, $data['conversation_id'] ?? null, $actor->getKey(), (string) ($data['source_type'] ?? 'manual'), $data['source_id'] ?? null, [
                    'lead_id' => $leadId,
                    'temperature' => $temperature,
                    'status' => $status,
                ]);

                return $leadId;
            }

            $changes = [
                'store_id' => $storeId,
                'conversation_id' => $data['conversation_id'] ?? $existing->conversation_id,
                'assigned_agent_id' => $data['assigned_agent_id'] ?? $existing->assigned_agent_id,
                'temperature' => $temperature,
                'status' => $status,
                'source_type' => $data['source_type'] ?? $existing->source_type,
                'source_id' => array_key_exists('source_id', $data) ? $data['source_id'] : $existing->source_id,
                'source_label' => array_key_exists('source_label', $data) ? $data['source_label'] : $existing->source_label,
                'last_activity_at' => $now,
                'converted_at' => $status === 'converted' ? ($existing->converted_at ?? $now) : null,
                'updated_by' => $actor->getKey(),
                'updated_at' => $now,
            ];

            DB::table('whats_app_leads')->where('id', $existing->id)->update($changes);

            if ((string) $existing->temperature !== $temperature) {
                $this->event('lead_temperature_changed', $customerId, $storeId, $changes['conversation_id'], $actor->getKey(), (string) $changes['source_type'], $changes['source_id'], [
                    'lead_id' => (int) $existing->id,
                    'from' => (string) $existing->temperature,
                    'to' => $temperature,
                ]);
            }
            if ((string) $existing->status !== $status) {
                $this->event('lead_status_changed', $customerId, $storeId, $changes['conversation_id'], $actor->getKey(), (string) $changes['source_type'], $changes['source_id'], [
                    'lead_id' => (int) $existing->id,
                    'from' => (string) $existing->status,
                    'to' => $status,
                ]);
            }

            return (int) $existing->id;
        }, 3);
    }

    public function recordCampaignReply(
        int $platformCustomerId,
        int $conversationId,
        int $campaignId,
        int $campaignRecipientId,
        ?int $assignedAgentId,
    ): void {
        $customer = DB::table('platform_customers')->where('id', $platformCustomerId)->first();
        if ($customer === null) {
            return;
        }

        $storeId = $customer->origin_store_id === null ? null : (int) $customer->origin_store_id;
        $now = now();

        $lead = DB::table('whats_app_leads')
            ->where('platform_customer_id', $platformCustomerId)
            ->lockForUpdate()
            ->first();

        if ($lead === null) {
            $leadId = (int) DB::table('whats_app_leads')->insertGetId([
                'platform_customer_id' => $platformCustomerId,
                'store_id' => $storeId,
                'conversation_id' => $conversationId,
                'assigned_agent_id' => $assignedAgentId,
                'temperature' => 'warm',
                'status' => 'open',
                'source_type' => 'campaign',
                'source_id' => (string) $campaignId,
                'source_label' => 'campaign_reply',
                'opened_at' => $now,
                'last_activity_at' => $now,
                'converted_at' => null,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->event('lead_created', $platformCustomerId, $storeId, $conversationId, $assignedAgentId, 'campaign', (string) $campaignId, [
                'lead_id' => $leadId,
                'temperature' => 'warm',
                'campaign_recipient_id' => $campaignRecipientId,
            ]);
        } else {
            $leadId = (int) $lead->id;
            $temperature = (string) $lead->temperature === 'cold' ? 'warm' : (string) $lead->temperature;
            DB::table('whats_app_leads')->where('id', $leadId)->update([
                'conversation_id' => $conversationId,
                'assigned_agent_id' => $assignedAgentId ?? $lead->assigned_agent_id,
                'temperature' => $temperature,
                'last_activity_at' => $now,
                'updated_at' => $now,
            ]);
            if ((string) $lead->temperature !== $temperature) {
                $this->event('lead_temperature_changed', $platformCustomerId, $storeId, $conversationId, $assignedAgentId, 'campaign', (string) $campaignId, [
                    'lead_id' => $leadId,
                    'from' => (string) $lead->temperature,
                    'to' => $temperature,
                ]);
            }
        }

        $sourceId = (string) $campaignRecipientId;
        $exists = DB::table('whats_app_follow_ups')
            ->where('source_type', 'campaign_recipient')
            ->where('source_id', $sourceId)
            ->whereIn('status', ['open', 'completed'])
            ->exists();

        if (! $exists) {
            $followUpId = (int) DB::table('whats_app_follow_ups')->insertGetId([
                'platform_customer_id' => $platformCustomerId,
                'lead_id' => $leadId,
                'conversation_id' => $conversationId,
                'store_id' => $storeId,
                'assigned_agent_id' => $assignedAgentId,
                'title' => 'Campaign reply follow-up',
                'notes' => null,
                'status' => 'open',
                'priority' => 'high',
                'due_at' => $now,
                'completed_at' => null,
                'source_type' => 'campaign_recipient',
                'source_id' => $sourceId,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->event('followup_created', $platformCustomerId, $storeId, $conversationId, $assignedAgentId, 'campaign_recipient', $sourceId, [
                'follow_up_id' => $followUpId,
                'lead_id' => $leadId,
                'campaign_id' => $campaignId,
            ]);
        }
    }

    public function createFollowUp(User $actor, array $data): int
    {
        $customerId = isset($data['platform_customer_id']) ? (int) $data['platform_customer_id'] : null;
        $requestedStoreId = isset($data['store_id']) ? (int) $data['store_id'] : null;
        $conversationId = isset($data['conversation_id']) ? (int) $data['conversation_id'] : null;

        if ($customerId === null && isset($data['lead_id'])) {
            $lead = DB::table('whats_app_leads')->where('id', (int) $data['lead_id'])->first();
            abort_unless($lead !== null, 404);
            $customerId = (int) $lead->platform_customer_id;
            $requestedStoreId ??= $lead->store_id === null ? null : (int) $lead->store_id;
        }

        if ($customerId === null && $conversationId !== null) {
            $value = DB::table('whats_app_conversations')
                ->where('id', $conversationId)
                ->value('platform_customer_id');
            $customerId = $value === null ? null : (int) $value;
        }

        if ($customerId === null) {
            throw ValidationException::withMessages([
                'platform_customer_id' => [__('whatsapp_supervisor.errors.customer_context_required')],
            ]);
        }

        $storeId = $this->resolveStoreContext($customerId, $requestedStoreId, $conversationId);
        $this->assertSupervisor($actor, $storeId);
        $this->assertAgentScope(
            isset($data['assigned_agent_id']) ? (int) $data['assigned_agent_id'] : null,
            $storeId,
        );
        $now = now();

        $id = (int) DB::table('whats_app_follow_ups')->insertGetId([
            'platform_customer_id' => $customerId,
            'lead_id' => $data['lead_id'] ?? null,
            'conversation_id' => $data['conversation_id'] ?? null,
            'store_id' => $storeId,
            'assigned_agent_id' => $data['assigned_agent_id'] ?? null,
            'title' => trim((string) $data['title']),
            'notes' => $data['notes'] ?? null,
            'status' => 'open',
            'priority' => (string) ($data['priority'] ?? 'normal'),
            'due_at' => $data['due_at'] ?? null,
            'completed_at' => null,
            'source_type' => (string) ($data['source_type'] ?? 'manual'),
            'source_id' => isset($data['source_id']) ? (string) $data['source_id'] : null,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->event('followup_created', $customerId, $storeId, $data['conversation_id'] ?? null, $data['assigned_agent_id'] ?? null, (string) ($data['source_type'] ?? 'manual'), $data['source_id'] ?? null, [
            'follow_up_id' => $id,
            'title' => trim((string) $data['title']),
            'due_at' => $data['due_at'] ?? null,
        ]);

        return $id;
    }

    public function transitionFollowUp(User $actor, int $followUpId, string $status): void
    {
        $status = strtolower($status);
        if (! in_array($status, self::FOLLOW_UP_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => ['Invalid follow-up status.']]);
        }

        $followUp = DB::table('whats_app_follow_ups')->where('id', $followUpId)->first();
        abort_unless($followUp !== null, 404);
        $storeId = $followUp->store_id === null ? null : (int) $followUp->store_id;
        $this->assertSupervisor($actor, $storeId);

        DB::table('whats_app_follow_ups')->where('id', $followUpId)->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
            'updated_by' => $actor->getKey(),
            'updated_at' => now(),
        ]);

        $this->event('followup_'.$status, $followUp->platform_customer_id, $storeId, $followUp->conversation_id, $actor->getKey(), (string) $followUp->source_type, $followUp->source_id, [
            'follow_up_id' => $followUpId,
            'from' => (string) $followUp->status,
            'to' => $status,
        ]);
    }

    public function recordOutcome(User $actor, int $conversationId, string $outcome, ?string $notes = null): void
    {
        $conversation = DB::table('whats_app_conversations')->where('id', $conversationId)->first();
        abort_unless($conversation !== null, 404);
        $storeId = $conversation->store_id === null ? null : (int) $conversation->store_id;
        $this->assertSupervisor($actor, $storeId);

        $now = now();
        DB::table('whats_app_conversation_outcomes')->updateOrInsert(
            ['conversation_id' => $conversationId],
            [
                'platform_customer_id' => $conversation->platform_customer_id,
                'store_id' => $storeId,
                'outcome_code' => trim($outcome),
                'notes' => $notes,
                'recorded_by' => $actor->getKey(),
                'recorded_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $this->event('conversation_outcome_recorded', $conversation->platform_customer_id, $storeId, $conversationId, $actor->getKey(), 'conversation', (string) $conversationId, [
            'outcome_code' => trim($outcome),
            'notes' => $notes,
        ]);
    }

    private function resolveStoreContext(
        int $customerId,
        ?int $requestedStoreId,
        ?int $conversationId,
    ): ?int {
        $customer = DB::table('platform_customers')->where('id', $customerId)->first();
        abort_unless($customer !== null, 404);

        $originStoreId = $customer->origin_store_id === null ? null : (int) $customer->origin_store_id;
        $conversationStoreId = null;

        if ($conversationId !== null) {
            $conversation = DB::table('whats_app_conversations')->where('id', $conversationId)->first();
            abort_unless($conversation !== null, 404);

            if ((int) $conversation->platform_customer_id !== $customerId) {
                throw ValidationException::withMessages([
                    'conversation_id' => [__('whatsapp_supervisor.errors.context_scope')],
                ]);
            }

            $conversationStoreId = $conversation->store_id === null ? null : (int) $conversation->store_id;
        }

        $storeId = $requestedStoreId ?? $conversationStoreId ?? $originStoreId;

        foreach ([$requestedStoreId, $conversationStoreId, $originStoreId] as $candidate) {
            if ($candidate !== null && $storeId !== null && $candidate !== $storeId) {
                throw ValidationException::withMessages([
                    'store_id' => [__('whatsapp_supervisor.errors.context_scope')],
                ]);
            }
        }

        return $storeId;
    }

    private function assertAgentScope(?int $agentId, ?int $storeId): void
    {
        if ($agentId === null) {
            return;
        }

        $agent = User::query()->whereKey($agentId)->where('is_active', true)->first();
        if (! $agent instanceof User
            || ! $agent->hasPermission('whatsapp.conversations.view', $storeId)) {
            throw ValidationException::withMessages([
                'assigned_agent_id' => [__('whatsapp.inbox.errors.agent_scope')],
            ]);
        }
    }

    private function assertSupervisor(User $actor, ?int $storeId): void
    {
        abort_unless(
            $actor->hasRole('SUPER_ADMIN')
                || $actor->hasPermission('whatsapp.customer_service.supervise', $storeId),
            403,
        );
    }

    private function event(
        string $type,
        mixed $customerId,
        ?int $storeId,
        mixed $conversationId,
        mixed $agentId,
        ?string $sourceType,
        mixed $sourceId,
        array $payload,
    ): void {
        DB::table('whats_app_crm_events')->insert([
            'event_type' => $type,
            'platform_customer_id' => $customerId,
            'store_id' => $storeId,
            'conversation_id' => $conversationId,
            'agent_id' => $agentId,
            'source_type' => $sourceType,
            'source_id' => $sourceId === null ? null : (string) $sourceId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
