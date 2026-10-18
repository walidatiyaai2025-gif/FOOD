<?php

namespace App\Domain\Assistant\Support;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantConversationState;
use App\Models\User;
use Carbon\CarbonImmutable;

final class AssistantMemoryPolicy
{
    public function pruneExpiredConversations(User $user): void
    {
        $cutoff = CarbonImmutable::now()->subDays(max(1, (int) config('assistant.retention_days', 90)));

        AssistantConversation::query()
            ->where('user_id', $user->getKey())
            ->where('updated_at', '<', $cutoff)
            ->delete();
    }

    public function stateFresh(?AssistantConversationState $state): bool
    {
        if ($state === null || $state->updated_at === null) {
            return false;
        }

        $cutoff = CarbonImmutable::now()->subMinutes(max(1, (int) config('assistant.session_minutes', 120)));

        return CarbonImmutable::instance($state->updated_at)->greaterThanOrEqualTo($cutoff);
    }

    public function expireTransientState(AssistantConversationState $state): AssistantConversationState
    {
        $state->forceFill([
            'last_intent' => null,
            'last_period' => null,
            'last_authorized_entities' => [],
            'last_tool' => null,
            'result_references' => [],
            'pending_clarification_slots' => [],
        ])->save();

        return $state->fresh();
    }

    /**
     * Durable Assistant memory is deliberately tiny: presentation preferences only.
     * Business values, tool results, permissions and provider/model settings are never accepted.
     *
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public function safePreferences(array $input, string $locale): array
    {
        $preferences = [
            'locale' => strtolower($locale) === 'en' ? 'en' : 'ar',
        ];

        $style = is_string($input['response_style'] ?? null)
            ? strtolower(trim((string) $input['response_style']))
            : null;

        if (in_array($style, ['concise', 'detailed'], true)) {
            $preferences['response_style'] = $style;
        }

        return $preferences;
    }

    public function redact(string $content): string
    {
        $patterns = [
            '/\bAuthorization\s*:\s*[^\r\n]+/iu' => 'Authorization: [REDACTED]',
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/iu' => 'Bearer [REDACTED]',
            '/\b(api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password)\s*[:=]\s*([^\s,;]+)/iu' => '$1=[REDACTED]',
        ];

        $redacted = $content;
        foreach ($patterns as $pattern => $replacement) {
            $next = preg_replace($pattern, $replacement, $redacted);
            if (is_string($next)) {
                $redacted = $next;
            }
        }

        return $redacted;
    }
}
