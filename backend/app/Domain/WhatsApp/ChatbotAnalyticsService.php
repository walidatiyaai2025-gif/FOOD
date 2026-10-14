<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChatbotAnalyticsService
{
    /** @param array<string, mixed> $payload */
    public function record(
        string $eventType,
        ?int $conversationId = null,
        ?int $platformCustomerId = null,
        ?string $featureCode = null,
        ?string $intent = null,
        ?float $confidence = null,
        array $payload = [],
    ): void {
        if (! Schema::hasTable('whats_app_chatbot_events')) {
            return;
        }

        DB::table('whats_app_chatbot_events')->insert([
            'conversation_id' => $conversationId,
            'platform_customer_id' => $platformCustomerId,
            'event_type' => mb_substr($eventType, 0, 64),
            'feature_code' => $featureCode === null ? null : mb_substr($featureCode, 0, 64),
            'intent' => $intent === null ? null : mb_substr($intent, 0, 64),
            'confidence' => $confidence,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function dashboard(int $days = 30): array
    {
        if (! Schema::hasTable('whats_app_chatbot_events')) {
            return [
                'conversations' => 0,
                'customers' => 0,
                'bot_replies' => 0,
                'handoffs' => 0,
                'fallbacks' => 0,
                'features' => collect(),
                'daily' => collect(),
            ];
        }

        $from = now()->subDays(max(1, min(365, $days)))->startOfDay();
        $base = DB::table('whats_app_chatbot_events')->where('occurred_at', '>=', $from);

        return [
            'conversations' => (clone $base)->whereNotNull('conversation_id')->distinct()->count('conversation_id'),
            'customers' => (clone $base)->whereNotNull('platform_customer_id')->distinct()->count('platform_customer_id'),
            'bot_replies' => (clone $base)->where('event_type', 'bot_reply')->count(),
            'handoffs' => (clone $base)->where('event_type', 'human_handoff')->count(),
            'fallbacks' => (clone $base)->where('event_type', 'fallback')->count(),
            'features' => (clone $base)
                ->whereNotNull('feature_code')
                ->selectRaw('feature_code, COUNT(*) as uses')
                ->groupBy('feature_code')
                ->orderByDesc('uses')
                ->limit(10)
                ->get(),
            'daily' => (clone $base)
                ->selectRaw('DATE(occurred_at) as day, COUNT(*) as events')
                ->groupByRaw('DATE(occurred_at)')
                ->orderBy('day')
                ->get(),
        ];
    }
}
