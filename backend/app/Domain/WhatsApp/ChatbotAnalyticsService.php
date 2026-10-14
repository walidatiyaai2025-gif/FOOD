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
        ?int $actorUserId = null,
    ): void {
        if (! Schema::hasTable('whats_app_chatbot_events')) {
            return;
        }

        DB::table('whats_app_chatbot_events')->insert([
            'conversation_id' => $conversationId,
            'platform_customer_id' => $platformCustomerId,
            'actor_user_id' => $actorUserId,
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
                'draft_actions' => 0,
                'support_cases' => 0,
                'campaign_entries' => 0,
                'draft_value' => 0.0,
                'features' => collect(),
                'daily' => collect(),
            ];
        }

        $from = now()->subDays(max(1, min(365, $days)))->startOfDay();
        $base = DB::table('whats_app_chatbot_events')->where('occurred_at', '>=', $from);

        $draftEvents = (clone $base)
            ->whereIn('event_type', ['draft_product_added', 'draft_reorder_created'])
            ->get(['payload']);

        $draftValue = 0.0;
        foreach ($draftEvents as $event) {
            $payload = is_string($event->payload) ? json_decode($event->payload, true) : null;
            if (is_array($payload) && is_numeric($payload['draft_value'] ?? null)) {
                $draftValue += (float) $payload['draft_value'];
            }
        }

        return [
            'conversations' => (clone $base)->whereNotNull('conversation_id')->distinct()->count('conversation_id'),
            'customers' => (clone $base)->whereNotNull('platform_customer_id')->distinct()->count('platform_customer_id'),
            'bot_replies' => (clone $base)->where('event_type', 'bot_reply')->count(),
            'handoffs' => (clone $base)->where('event_type', 'human_handoff')->count(),
            'fallbacks' => (clone $base)->where('event_type', 'fallback')->count(),
            'draft_actions' => $draftEvents->count(),
            'support_cases' => (clone $base)->where('event_type', 'support_case_created')->count(),
            'campaign_entries' => (clone $base)->where('event_type', 'campaign_chatbot_entry')->count(),
            'draft_value' => round($draftValue, 3),
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
