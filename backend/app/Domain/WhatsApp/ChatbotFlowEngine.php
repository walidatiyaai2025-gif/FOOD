<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChatbotFlowEngine
{
    /** @param array<string, mixed> $reply
     * @return array<string, mixed>
     */
    public function decorate(string $action, string $locale, array $reply): array
    {
        if (! Schema::hasTable('whats_app_chatbot_flows')) {
            return $reply;
        }

        $flows = DB::table('whats_app_chatbot_flows')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->get();

        foreach ($flows as $flow) {
            $definition = json_decode((string) $flow->definition, true);
            if (! is_array($definition)) {
                continue;
            }

            $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
            $actionNode = collect($nodes)->first(
                static fn (mixed $node): bool => is_array($node)
                    && ($node['type'] ?? null) === 'action'
                    && ($node['action'] ?? null) === $action,
            );

            if (! is_array($actionNode)) {
                continue;
            }

            $welcome = collect($nodes)->first(
                static fn (mixed $node): bool => is_array($node)
                    && ($node['id'] ?? null) === ($definition['start'] ?? 'welcome'),
            );

            $intro = is_array($welcome)
                ? (string) (($welcome['content'][$locale] ?? $welcome['content']['ar'] ?? $welcome['content']['en'] ?? ''))
                : '';

            if (trim($intro) !== '') {
                $reply['body'] = trim($intro)."\n\n".trim((string) ($reply['body'] ?? ''));
            }

            $reply['_flow'] = [
                'id' => (int) $flow->id,
                'version' => (int) $flow->version,
                'node' => (string) ($actionNode['id'] ?? 'primary_action'),
            ];

            return $reply;
        }

        return $reply;
    }

    /** @param array{id:int,version:int,node:string} $flow */
    public function persist(int $conversationId, ?int $platformCustomerId, array $flow): void
    {
        if (! Schema::hasTable('whats_app_chatbot_sessions')) {
            return;
        }

        $existing = DB::table('whats_app_chatbot_sessions')
            ->where('conversation_id', $conversationId)
            ->first();

        $context = [];
        if ($existing !== null && is_string($existing->context) && trim($existing->context) !== '') {
            $decoded = json_decode($existing->context, true);
            $context = is_array($decoded) ? $decoded : [];
        }

        $context['flow'] = $flow;

        DB::table('whats_app_chatbot_sessions')->updateOrInsert(
            ['conversation_id' => $conversationId],
            [
                'platform_customer_id' => $platformCustomerId,
                'state' => $existing->state ?? 'ACTIVE',
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ],
        );
    }
}
