<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use App\Services\AuditLogger;
use DateTime;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConversationService
{
    public function __construct(
        private readonly AgentAssignmentEngine $assignments,
        private readonly AuditLogger $audit,
        private readonly InboundMessageAlertService $inboundAlerts,
    ) {}

    public function findOrCreateForInbound(
        ?int $platformCustomerId,
        ?int $storeId,
        string $channel = 'whatsapp',
        ?string $externalParticipantPhone = null,
        bool $autoAssign = true,
    ): int {
        return DB::transaction(function () use (
            $platformCustomerId,
            $storeId,
            $channel,
            $externalParticipantPhone,
            $autoAssign,
        ): int {
            $query = DB::table('whats_app_conversations')
                ->where('store_id', $storeId)
                ->where('channel', $channel)
                ->whereNotIn('status', ['RESOLVED', 'CLOSED']);

            if ($platformCustomerId !== null) {
                $query->where('platform_customer_id', $platformCustomerId);
            } else {
                $query->whereNull('platform_customer_id')
                    ->where('external_participant_phone', $externalParticipantPhone);
            }

            $existing = $query->lockForUpdate()->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }

            $now = now();
            $id = (int) DB::table('whats_app_conversations')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'platform_customer_id' => $platformCustomerId,
                'external_participant_phone' => $externalParticipantPhone,
                'store_id' => $storeId,
                'channel' => $channel,
                'status' => 'WAITING_FOR_AGENT',
                'primary_agent_id' => null,
                'priority' => 0,
                'unread_count' => 0,
                'waiting_since' => $now,
                'first_inbound_at' => null,
                'first_agent_response_at' => null,
                'last_inbound_at' => null,
                'last_outbound_at' => null,
                'resolved_at' => null,
                'reopened_at' => null,
                'lock_version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($autoAssign) {
                $this->autoAssign($id);
            }

            return $id;
        }, 3);
    }

    public function findOrCreateForSystem(
        ?int $platformCustomerId,
        ?int $storeId,
        string $externalParticipantPhone,
    ): int {
        return DB::transaction(function () use ($platformCustomerId, $storeId, $externalParticipantPhone): int {
            $query = DB::table('whats_app_conversations')
                ->where('store_id', $storeId)
                ->where('channel', 'whatsapp')
                ->whereNotIn('status', ['RESOLVED', 'CLOSED']);

            if ($platformCustomerId !== null) {
                $query->where('platform_customer_id', $platformCustomerId);
            } else {
                $query->whereNull('platform_customer_id')
                    ->where('external_participant_phone', $externalParticipantPhone);
            }

            $existing = $query->lockForUpdate()->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }

            $now = now();

            return (int) DB::table('whats_app_conversations')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'platform_customer_id' => $platformCustomerId,
                'external_participant_phone' => $externalParticipantPhone,
                'store_id' => $storeId,
                'channel' => 'whatsapp',
                'status' => 'WAITING_FOR_CUSTOMER',
                'primary_agent_id' => null,
                'priority' => 0,
                'unread_count' => 0,
                'waiting_since' => null,
                'first_inbound_at' => null,
                'first_agent_response_at' => null,
                'last_inbound_at' => null,
                'last_outbound_at' => null,
                'resolved_at' => null,
                'reopened_at' => null,
                'lock_version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 3);
    }

    public function appendInbound(
        int $conversationId,
        ?string $body,
        array $attachmentMetadata = [],
        ?string $providerReference = null,
        ?int $connectionProfileId = null,
        ?DateTimeInterface $occurredAt = null,
        string $contentType = 'text',
        bool $autoAssign = true,
    ): int {
        return DB::transaction(function () use (
            $conversationId,
            $body,
            $attachmentMetadata,
            $providerReference,
            $connectionProfileId,
            $occurredAt,
            $contentType,
            $autoAssign,
        ): int {
            if ($providerReference !== null && $connectionProfileId !== null) {
                $existing = DB::table('whats_app_messages')
                    ->where('connection_profile_id', $connectionProfileId)
                    ->where('provider_message_ref', $providerReference)
                    ->value('id');

                if ($existing !== null) {
                    return (int) $existing;
                }
            }

            $conversation = $this->lockConversation($conversationId);
            $now = now();
            $occurred = $occurredAt === null
                ? $now
                : Carbon::instance(
                    $occurredAt instanceof DateTime
                        ? $occurredAt
                        : DateTime::createFromInterface($occurredAt),
                );

            $messageId = (int) DB::table('whats_app_messages')->insertGetId([
                'conversation_id' => $conversationId,
                'connection_profile_id' => $connectionProfileId,
                'direction' => 'inbound',
                'content_type' => mb_substr($contentType, 0, 24),
                'actor_type' => 'customer',
                'actor_user_id' => null,
                'body' => $body,
                'attachment_metadata' => $attachmentMetadata === []
                    ? null
                    : json_encode($attachmentMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'provider_message_ref' => $providerReference,
                'delivery_status' => 'received',
                'occurred_at' => $occurred,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('whats_app_conversations')->where('id', $conversationId)->update([
                'status' => 'WAITING_FOR_AGENT',
                'waiting_since' => $conversation->waiting_since ?? $now,
                'first_inbound_at' => $conversation->first_inbound_at ?? $occurred,
                'last_inbound_at' => $occurred,
                'unread_count' => ((int) $conversation->unread_count) + 1,
                'lock_version' => ((int) $conversation->lock_version) + 1,
                'updated_at' => $now,
            ]);

            if ($autoAssign && $conversation->primary_agent_id === null) {
                $this->autoAssign($conversationId);
            }

            // #1428: create the Dashboard alert only after the inbound message
            // and any deterministic assignment have been durably persisted.
            $this->inboundAlerts->recordForPersistedMessage($messageId);

            return $messageId;
        }, 3);
    }

    public function appendAutomationPending(
        int $conversationId,
        string $body,
        array $attachmentMetadata = [],
    ): int {
        return DB::transaction(function () use ($conversationId, $body, $attachmentMetadata): int {
            $conversation = $this->lockConversation($conversationId);
            $now = now();
            $contentType = isset($attachmentMetadata['interactive'])
                ? 'interactive'
                : (isset($attachmentMetadata['template_name'])
                    ? 'template'
                    : (isset($attachmentMetadata['media_type']) ? 'media' : 'text'));

            $messageId = (int) DB::table('whats_app_messages')->insertGetId([
                'conversation_id' => $conversationId,
                'direction' => 'outbound',
                'content_type' => $contentType,
                'actor_type' => 'bot',
                'actor_user_id' => null,
                'body' => trim($body),
                'attachment_metadata' => $attachmentMetadata === []
                    ? null
                    : json_encode($attachmentMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'provider_message_ref' => null,
                'delivery_status' => 'pending_transport',
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('whats_app_conversations')->where('id', $conversationId)->update([
                'status' => 'WAITING_FOR_CUSTOMER',
                'last_outbound_at' => $now,
                'unread_count' => 0,
                'waiting_since' => null,
                'lock_version' => ((int) $conversation->lock_version) + 1,
                'updated_at' => $now,
            ]);

            return $messageId;
        }, 3);
    }

    public function appendOutboundPending(
        int $conversationId,
        User $actor,
        string $body,
        array $attachmentMetadata = [],
    ): int {
        $conversation = $this->conversationForActor(
            $conversationId,
            $actor,
            'whatsapp.conversations.reply',
        );
        $now = now();

        return DB::transaction(function () use (
            $conversation,
            $conversationId,
            $actor,
            $body,
            $attachmentMetadata,
            $now,
        ): int {
            $messageId = (int) DB::table('whats_app_messages')->insertGetId([
                'conversation_id' => $conversationId,
                'direction' => 'outbound',
                'content_type' => $attachmentMetadata === []
                    ? 'text'
                    : (isset($attachmentMetadata['template_name']) ? 'template' : (string) ($attachmentMetadata['media_type'] ?? 'media')),
                'actor_type' => 'agent',
                'actor_user_id' => $actor->getKey(),
                'body' => trim($body),
                'attachment_metadata' => $attachmentMetadata === []
                    ? null
                    : json_encode($attachmentMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'provider_message_ref' => null,
                'delivery_status' => 'pending_transport',
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('whats_app_conversations')->where('id', $conversationId)->update([
                'status' => 'WAITING_FOR_CUSTOMER',
                'first_agent_response_at' => $conversation->first_agent_response_at ?? $now,
                'last_outbound_at' => $now,
                'unread_count' => 0,
                'waiting_since' => null,
                'lock_version' => ((int) $conversation->lock_version) + 1,
                'updated_at' => $now,
            ]);

            return $messageId;
        }, 3);
    }

    public function markDeliveryState(
        int $messageId,
        string $state,
        ?string $providerReference = null,
    ): void {
        DB::table('whats_app_messages')
            ->where('id', $messageId)
            ->where('direction', 'outbound')
            ->update([
                'delivery_status' => $state,
                'provider_message_ref' => $providerReference,
                'updated_at' => now(),
            ]);
    }

    public function addInternalNote(int $conversationId, User $actor, string $body): int
    {
        $this->conversationForActor(
            $conversationId,
            $actor,
            'whatsapp.internal_notes.manage',
        );

        return (int) DB::table('whats_app_internal_notes')->insertGetId([
            'conversation_id' => $conversationId,
            'author_user_id' => $actor->getKey(),
            'body' => trim($body),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function assign(
        int $conversationId,
        User $actor,
        int $agentId,
        int $expectedVersion,
        ?string $reason = null,
        ?int $backupAgentId = null,
    ): void {
        $conversation = $this->conversationForActor(
            $conversationId,
            $actor,
            $this->currentPrimaryAgent($conversationId) === null
                ? 'whatsapp.conversations.assign'
                : 'whatsapp.conversations.reassign',
        );
        $storeId = $conversation->store_id === null ? null : (int) $conversation->store_id;

        $this->assignments->assertManuallyAssignable($agentId, $storeId);
        if ($backupAgentId !== null) {
            $this->assignments->assertManuallyAssignable($backupAgentId, $storeId);
            if ($backupAgentId === $agentId) {
                throw ValidationException::withMessages([
                    'backup_agent_id' => [__('whatsapp.inbox.errors.backup_same')],
                ]);
            }
        }

        DB::transaction(function () use (
            $conversationId,
            $conversation,
            $actor,
            $agentId,
            $backupAgentId,
            $expectedVersion,
            $reason,
        ): void {
            $updated = DB::table('whats_app_conversations')
                ->where('id', $conversationId)
                ->where('lock_version', $expectedVersion)
                ->update([
                    'primary_agent_id' => $agentId,
                    'status' => 'OPEN',
                    'lock_version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'conversation' => [__('whatsapp.inbox.errors.stale_assignment')],
                ]);
            }

            $oldAssignment = null;
            if ($conversation->platform_customer_id !== null) {
                $oldAssignment = DB::table('whats_app_customer_assignments')
                    ->where('platform_customer_id', $conversation->platform_customer_id)
                    ->lockForUpdate()
                    ->first();
                $version = $oldAssignment === null ? 0 : ((int) $oldAssignment->version) + 1;
                DB::table('whats_app_customer_assignments')->updateOrInsert(
                    ['platform_customer_id' => $conversation->platform_customer_id],
                    [
                        'primary_agent_id' => $agentId,
                        'backup_agent_id' => $backupAgentId,
                        'store_id' => $conversation->store_id,
                        'channel' => $conversation->channel,
                        'source' => $oldAssignment === null ? 'manual' : 'reassignment',
                        'assigned_by' => $actor->getKey(),
                        'assigned_at' => now(),
                        'effective_from' => now(),
                        'effective_until' => null,
                        'is_active' => true,
                        'version' => $version,
                        'updated_at' => now(),
                        'created_at' => $oldAssignment->created_at ?? now(),
                    ],
                );
            }

            $this->history(
                $conversation,
                $actor,
                $oldAssignment?->primary_agent_id,
                $agentId,
                $oldAssignment?->backup_agent_id,
                $backupAgentId,
                $oldAssignment === null ? 'manual' : 'reassignment',
                $reason,
            );

            $this->audit->record(
                $oldAssignment === null
                    ? 'whatsapp.customer_assigned'
                    : 'whatsapp.customer_reassigned',
                $actor,
                null,
                [
                    'store_id' => $conversation->store_id,
                    'primary_agent_id' => $oldAssignment?->primary_agent_id,
                    'backup_agent_id' => $oldAssignment?->backup_agent_id,
                ],
                [
                    'store_id' => $conversation->store_id,
                    'conversation_id' => $conversationId,
                    'primary_agent_id' => $agentId,
                    'backup_agent_id' => $backupAgentId,
                    'reason' => $reason,
                ],
            );
        }, 3);
    }

    public function autoAssign(int $conversationId, string $strategy = 'rule_based'): ?int
    {
        $conversation = DB::table('whats_app_conversations')
            ->where('id', $conversationId)
            ->first();

        if ($conversation === null) {
            return null;
        }

        if ($conversation->primary_agent_id !== null) {
            return (int) $conversation->primary_agent_id;
        }

        $agentId = $this->assignments->choose(
            $conversation->store_id === null ? null : (int) $conversation->store_id,
            (string) $conversation->channel,
            $strategy,
        );

        if ($agentId === null) {
            DB::table('whats_app_conversations')
                ->where('id', $conversationId)
                ->update([
                    'status' => 'WAITING_FOR_AGENT',
                    'waiting_since' => $conversation->waiting_since ?? now(),
                    'updated_at' => now(),
                ]);

            return null;
        }

        DB::transaction(function () use ($conversation, $conversationId, $agentId, $strategy): void {
            $now = now();

            $updated = DB::table('whats_app_conversations')
                ->where('id', $conversationId)
                ->whereNull('primary_agent_id')
                ->update([
                    'primary_agent_id' => $agentId,
                    'status' => 'OPEN',
                    'lock_version' => ((int) $conversation->lock_version) + 1,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return;
            }

            $oldAssignment = null;
            if ($conversation->platform_customer_id !== null) {
                $oldAssignment = DB::table('whats_app_customer_assignments')
                    ->where('platform_customer_id', $conversation->platform_customer_id)
                    ->lockForUpdate()
                    ->first();
                $version = $oldAssignment === null ? 0 : ((int) $oldAssignment->version) + 1;

                DB::table('whats_app_customer_assignments')->updateOrInsert(
                    ['platform_customer_id' => $conversation->platform_customer_id],
                    [
                        'primary_agent_id' => $agentId,
                        'backup_agent_id' => $oldAssignment?->backup_agent_id,
                        'store_id' => $conversation->store_id,
                        'channel' => $conversation->channel,
                        'source' => $strategy,
                        'assigned_by' => null,
                        'assigned_at' => $now,
                        'effective_from' => $now,
                        'effective_until' => null,
                        'is_active' => true,
                        'version' => $version,
                        'updated_at' => $now,
                        'created_at' => $oldAssignment->created_at ?? $now,
                    ],
                );
            }

            $this->history(
                $conversation,
                null,
                $oldAssignment?->primary_agent_id,
                $agentId,
                $oldAssignment?->backup_agent_id,
                $oldAssignment?->backup_agent_id,
                $strategy,
                null,
            );
        }, 3);

        return $agentId;
    }

    public function resolve(int $conversationId, User $actor, bool $reopen = false): void
    {
        $conversation = $this->conversationForActor(
            $conversationId,
            $actor,
            'whatsapp.conversations.reply',
        );

        DB::table('whats_app_conversations')->where('id', $conversationId)->update([
            'status' => $reopen ? 'OPEN' : 'RESOLVED',
            'resolved_at' => $reopen ? null : now(),
            'reopened_at' => $reopen ? now() : $conversation->reopened_at,
            'waiting_since' => $reopen ? now() : null,
            'lock_version' => ((int) $conversation->lock_version) + 1,
            'updated_at' => now(),
        ]);

        $this->audit->record(
            $reopen ? 'whatsapp.conversation_reopened' : 'whatsapp.conversation_resolved',
            $actor,
            null,
            null,
            [
                'store_id' => $conversation->store_id,
                'conversation_id' => $conversationId,
            ],
        );
    }

    public function conversationForActor(
        int $conversationId,
        User $actor,
        string $permission,
    ): object {
        $conversation = DB::table('whats_app_conversations')
            ->where('id', $conversationId)
            ->first();

        abort_unless($conversation !== null, 404);

        $storeId = $conversation->store_id === null ? null : (int) $conversation->store_id;
        abort_unless($actor->hasPermission($permission, $storeId), 403);

        $supervisor = $actor->hasPermission(
            'whatsapp.customer_service.supervise',
            $storeId,
        );

        abort_unless(
            $supervisor
                || $conversation->primary_agent_id === null
                || (int) $conversation->primary_agent_id === (int) $actor->getKey(),
            404,
        );

        return $conversation;
    }

    private function lockConversation(int $conversationId): object
    {
        $conversation = DB::table('whats_app_conversations')
            ->where('id', $conversationId)
            ->lockForUpdate()
            ->first();

        if ($conversation === null) {
            throw ValidationException::withMessages([
                'conversation' => [__('whatsapp.inbox.errors.conversation_missing')],
            ]);
        }

        return $conversation;
    }

    private function currentPrimaryAgent(int $conversationId): ?int
    {
        $id = DB::table('whats_app_conversations')
            ->where('id', $conversationId)
            ->value('primary_agent_id');

        return $id === null ? null : (int) $id;
    }

    private function history(
        object $conversation,
        ?User $actor,
        mixed $oldPrimary,
        mixed $newPrimary,
        mixed $oldBackup,
        mixed $newBackup,
        string $source,
        ?string $reason,
    ): void {
        DB::table('whats_app_assignment_history')->insert([
            'conversation_id' => $conversation->id,
            'platform_customer_id' => $conversation->platform_customer_id,
            'store_id' => $conversation->store_id,
            'channel' => $conversation->channel,
            'old_primary_agent_id' => $oldPrimary,
            'new_primary_agent_id' => $newPrimary,
            'old_backup_agent_id' => $oldBackup,
            'new_backup_agent_id' => $newBackup,
            'actor_user_id' => $actor?->getKey(),
            'source' => $source,
            'reason' => $reason,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
