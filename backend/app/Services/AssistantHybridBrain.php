<?php

namespace App\Services;

use App\Domain\Assistant\Contracts\AssistantBrainInterface;
use App\Domain\Assistant\Conversation\DeterministicBrain;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantBrainResult;

final class AssistantHybridBrain implements AssistantBrainInterface
{
    public function __construct(
        private readonly DeterministicBrain $deterministic,
        private readonly AssistantExternalAiClient $external,
        private readonly AssistantProviderSettings $settings,
    ) {}

    public function respond(AssistantBrainRequest $request): AssistantBrainResult
    {
        $base = $this->deterministic->respond($request);

        if ($base->intent !== null || ! $this->settings->usable()) {
            return $base;
        }

        $external = $this->external->route($request);

        if ($external === null) {
            return $base;
        }

        $intent = $external['intent'];
        $message = trim($external['message']);

        if ($intent === null) {
            return new AssistantBrainResult(
                message: $message === '' ? $base->message : $message,
                intent: null,
                confidence: $external['confidence'],
                cards: $base->cards,
                actions: $base->actions,
                suggestedPrompts: $base->suggestedPrompts,
                state: $base->state,
            );
        }

        $state = $base->state;
        $state['entities'] = array_merge(
            is_array($state['entities'] ?? null) ? $state['entities'] : [],
            $external['entities'],
        );
        $state['last_intent'] = $intent;
        $state['last_tool'] = $intent;
        $state['pending_clarification'] = false;
        $state['external_ai_routed'] = true;

        return new AssistantBrainResult(
            message: $message === ''
                ? (strtolower($request->locale) === 'ar'
                    ? 'سأستخدم بيانات FOODEX المعتمدة لهذا الطلب.'
                    : 'I will use authoritative FOODEX data for this request.')
                : $message,
            intent: $intent,
            confidence: $external['confidence'],
            suggestedPrompts: $base->suggestedPrompts,
            state: $state,
        );
    }
}
