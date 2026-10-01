<?php

namespace App\Domain\Assistant\Conversation;

final class DialogueStateResolver
{
    /**
     * @param  array{intent: ?string, confidence: float, alternatives: list<array{intent: string, confidence: float}>}  $classification
     * @param  array<string, int|string>  $entities
     * @param  array{key: string, start: string, end: string}|null  $period
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $state
     * @return array{intent: ?string, confidence: float, entities: array<string, mixed>, period: array<string, string>|null, authorized_entities: array<string, mixed>, reference_resolved: bool}
     */
    public function resolve(
        string $normalizedMessage,
        array $classification,
        array $entities,
        ?array $period,
        array $context,
        array $state,
    ): array {
        $intent = $classification['intent'];
        $confidence = $classification['confidence'];
        $lastIntent = is_string($state['last_intent'] ?? null) ? $state['last_intent'] : null;

        $hasReference = $this->containsAny($normalizedMessage, [
            'ده',
            'دي',
            'دول',
            'هم',
            'نفسهم',
            'it',
            'this',
            'them',
            'those',
            'same',
        ]);
        $asksComparison = $this->containsAny($normalizedMessage, ['compare', 'comparison', 'compare them', 'compare it', 'قارن', 'قارنهم', 'قارنها', 'قارنه', 'مقارنه']);

        if ($lastIntent !== null && $asksComparison) {
            $comparisonIntent = match ($lastIntent) {
                'sales.summary', 'sales.compare' => 'sales.compare',
                'stores.summary', 'stores.compare' => 'stores.compare',
                default => null,
            };

            if ($comparisonIntent !== null) {
                $intent = $comparisonIntent;
                $confidence = max($confidence, 0.95);
                $hasReference = true;
            }
        } elseif ($lastIntent !== null && ($hasReference || ($intent === null && $period !== null))) {
            $intent = $lastIntent;
            $confidence = max($confidence, 0.90);
        }

        $authorizedEntities = $this->arrayValue($context['authorized_entities'] ?? null);

        if ($authorizedEntities === []) {
            $authorizedEntities = $this->arrayValue($state['last_authorized_entities'] ?? null);
        }

        $resolvedEntities = $entities;
        $referenceResolved = false;

        if ($resolvedEntities === [] && $hasReference && $authorizedEntities !== []) {
            $resolvedEntities = $authorizedEntities;
            $referenceResolved = true;
        } elseif ($resolvedEntities === [] && $authorizedEntities !== [] && $this->containsAny($normalizedMessage, ['this', 'current', 'الحالي', 'دي', 'ده'])) {
            $resolvedEntities = $authorizedEntities;
            $referenceResolved = true;
        }

        if ($period === null) {
            $lastPeriod = $this->arrayValue($state['last_period'] ?? null);

            if ($hasReference && isset($lastPeriod['key'], $lastPeriod['start'], $lastPeriod['end'])) {
                $period = [
                    'key' => (string) $lastPeriod['key'],
                    'start' => (string) $lastPeriod['start'],
                    'end' => (string) $lastPeriod['end'],
                ];
            }
        }

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'entities' => $resolvedEntities,
            'period' => $period,
            'authorized_entities' => $authorizedEntities,
            'reference_resolved' => $referenceResolved,
        ];
    }

    /** @param list<string> $needles */
    private function containsAny(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains(' '.$message.' ', ' '.$needle.' ')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
