<?php

namespace App\Domain\Assistant\Conversation;

use App\Domain\Assistant\Contracts\AssistantBrainInterface;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantBrainResult;
use App\Domain\Assistant\Tools\AssistantToolRegistry;

final class DeterministicBrain implements AssistantBrainInterface
{
    private const HIGH_CONFIDENCE = 0.75;

    private const MEDIUM_CONFIDENCE = 0.45;

    private TextNormalizer $normalizer;

    private IntentClassifier $classifier;

    private EntityExtractor $entityExtractor;

    private TimeWindowParser $timeWindowParser;

    private DialogueStateResolver $dialogueStateResolver;

    public function __construct(
        private readonly AssistantToolRegistry $toolRegistry,
        ?TextNormalizer $normalizer = null,
        ?IntentClassifier $classifier = null,
        ?EntityExtractor $entityExtractor = null,
        ?TimeWindowParser $timeWindowParser = null,
        ?DialogueStateResolver $dialogueStateResolver = null,
    ) {
        $this->normalizer = $normalizer ?? new TextNormalizer;
        $this->classifier = $classifier ?? new IntentClassifier($this->normalizer);
        $this->entityExtractor = $entityExtractor ?? new EntityExtractor($this->normalizer);
        $this->timeWindowParser = $timeWindowParser ?? new TimeWindowParser;
        $this->dialogueStateResolver = $dialogueStateResolver ?? new DialogueStateResolver;
    }

    public function respond(AssistantBrainRequest $request): AssistantBrainResult
    {
        $normalized = $this->normalizer->normalize($request->message);
        $classification = $this->classifier->classify($normalized);
        $entities = $this->entityExtractor->extract($request->message, $normalized);
        $period = $this->timeWindowParser->parse($normalized);
        $resolved = $this->dialogueStateResolver->resolve(
            $normalized,
            $classification,
            $entities,
            $period,
            $request->context,
            $request->state,
        );

        $intent = $resolved['intent'];
        $confidence = $resolved['confidence'];
        $arabic = str_starts_with(mb_strtolower($request->locale, 'UTF-8'), 'ar');

        if ($intent === null || $confidence < self::MEDIUM_CONFIDENCE) {
            return $this->fallback($arabic, $resolved);
        }

        if (! $this->toolRegistry->has($intent)) {
            return $this->unavailableToolFallback($arabic, $resolved);
        }

        if ($confidence < self::HIGH_CONFIDENCE) {
            return new AssistantBrainResult(
                message: $arabic
                    ? 'هل تقصد '.$this->label($intent, true).'؟'
                    : 'Do you mean '.$this->label($intent, false).'?',
                intent: $intent,
                confidence: $confidence,
                suggestedPrompts: $this->suggestedPrompts($arabic),
                state: $this->resultState($request, $resolved, $intent, true),
            );
        }

        return new AssistantBrainResult(
            message: $arabic
                ? 'سأستخدم بيانات FOODEX المعتمدة لعرض '.$this->label($intent, true).'.'
                : 'I will use authoritative FOODEX data for '.$this->label($intent, false).'.',
            intent: $intent,
            confidence: $confidence,
            state: $this->resultState($request, $resolved, $intent, false),
        );
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function fallback(bool $arabic, array $resolved): AssistantBrainResult
    {
        return new AssistantBrainResult(
            message: $arabic
                ? 'لم أحدد طلبك بدقة. أقدر أساعد في المبيعات والطلبات والفروع والعملاء والمنتجات والسائقين والمخزون.'
                : 'I could not identify that request reliably. I can help with sales, orders, stores, customers, products, drivers, and inventory.',
            intent: null,
            confidence: 0.0,
            suggestedPrompts: $this->suggestedPrompts($arabic),
            state: [
                'entities' => $resolved['entities'] ?? [],
                'period' => $resolved['period'] ?? null,
                'pending_clarification' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function unavailableToolFallback(bool $arabic, array $resolved): AssistantBrainResult
    {
        return new AssistantBrainResult(
            message: $arabic
                ? 'فهمت الموضوع، لكن الأداة المعتمدة لهذا الطلب غير مسجلة حاليًا.'
                : 'I understood the topic, but the authoritative tool for this request is not currently registered.',
            intent: null,
            confidence: 0.0,
            suggestedPrompts: $this->suggestedPrompts($arabic),
            state: [
                'entities' => $resolved['entities'] ?? [],
                'period' => $resolved['period'] ?? null,
                'pending_clarification' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function resultState(
        AssistantBrainRequest $request,
        array $resolved,
        string $intent,
        bool $pendingClarification,
    ): array {
        return [
            'last_intent' => $intent,
            'last_period' => $resolved['period'] ?? null,
            'last_tool' => $intent,
            'last_authorized_entities' => $resolved['authorized_entities'] ?? [],
            'entities' => $resolved['entities'] ?? [],
            'period' => $resolved['period'] ?? null,
            'reference_resolved' => (bool) ($resolved['reference_resolved'] ?? false),
            'pending_clarification' => $pendingClarification,
            'locale' => $request->locale,
        ];
    }

    /** @return list<string> */
    private function suggestedPrompts(bool $arabic): array
    {
        return $arabic
            ? ['مبيعات اليوم', 'الطلبات المتأخرة', 'ملخص اليوم']
            : ['Sales today', 'Late orders', 'Daily brief'];
    }

    private function label(string $intent, bool $arabic): string
    {
        $labels = [
            'sales.summary' => ['ملخص المبيعات', 'sales summary'],
            'sales.compare' => ['مقارنة المبيعات', 'sales comparison'],
            'orders.summary' => ['ملخص الطلبات', 'orders summary'],
            'orders.lookup' => ['تفاصيل الطلب', 'order details'],
            'stores.summary' => ['ملخص الفروع', 'stores summary'],
            'stores.compare' => ['مقارنة الفروع', 'store comparison'],
            'customers.summary' => ['ملخص العملاء', 'customers summary'],
            'customers.activity' => ['نشاط العملاء', 'customer activity'],
            'products.performance' => ['أداء المنتجات', 'product performance'],
            'orders.late' => ['الطلبات المتأخرة', 'late orders'],
            'orders.cancelled' => ['الطلبات الملغية', 'cancelled orders'],
            'cancellations.summary' => ['ملخص الإلغاءات', 'cancellations summary'],
            'drivers.status' => ['حالة السائقين', 'driver status'],
            'drivers.assignments' => ['تكليفات السائقين', 'driver assignments'],
            'inventory.alerts' => ['تنبيهات المخزون', 'inventory alerts'],
            'brief.daily' => ['ملخص اليوم', 'daily brief'],
        ];

        return $labels[$intent][$arabic ? 0 : 1] ?? $intent;
    }
}
