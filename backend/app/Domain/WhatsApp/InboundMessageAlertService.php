<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;

/**
 * Durable, privacy-minimal bridge from an already-persisted inbound message
 * to the Dashboard alert surface. It never ingests provider webhooks itself.
 */
final class InboundMessageAlertService
{
    public function __construct(private readonly RuntimePolicy $policy) {}

    public function recordForPersistedMessage(int $messageId): void
    {
        if (! $this->policy->allowsInbound() || ! $this->policy->allowsAgentInbox()) {
            return;
        }

        $source = DB::table('whats_app_messages as messages')
            ->join('whats_app_conversations as conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('messages.id', $messageId)
            ->where('messages.direction', 'inbound')
            ->first([
                'messages.id as message_id',
                'messages.conversation_id',
                'conversations.primary_agent_id',
            ]);

        if ($source === null || $source->primary_agent_id === null) {
            // #1403 routing has already attempted auto-assignment. If no
            // eligible agent exists, leave the conversation in the shared
            // unassigned queue instead of broadcasting private content.
            return;
        }

        DB::table('whats_app_inbound_alerts')->insertOrIgnore([
            'message_id' => (int) $source->message_id,
            'conversation_id' => (int) $source->conversation_id,
            'recipient_user_id' => (int) $source->primary_agent_id,
            'state' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
