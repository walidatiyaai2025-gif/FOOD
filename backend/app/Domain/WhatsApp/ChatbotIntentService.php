<?php

namespace App\Domain\WhatsApp;

final class ChatbotIntentService
{
    /** @return array{intent:string,confidence:float} */
    public function classify(?string $body, array $metadata = []): array
    {
        $interactiveId = $metadata['interactive_id'] ?? null;
        if (is_string($interactiveId) && str_starts_with($interactiveId, 'chatbot:')) {
            $intent = substr($interactiveId, strlen('chatbot:'));

            return ['intent' => $this->normalizeIntent($intent), 'confidence' => 1.0];
        }

        $text = $this->normalize((string) $body);
        if ($text === '') {
            return ['intent' => 'home', 'confidence' => 0.45];
        }

        $numberMap = [
            '1' => 'new_order',
            '2' => 'repeat_order',
            '3' => 'product_search',
            '4' => 'prices_offers',
            '5' => 'order_tracking',
            '6' => 'finance',
            '7' => 'complaints',
            '8' => 'human_handoff',
        ];
        if (isset($numberMap[$text])) {
            return ['intent' => $numberMap[$text], 'confidence' => 1.0];
        }

        $patterns = [
            'human_handoff' => ['موظف', 'خدمة العملاء', 'بني ادم', 'بني آدم', 'agent', 'human', 'representative'],
            'repeat_order' => ['اخر طلب', 'آخر طلب', 'اعادة الطلب', 'إعادة الطلب', 'نفس الطلب', 'repeat order', 'reorder'],
            'new_order' => ['طلب جديد', 'عايز اطلب', 'عاوز اطلب', 'اعمل طلب', 'new order', 'place order'],
            'order_tracking' => ['تتبع', 'متابعة الطلب', 'طلبي فين', 'فين الطلب', 'track order', 'order status'],
            'finance' => ['فاتورة', 'فواتير', 'حسابي', 'الرصيد', 'كشف حساب', 'invoice', 'balance', 'statement'],
            'complaints' => ['شكوى', 'مشكلة', 'ناقص', 'تالف', 'غلط', 'complaint', 'problem', 'damaged', 'missing'],
            'prices_offers' => ['سعر', 'اسعار', 'أسعار', 'عرض', 'عروض', 'price', 'offer', 'discount'],
            'product_search' => ['منتج', 'ابحث', 'دور على', 'search', 'product', 'catalog'],
            'home' => ['القائمة', 'الرئيسية', 'menu', 'home', 'ابدأ', 'start'],
        ];

        foreach ($patterns as $intent => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $this->normalize($needle))) {
                    return ['intent' => $intent, 'confidence' => 0.92];
                }
            }
        }

        return ['intent' => 'fallback', 'confidence' => 0.0];
    }

    private function normalizeIntent(string $intent): string
    {
        return in_array($intent, [
            'home', 'new_order', 'repeat_order', 'product_search', 'prices_offers',
            'order_tracking', 'finance', 'complaints', 'human_handoff',
        ], true) ? $intent : 'fallback';
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace(['ة'], 'ه', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
