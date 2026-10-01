<?php

namespace App\Domain\Assistant\Conversation;

final class IntentClassifier
{
    /**
     * @var array<string, array<string, float>>
     */
    private const DEFINITIONS = [
        'sales.compare' => [
            'compare sales' => 0.99,
            'sales comparison' => 0.98,
            'قارن المبيعات' => 0.99,
            'مقارنه المبيعات' => 0.98,
            'compare' => 0.55,
            'قارن' => 0.55,
        ],
        'sales.summary' => [
            'sales summary' => 0.98,
            'show sales' => 0.92,
            'sales' => 0.80,
            'ملخص المبيعات' => 0.98,
            'اعرض المبيعات' => 0.92,
            'المبيعات' => 0.82,
            'مبيعات' => 0.80,
        ],
        'orders.lookup' => [
            'order number' => 0.99,
            'order #' => 0.99,
            'find order' => 0.98,
            'طلب رقم' => 0.99,
            'هات الطلب' => 0.95,
        ],
        'orders.late' => [
            'late orders' => 0.99,
            'delayed orders' => 0.99,
            'orders delayed' => 0.96,
            'الطلبات المتاخره' => 0.99,
            'طلبات متاخره' => 0.97,
            'الطلبات المتعطله' => 0.96,
        ],
        'orders.cancelled' => [
            'cancelled orders' => 0.99,
            'canceled orders' => 0.99,
            'orders cancelled' => 0.96,
            'الطلبات الملغيه' => 0.99,
            'طلبات ملغيه' => 0.97,
        ],
        'orders.summary' => [
            'orders summary' => 0.98,
            'show orders' => 0.92,
            'orders' => 0.80,
            'ملخص الطلبات' => 0.98,
            'اعرض الطلبات' => 0.92,
            'الطلبات' => 0.82,
            'طلبات' => 0.78,
        ],
        'stores.compare' => [
            'compare stores' => 0.99,
            'compare branches' => 0.99,
            'store comparison' => 0.98,
            'قارن الفروع' => 0.99,
            'قارن المتاجر' => 0.99,
            'مقارنه الفروع' => 0.98,
            'compare' => 0.55,
            'قارن' => 0.55,
        ],
        'stores.summary' => [
            'stores summary' => 0.98,
            'store summary' => 0.96,
            'branches summary' => 0.96,
            'stores' => 0.80,
            'branches' => 0.80,
            'ملخص الفروع' => 0.98,
            'ملخص المتاجر' => 0.98,
            'الفروع' => 0.82,
            'المتاجر' => 0.82,
        ],
        'customers.activity' => [
            'customer activity' => 0.99,
            'customers activity' => 0.99,
            'نشاط العملاء' => 0.99,
            'حركه العملاء' => 0.96,
        ],
        'customers.summary' => [
            'customers summary' => 0.98,
            'customer summary' => 0.96,
            'customers' => 0.80,
            'ملخص العملاء' => 0.98,
            'العملاء' => 0.82,
        ],
        'products.performance' => [
            'product performance' => 0.99,
            'products performance' => 0.99,
            'best selling products' => 0.98,
            'top products' => 0.96,
            'اداء المنتجات' => 0.99,
            'المنتجات الاكثر مبيعا' => 0.99,
            'افضل المنتجات' => 0.96,
        ],
        'cancellations.summary' => [
            'cancellations summary' => 0.99,
            'cancellation rate' => 0.98,
            'cancellations' => 0.90,
            'ملخص الالغاءات' => 0.99,
            'نسبه الالغاء' => 0.98,
            'الالغاءات' => 0.90,
        ],
        'drivers.status' => [
            'driver status' => 0.99,
            'drivers status' => 0.99,
            'driver availability' => 0.96,
            'حاله السائقين' => 0.99,
            'السائقين المتاحين' => 0.96,
        ],
        'drivers.assignments' => [
            'driver assignments' => 0.99,
            'drivers assignments' => 0.99,
            'assigned drivers' => 0.96,
            'تكليفات السائقين' => 0.99,
            'تعيينات السائقين' => 0.96,
        ],
        'inventory.alerts' => [
            'inventory alerts' => 0.99,
            'low stock' => 0.99,
            'stock alerts' => 0.98,
            'تنبيهات المخزون' => 0.99,
            'نواقص المخزون' => 0.99,
            'مخزون منخفض' => 0.98,
        ],
        'brief.daily' => [
            'daily brief' => 0.99,
            'daily summary' => 0.96,
            'today brief' => 0.96,
            'ملخص اليوم' => 0.99,
            'التقرير اليومي' => 0.98,
            'موجز اليوم' => 0.98,
        ],
    ];

    private TextNormalizer $normalizer;

    public function __construct(?TextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new TextNormalizer;
    }

    /**
     * @return array{intent: ?string, confidence: float, alternatives: list<array{intent: string, confidence: float}>}
     */
    public function classify(string $normalizedMessage): array
    {
        $scores = [];

        foreach (self::DEFINITIONS as $intent => $patterns) {
            $best = 0.0;

            foreach ($patterns as $phrase => $weight) {
                if ($this->containsPhrase($normalizedMessage, $this->normalizer->normalize($phrase))) {
                    $best = max($best, $weight);
                }
            }

            if ($best > 0.0) {
                $scores[] = ['intent' => $intent, 'confidence' => $best];
            }
        }

        usort($scores, static function (array $left, array $right): int {
            $scoreOrder = $right['confidence'] <=> $left['confidence'];

            return $scoreOrder !== 0
                ? $scoreOrder
                : $left['intent'] <=> $right['intent'];
        });

        if ($scores === []) {
            return [
                'intent' => null,
                'confidence' => 0.0,
                'alternatives' => [],
            ];
        }

        $confidence = $scores[0]['confidence'];

        if (isset($scores[1]) && abs($confidence - $scores[1]['confidence']) < 0.10) {
            $confidence = min($confidence, 0.60);
        }

        return [
            'intent' => $scores[0]['intent'],
            'confidence' => $confidence,
            'alternatives' => array_slice($scores, 1, 3),
        ];
    }

    private function containsPhrase(string $message, string $phrase): bool
    {
        if ($message === '' || $phrase === '') {
            return false;
        }

        return str_contains(' '.$message.' ', ' '.$phrase.' ');
    }
}
