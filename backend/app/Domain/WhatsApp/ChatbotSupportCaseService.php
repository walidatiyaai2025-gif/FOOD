<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ChatbotSupportCaseService
{
    /** @param array<string, mixed> $attachmentMetadata */
    public function create(
        int $conversationId,
        ?int $platformCustomerId,
        string $type,
        ?string $description = null,
        array $attachmentMetadata = [],
    ): ?int {
        if (! Schema::hasTable('whats_app_chatbot_cases')) {
            return null;
        }

        $conversation = DB::table('whats_app_conversations')->where('id', $conversationId)->first();
        if ($conversation === null) {
            return null;
        }

        $orderId = null;
        if ($platformCustomerId !== null) {
            $legacyCustomerId = DB::table('platform_customers')
                ->where('id', $platformCustomerId)
                ->value('legacy_customer_id');
            if ($legacyCustomerId !== null) {
                $orderId = DB::table('orders')
                    ->where('customer_id', $legacyCustomerId)
                    ->orderByDesc('created_at')
                    ->value('id');
            }
        }

        $id = (int) DB::table('whats_app_chatbot_cases')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'platform_customer_id' => $platformCustomerId,
            'order_id' => $orderId,
            'type' => mb_substr($type, 0, 64),
            'status' => 'open',
            'priority' => in_array($type, ['damaged', 'delivery'], true) ? 'high' : 'normal',
            'description' => $description === null ? null : mb_substr(trim($description), 0, 2000),
            'attachment_metadata' => $attachmentMetadata === []
                ? null
                : json_encode($attachmentMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'assigned_agent_id' => $conversation->primary_agent_id,
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
