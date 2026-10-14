<?php

namespace App\Domain\WhatsApp;

use App\Domain\WhatsApp\Data\ChatbotActionRequest;

final class ChatbotIntentService
{
    /** @param array<string, mixed> $metadata */
    public function interpret(?string $body, array $metadata = []): ChatbotActionRequest
    {
        $classification = $this->classify($body, $metadata);
        $intent = $classification['intent'];

        $requestedAction = match ($intent) {
            'add_product' => 'update_order_draft',
            'repeat_to_cart' => 'create_reorder_draft',
            'view_cart' => 'read_order_draft',
            'new_order' => 'browse_catalog_for_order',
            'repeat_order' => 'review_previous_order',
            'product_search', 'prices_offers' => 'read_catalog',
            'order_tracking' => 'read_orders',
            'finance' => 'request_finance_verification',
            'complaints' => 'start_support_flow',
            'human_handoff' => 'request_human_handoff',
            'home' => 'show_menu',
            default => 'safe_fallback',
        };

        $requiresConfirmation = in_array($intent, [
            'add_product',
            'repeat_to_cart',
            'new_order',
            'repeat_order',
        ], true);

        $entities = [];
        $interactiveId = $metadata['interactive_id'] ?? null;
        if (is_string($interactiveId) && preg_match('/^chatbot:add_product:(\d+)$/', $interactiveId, $matches) === 1) {
            $entities['product_id'] = (int) $matches[1];
        }

        return new ChatbotActionRequest(
            intent: $intent,
            confidence: (float) $classification['confidence'],
            requestedAction: $requestedAction,
            requiresConfirmation: $requiresConfirmation,
            entities: $entities,
        );
    }

    /** @return array{intent:string,confidence:float} */
    public function classify(?string $body, array $metadata = []): array
    {
        $interactiveId = $metadata['interactive_id'] ?? null;
        if (is_string($interactiveId) && str_starts_with($interactiveId, 'chatbot:')) {
            $intent = substr($interactiveId, strlen('chatbot:'));

            if (str_starts_with($intent, 'add_product:')) {
                return ['intent' => 'add_product', 'confidence' => 1.0];
            }
            if (str_starts_with($intent, 'repeat_to_cart')) {
                return ['intent' => 'repeat_to_cart', 'confidence' => 1.0];
            }
            if ($intent === 'view_cart') {
                return ['intent' => 'view_cart', 'confidence' => 1.0];
            }

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
        if (str_starts_with($intent, 'human_handoff_')) {
            return 'human_handoff';
        }

        return in_array($intent, [
            'home', 'new_order', 'repeat_order', 'product_search', 'prices_offers',
            'order_tracking', 'finance', 'complaints', 'human_handoff',
            'add_product', 'repeat_to_cart', 'view_cart',
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
