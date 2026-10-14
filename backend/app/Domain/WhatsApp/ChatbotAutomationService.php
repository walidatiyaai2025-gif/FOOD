<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChatbotAutomationService
{
    public function __construct(
        private readonly ChatbotRuntimePolicy $runtime,
        private readonly ChatbotFeatureRegistry $features,
        private readonly ChatbotIntentService $intents,
        private readonly ChatbotAnalyticsService $analytics,
        private readonly ConversationService $conversations,
        private readonly OutboundMessageDispatcher $dispatcher,
    ) {}

    public function shouldHandle(int $conversationId): bool
    {
        return $this->runtime->canHandleConversation($conversationId);
    }

    /** @param array<string, mixed> $metadata */
    public function handleInbound(
        int $conversationId,
        ?int $platformCustomerId,
        ?string $body,
        array $metadata = [],
    ): bool {
        if (! $this->shouldHandle($conversationId)) {
            return false;
        }

        $settings = $this->runtime->settings();
        $classification = $this->intents->classify($body, $metadata);
        $intent = $classification['intent'];
        $confidence = $classification['confidence'];
        $mode = $this->runtime->mode();

        if ($mode === 'menu' && ! isset($metadata['interactive_id']) && ! $this->menuTextAllowed((string) $body)) {
            $intent = 'home';
            $confidence = 1.0;
        }

        if ($mode !== 'menu' && $intent === 'fallback' && (! (bool) $settings['ai_enabled']
            || $confidence < (float) $settings['confidence_threshold'])) {
            $this->analytics->record(
                'fallback',
                $conversationId,
                $platformCustomerId,
                null,
                null,
                $confidence,
            );

            return $this->send(
                $conversationId,
                $platformCustomerId,
                (string) ($settings[$this->localeKey('fallback')] ?? ''),
                $this->homeInteractive(),
                'home',
                'fallback',
                $confidence,
            );
        }

        if ($intent === 'human_handoff') {
            return $this->handoff($conversationId, $platformCustomerId, 'customer_request');
        }

        $feature = $this->featureForIntent($intent);
        if ($feature !== null && ! $this->features->enabled($feature)) {
            return $this->send(
                $conversationId,
                $platformCustomerId,
                $this->t('feature_unavailable'),
                $this->homeInteractive(),
                'home',
                $intent,
                $confidence,
            );
        }

        $reply = $this->reply($conversationId, $platformCustomerId, $intent);

        if (($reply['handoff'] ?? false) === true) {
            return $this->handoff($conversationId, $platformCustomerId, (string) ($reply['reason'] ?? 'policy'));
        }

        return $this->send(
            $conversationId,
            $platformCustomerId,
            (string) $reply['body'],
            isset($reply['interactive']) && is_array($reply['interactive']) ? $reply['interactive'] : null,
            $feature,
            $intent,
            $confidence,
        );
    }

    /** @return array<string, mixed> */
    private function reply(int $conversationId, ?int $platformCustomerId, string $intent): array
    {
        return match ($intent) {
            'new_order', 'product_search' => $this->productReply($conversationId),
            'repeat_order' => $this->reorderReply($platformCustomerId),
            'prices_offers' => $this->priceReply($conversationId),
            'order_tracking' => $this->orderReply($platformCustomerId),
            'finance' => $this->financeReply($platformCustomerId),
            'complaints' => $this->supportReply(),
            default => [
                'body' => $this->welcome(),
                'interactive' => $this->homeInteractive(),
            ],
        };
    }

    /** @return array<string, mixed> */
    private function productReply(int $conversationId): array
    {
        $conversation = DB::table('whats_app_conversations')->where('id', $conversationId)->first();
        $storeId = $conversation?->store_id === null ? null : (int) $conversation->store_id;

        $query = DB::table('products')
            ->leftJoin('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id');
                if ($storeId !== null) {
                    $join->where('store_products.store_id', $storeId);
                }
            })
            ->where('products.is_active', true)
            ->select('products.id', 'products.name', 'products.sku', 'store_products.price')
            ->orderBy('products.name')
            ->limit(5);

        $products = $query->get();
        if ($products->isEmpty()) {
            return ['body' => $this->t('no_products'), 'interactive' => $this->homeInteractive()];
        }

        $lines = [$this->t('product_intro')];
        foreach ($products as $product) {
            $price = $product->price === null ? $this->t('price_on_request') : number_format((float) $product->price, 3);
            $lines[] = '• '.$product->name.' — '.$price;
        }
        $lines[] = $this->t('product_hint');

        return ['body' => implode("\n", $lines), 'interactive' => $this->homeInteractive()];
    }

    /** @return array<string, mixed> */
    private function reorderReply(?int $platformCustomerId): array
    {
        $customer = $this->customer($platformCustomerId);
        if ($customer === null) {
            return ['body' => $this->t('identity_required'), 'handoff' => true, 'reason' => 'identity_required'];
        }

        $order = DB::table('orders')
            ->where('customer_id', (int) $customer->legacy_customer_id)
            ->orderByDesc('created_at')
            ->first();

        if ($order === null) {
            return ['body' => $this->t('no_previous_order'), 'interactive' => $this->homeInteractive()];
        }

        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get();
        $lines = [
            $this->t('reorder_intro').' #'.$order->order_number,
        ];
        foreach ($items->take(8) as $item) {
            $lines[] = '• '.$item->name_snapshot.' × '.rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.');
        }
        $lines[] = $this->t('draft_only');

        return ['body' => implode("\n", $lines), 'interactive' => $this->homeInteractive()];
    }

    /** @return array<string, mixed> */
    private function priceReply(int $conversationId): array
    {
        $reply = $this->productReply($conversationId);
        $reply['body'] = $this->t('price_intro')."\n".$reply['body'];

        return $reply;
    }

    /** @return array<string, mixed> */
    private function orderReply(?int $platformCustomerId): array
    {
        $customer = $this->customer($platformCustomerId);
        if ($customer === null) {
            return ['body' => $this->t('identity_required'), 'handoff' => true, 'reason' => 'identity_required'];
        }

        $orders = DB::table('orders')
            ->where('customer_id', (int) $customer->legacy_customer_id)
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        if ($orders->isEmpty()) {
            return ['body' => $this->t('no_orders'), 'interactive' => $this->homeInteractive()];
        }

        $lines = [$this->t('orders_intro')];
        foreach ($orders as $order) {
            $lines[] = '• #'.$order->order_number.' — '.$this->status((string) $order->status).' — '.number_format((float) $order->grand_total, 3).' '.$order->currency;
        }

        return ['body' => implode("\n", $lines), 'interactive' => $this->homeInteractive()];
    }

    /** @return array<string, mixed> */
    private function financeReply(?int $platformCustomerId): array
    {
        if (! $this->features->enabled('finance')) {
            return ['body' => $this->t('feature_unavailable'), 'interactive' => $this->homeInteractive()];
        }

        $customer = $this->customer($platformCustomerId);
        if ($customer === null) {
            return ['body' => $this->t('finance_secure'), 'handoff' => true, 'reason' => 'finance_identity'];
        }

        $invoices = DB::table('invoices')
            ->where('platform_customer_id', (int) $customer->id)
            ->orderByDesc('issued_at')
            ->limit(3)
            ->get();

        if ($invoices->isEmpty()) {
            return ['body' => $this->t('no_invoices'), 'interactive' => $this->homeInteractive()];
        }

        $lines = [$this->t('finance_intro')];
        foreach ($invoices as $invoice) {
            $number = $invoice->invoice_number ?? ('#'.$invoice->id);
            $total = $invoice->total ?? 0;
            $lines[] = '• '.$number.' — '.number_format((float) $total, 3);
        }
        $lines[] = $this->t('finance_secure');

        return ['body' => implode("\n", $lines), 'interactive' => $this->homeInteractive()];
    }

    /** @return array<string, mixed> */
    private function supportReply(): array
    {
        return [
            'body' => $this->t('support_intro'),
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $this->t('support_intro')],
                'action' => [
                    'button' => $this->t('choose'),
                    'sections' => [[
                        'title' => $this->t('support_title'),
                        'rows' => [
                            ['id' => 'chatbot:human_handoff', 'title' => $this->t('missing_item')],
                            ['id' => 'chatbot:human_handoff', 'title' => $this->t('damaged_item')],
                            ['id' => 'chatbot:human_handoff', 'title' => $this->t('delivery_problem')],
                        ],
                    ]],
                ],
            ],
        ];
    }

    private function handoff(int $conversationId, ?int $platformCustomerId, string $reason): bool
    {
        if (Schema::hasTable('whats_app_chatbot_sessions')) {
            DB::table('whats_app_chatbot_sessions')->updateOrInsert(
                ['conversation_id' => $conversationId],
                [
                    'platform_customer_id' => $platformCustomerId,
                    'state' => 'HANDOFF',
                    'human_takeover' => true,
                    'current_intent' => 'human_handoff',
                    'last_customer_message_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $this->conversations->autoAssign($conversationId);
        $this->analytics->record(
            'human_handoff',
            $conversationId,
            $platformCustomerId,
            'human_handoff',
            'human_handoff',
            1.0,
            ['reason' => $reason],
        );

        $messageId = $this->conversations->appendAutomationPending(
            $conversationId,
            $this->t('handoff_confirmed'),
            ['purpose' => Capability::AUTOMATION->value],
        );
        $this->dispatcher->queue($messageId);

        return true;
    }

    /** @param array<string, mixed>|null $interactive */
    private function send(
        int $conversationId,
        ?int $platformCustomerId,
        string $body,
        ?array $interactive,
        ?string $feature,
        ?string $intent,
        float $confidence,
    ): bool {
        $metadata = ['purpose' => Capability::AUTOMATION->value];
        if ($interactive !== null) {
            $metadata['interactive'] = $interactive;
        }

        $messageId = $this->conversations->appendAutomationPending($conversationId, $body, $metadata);
        $this->dispatcher->queue($messageId);

        if (Schema::hasTable('whats_app_chatbot_sessions')) {
            DB::table('whats_app_chatbot_sessions')->updateOrInsert(
                ['conversation_id' => $conversationId],
                [
                    'platform_customer_id' => $platformCustomerId,
                    'state' => 'ACTIVE',
                    'current_intent' => $intent,
                    'last_confidence' => $confidence,
                    'human_takeover' => false,
                    'last_customer_message_at' => now(),
                    'last_bot_message_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $this->analytics->record(
            'bot_reply',
            $conversationId,
            $platformCustomerId,
            $feature,
            $intent,
            $confidence,
        );

        return true;
    }

    /** @return array<string, mixed> */
    private function homeInteractive(): array
    {
        $rows = [];
        $labels = [
            'new_order' => $this->t('new_order'),
            'repeat_order' => $this->t('repeat_order'),
            'product_search' => $this->t('product_search'),
            'prices_offers' => $this->t('prices_offers'),
            'order_tracking' => $this->t('order_tracking'),
            'finance' => $this->t('finance'),
            'complaints' => $this->t('complaints'),
            'human_handoff' => $this->t('human_handoff'),
        ];

        foreach ($this->features->visibleEnabled() as $feature) {
            if (! isset($labels[$feature->code])) {
                continue;
            }
            $rows[] = [
                'id' => 'chatbot:'.$feature->code,
                'title' => mb_substr($labels[$feature->code], 0, 24),
            ];
        }

        return [
            'type' => 'list',
            'body' => ['text' => $this->welcome()],
            'action' => [
                'button' => $this->t('choose'),
                'sections' => [[
                    'title' => $this->t('services'),
                    'rows' => array_slice($rows, 0, 10),
                ]],
            ],
        ];
    }

    private function welcome(): string
    {
        $settings = $this->runtime->settings();
        $key = $this->localeKey('welcome');

        return trim((string) ($settings[$key] ?? '')) ?: $this->t('welcome_default');
    }

    private function featureForIntent(string $intent): ?string
    {
        return match ($intent) {
            'new_order' => 'new_order',
            'repeat_order' => 'repeat_order',
            'product_search' => 'product_search',
            'prices_offers' => 'prices_offers',
            'order_tracking' => 'order_tracking',
            'finance' => 'finance',
            'complaints' => 'complaints',
            'human_handoff' => 'human_handoff',
            default => null,
        };
    }

    private function customer(?int $platformCustomerId): ?object
    {
        if ($platformCustomerId === null) {
            return null;
        }

        return DB::table('platform_customers')
            ->where('id', $platformCustomerId)
            ->where('is_active', true)
            ->first();
    }

    private function localeKey(string $prefix): string
    {
        return $prefix.'_'.(app()->getLocale() === 'ar' ? 'ar' : 'en');
    }

    private function t(string $key): string
    {
        return (string) __('chatbot.customer.'.$key);
    }

    private function status(string $status): string
    {
        $key = 'chatbot.customer.statuses.'.strtolower($status);
        $translated = __($key);

        return $translated === $key ? $status : (string) $translated;
    }

    private function menuTextAllowed(string $body): bool
    {
        $body = trim($body);

        return preg_match('/^[1-8]$/', $body) === 1
            || str_contains(mb_strtolower($body, 'UTF-8'), 'menu')
            || str_contains($body, 'القائمة');
    }
}
