<?php

namespace App\Domain\WhatsApp;

use App\Domain\SmartOrdering\WhatsApp\SmartOrderingChatbotContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ChatbotAutomationService
{
    /** @var array<string, mixed> */
    private array $currentMetadata = [];

    public function __construct(
        private readonly ChatbotRuntimePolicy $runtime,
        private readonly ChatbotFeatureRegistry $features,
        private readonly ChatbotIntentService $intents,
        private readonly ChatbotAnalyticsService $analytics,
        private readonly ChatbotSegmentationService $segmentation,
        private readonly ChatbotSupportCaseService $supportCases,
        private readonly ChatbotOrderDraftService $orderDrafts,
        private readonly ChatbotFlowEngine $flowEngine,
        private readonly ChatbotCampaignAttributionService $campaignAttribution,
        private readonly ConversationService $conversations,
        private readonly OutboundMessageDispatcher $dispatcher,
        private readonly SmartOrderingChatbotContextService $smartOrdering,
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
        $this->currentMetadata = $metadata;
        $actionRequest = $this->intents->interpret($body, $metadata);
        $intent = $actionRequest->intent;
        $confidence = $actionRequest->confidence;
        $mode = $this->runtime->mode();

        $this->analytics->record(
            'action_interpreted',
            $conversationId,
            $platformCustomerId,
            $this->featureForIntent($intent),
            $intent,
            $confidence,
            [
                'requested_action' => $actionRequest->requestedAction,
                'requires_confirmation' => $actionRequest->requiresConfirmation,
                'entities' => $actionRequest->entities,
            ],
        );

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
                $this->homeInteractive($platformCustomerId),
                'home',
                'fallback',
                $confidence,
            );
        }

        $feature = $this->featureForIntent($intent);
        if ($feature !== null && ! $this->features->enabled($feature)) {
            return $this->send(
                $conversationId,
                $platformCustomerId,
                $this->t('feature_unavailable'),
                $this->homeInteractive($platformCustomerId),
                'home',
                $intent,
                $confidence,
            );
        }

        if ($intent === 'human_handoff') {
            $complaintType = $this->complaintType($metadata);
            if ($complaintType !== null) {
                $caseId = $this->supportCases->create(
                    $conversationId,
                    $platformCustomerId,
                    $complaintType,
                    $body,
                    $metadata,
                );
                $this->analytics->record(
                    'support_case_created',
                    $conversationId,
                    $platformCustomerId,
                    'complaints',
                    'human_handoff',
                    $confidence,
                    ['case_id' => $caseId, 'type' => $complaintType],
                );
            }

            if (! (bool) $settings['human_handoff_enabled']) {
                return $this->send(
                    $conversationId,
                    $platformCustomerId,
                    $complaintType === null ? $this->t('feature_unavailable') : $this->t('case_created'),
                    $this->homeInteractive($platformCustomerId),
                    'complaints',
                    'complaints',
                    $confidence,
                );
            }

            return $this->handoff(
                $conversationId,
                $platformCustomerId,
                $complaintType === null ? 'customer_request' : 'complaint_'.$complaintType,
            );
        }

        $reply = $this->reply($conversationId, $platformCustomerId, $intent);

        if (($reply['handoff'] ?? false) === true) {
            return $this->handoff($conversationId, $platformCustomerId, (string) ($reply['reason'] ?? 'policy'));
        }

        $reply = $this->flowEngine->decorate($intent, $this->locale(), $reply);
        $campaign = $this->campaignAttribution->latestForCustomer($platformCustomerId);
        if ($campaign !== null) {
            $this->analytics->record(
                'campaign_chatbot_entry',
                $conversationId,
                $platformCustomerId,
                $feature,
                $intent,
                $confidence,
                $campaign + [
                    'interactive_id' => is_string($metadata['interactive_id'] ?? null)
                        ? mb_substr((string) $metadata['interactive_id'], 0, 191)
                        : null,
                    'flow_id' => isset($reply['_flow']['id']) ? (int) $reply['_flow']['id'] : null,
                    'flow_version' => isset($reply['_flow']['version']) ? (int) $reply['_flow']['version'] : null,
                ],
            );
        }

        if (isset($reply['_flow']) && is_array($reply['_flow'])) {
            $this->flowEngine->persist($conversationId, $platformCustomerId, $reply['_flow']);
            $this->analytics->record(
                'flow_node_entered',
                $conversationId,
                $platformCustomerId,
                $feature,
                $intent,
                $confidence,
                $reply['_flow'],
            );
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
            'new_order', 'product_search' => $this->productReply($conversationId, $platformCustomerId),
            'repeat_order' => $this->reorderReply($conversationId, $platformCustomerId),
            'add_product' => $this->addProductReply($conversationId, $platformCustomerId),
            'repeat_to_cart' => $this->repeatToCartReply($conversationId, $platformCustomerId),
            'view_cart' => $this->cartReply($conversationId, $platformCustomerId),
            'prices_offers' => $this->priceReply($conversationId, $platformCustomerId),
            'order_tracking' => $this->orderReply($conversationId, $platformCustomerId),
            'finance' => $this->financeReply($platformCustomerId),
            'complaints' => $this->supportReply(),
            default => [
                'body' => $this->welcome(),
                'interactive' => $this->homeInteractive($platformCustomerId),
            ],
        };
    }

    /** @return array<string, mixed> */
    private function productReply(int $conversationId, ?int $platformCustomerId): array
    {
        $conversation = DB::table('whats_app_conversations')->where('id', $conversationId)->first();
        $storeId = $conversation?->store_id === null ? null : (int) $conversation->store_id;

        $query = DB::table('products')
            ->leftJoin('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id');
                if ($storeId !== null) {
                    $join->where('store_products.store_id', $storeId)
                        ->where('store_products.is_active', true);
                } else {
                    $join->whereRaw('1 = 0');
                }
            })
            ->where('products.is_active', true)
            ->select('products.id', 'products.name', 'products.sku', 'store_products.price')
            ->orderBy('products.name')
            ->limit(10);

        $products = $query->get();
        if ($products->isEmpty()) {
            return ['body' => $this->t('no_products'), 'interactive' => $this->homeInteractive($platformCustomerId)];
        }

        $rows = [];
        $lines = [$this->t('product_intro')];
        foreach ($products as $product) {
            $price = $product->price === null ? $this->t('price_on_request') : number_format((float) $product->price, 3);
            $lines[] = '• '.$product->name.' — '.$price;
            if ($platformCustomerId !== null && $product->price !== null) {
                $rows[] = [
                    'id' => 'chatbot:add_product:'.$product->id,
                    'title' => mb_substr((string) $product->name, 0, 24),
                    'description' => mb_substr((string) $product->sku.' · '.$price, 0, 72),
                ];
            }
        }
        $lines[] = $this->t('product_hint');

        $interactive = $rows === [] ? $this->homeInteractive($platformCustomerId) : [
            'type' => 'list',
            'body' => ['text' => $this->t('choose_product')],
            'action' => [
                'button' => $this->t('choose'),
                'sections' => [[
                    'title' => $this->t('products'),
                    'rows' => $rows,
                ]],
            ],
        ];

        return ['body' => implode("\n", $lines), 'interactive' => $interactive];
    }

    /** @return array<string, mixed> */
    private function reorderReply(int $conversationId, ?int $platformCustomerId): array
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
            return ['body' => $this->t('no_previous_order'), 'interactive' => $this->homeInteractive($platformCustomerId)];
        }

        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get();
        $lines = [$this->t('reorder_intro').' #'.$order->order_number];
        foreach ($items->take(8) as $item) {
            $lines[] = '• '.$item->name_snapshot.' × '.rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.');
        }
        $lines[] = $this->t('draft_only');

        return [
            'body' => implode("\n", $lines),
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $this->t('reorder_confirm_prompt')],
                'action' => [
                    'button' => $this->t('choose'),
                    'sections' => [[
                        'title' => $this->t('reorder_actions'),
                        'rows' => [
                            ['id' => 'chatbot:repeat_to_cart', 'title' => mb_substr($this->t('add_previous_to_cart'), 0, 24)],
                            ['id' => 'chatbot:view_cart', 'title' => mb_substr($this->t('view_cart'), 0, 24)],
                        ],
                    ]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function addProductReply(int $conversationId, ?int $platformCustomerId): array
    {
        if ($platformCustomerId === null) {
            return ['body' => $this->t('identity_required'), 'handoff' => true, 'reason' => 'identity_required'];
        }

        $productId = $this->interactiveNumericId('chatbot:add_product:');
        if ($productId === null) {
            return ['body' => $this->t('product_unavailable'), 'interactive' => $this->homeInteractive($platformCustomerId)];
        }

        try {
            $result = $this->orderDrafts->addProduct($conversationId, $platformCustomerId, $productId);
        } catch (ValidationException) {
            return ['body' => $this->t('product_unavailable'), 'interactive' => $this->homeInteractive($platformCustomerId)];
        }

        $this->analytics->record(
            'draft_product_added',
            $conversationId,
            $platformCustomerId,
            'new_order',
            'add_product',
            1.0,
            [
                'product_id' => $productId,
                'cart_id' => $result['cart_id'],
                'item_count' => $result['item_count'],
                'draft_value' => $result['total'],
            ],
        );

        return $this->cartReply($conversationId, $platformCustomerId, $this->t('added_to_cart'));
    }

    /** @return array<string, mixed> */
    private function repeatToCartReply(int $conversationId, ?int $platformCustomerId): array
    {
        if ($platformCustomerId === null) {
            return ['body' => $this->t('identity_required'), 'handoff' => true, 'reason' => 'identity_required'];
        }

        try {
            $result = $this->orderDrafts->repeatLatestOrder($conversationId, $platformCustomerId);
        } catch (ValidationException) {
            return ['body' => $this->t('no_previous_order'), 'interactive' => $this->homeInteractive($platformCustomerId)];
        }

        $this->analytics->record(
            'draft_reorder_created',
            $conversationId,
            $platformCustomerId,
            'repeat_order',
            'repeat_to_cart',
            1.0,
            [
                'cart_id' => $result['cart_id'],
                'item_count' => $result['item_count'],
                'draft_value' => $result['total'],
                'skipped' => $result['skipped'],
            ],
        );

        $prefix = $this->t('reorder_added');
        if (($result['skipped']) > 0) {
            $prefix .= ' '.$this->t('some_items_skipped');
        }

        return $this->cartReply($conversationId, $platformCustomerId, $prefix);
    }

    /** @return array<string, mixed> */
    private function cartReply(int $conversationId, ?int $platformCustomerId, ?string $prefix = null): array
    {
        if ($platformCustomerId === null) {
            return ['body' => $this->t('identity_required'), 'handoff' => true, 'reason' => 'identity_required'];
        }

        try {
            $items = $this->orderDrafts->items($conversationId, $platformCustomerId);
        } catch (ValidationException) {
            return ['body' => $this->t('identity_required'), 'handoff' => true, 'reason' => 'identity_required'];
        }

        $lines = [];
        if ($prefix !== null) {
            $lines[] = $prefix;
        }
        $lines[] = $this->t('cart_summary');

        $total = 0.0;
        foreach ($items->take(12) as $item) {
            $quantity = (float) $item->quantity;
            $price = (float) ($item->unit_price_snapshot ?? 0);
            $total += $quantity * $price;
            $lines[] = '• '.$item->name.' × '.rtrim(rtrim(number_format($quantity, 3), '0'), '.').' — '.number_format($quantity * $price, 3);
        }
        if ($items->isEmpty()) {
            $lines[] = $this->t('empty_cart');
        } else {
            $lines[] = $this->t('estimated_total').': '.number_format($total, 3).' KWD';
            $lines[] = $this->t('draft_only');
        }

        return ['body' => implode("\n", $lines), 'interactive' => $this->homeInteractive($platformCustomerId)];
    }

    /** @return array<string, mixed> */
    private function priceReply(int $conversationId, ?int $platformCustomerId): array
    {
        $reply = $this->productReply($conversationId, $platformCustomerId);
        $reply['body'] = $this->t('price_intro')."\n".$reply['body'];

        return $reply;
    }

    /** @return array<string, mixed> */
    private function orderReply(int $conversationId, ?int $platformCustomerId): array
    {
        $customer = $this->customer($platformCustomerId);
        if ($customer === null) {
            return ['body' => $this->t('identity_required'), 'handoff' => true, 'reason' => 'identity_required'];
        }

        $smartOrders = $this->smartOrdering->recentOrders($conversationId, $platformCustomerId);
        if ($smartOrders !== []) {
            $lines = [$this->t('orders_intro')];
            foreach ($smartOrders as $order) {
                $lines[] = '• #'.$order['order_number'].' — '.$this->status((string) $order['status']).' — '.number_format((float) $order['total'], 3).' '.$order['currency'];
            }

            return ['body' => implode("\n", $lines), 'interactive' => $this->homeInteractive($platformCustomerId)];
        }

        $orders = DB::table('orders')
            ->where('customer_id', (int) $customer->legacy_customer_id)
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        if ($orders->isEmpty()) {
            return ['body' => $this->t('no_orders'), 'interactive' => $this->homeInteractive($platformCustomerId)];
        }

        $lines = [$this->t('orders_intro')];
        foreach ($orders as $order) {
            $lines[] = '• #'.$order->order_number.' — '.$this->status((string) $order->status).' — '.number_format((float) $order->grand_total, 3).' '.$order->currency;
        }

        return ['body' => implode("\n", $lines), 'interactive' => $this->homeInteractive($platformCustomerId)];
    }

    /** @return array<string, mixed> */
    private function financeReply(?int $platformCustomerId): array
    {
        if (! $this->features->enabled('finance')) {
            return ['body' => $this->t('feature_unavailable'), 'interactive' => $this->homeInteractive($platformCustomerId)];
        }

        if ($this->customer($platformCustomerId) === null) {
            return ['body' => $this->t('finance_secure'), 'handoff' => true, 'reason' => 'finance_identity'];
        }

        return [
            'body' => $this->t('finance_secure'),
            'handoff' => true,
            'reason' => 'finance_verification_required',
        ];
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
                            ['id' => 'chatbot:human_handoff_missing', 'title' => mb_substr($this->t('missing_item'), 0, 24)],
                            ['id' => 'chatbot:human_handoff_damaged', 'title' => mb_substr($this->t('damaged_item'), 0, 24)],
                            ['id' => 'chatbot:human_handoff_quantity', 'title' => mb_substr($this->t('incorrect_quantity'), 0, 24)],
                            ['id' => 'chatbot:human_handoff_delivery', 'title' => mb_substr($this->t('delivery_problem'), 0, 24)],
                            ['id' => 'chatbot:human_handoff_invoice', 'title' => mb_substr($this->t('invoice_problem'), 0, 24)],
                            ['id' => 'chatbot:human_handoff_other', 'title' => mb_substr($this->t('other_problem'), 0, 24)],
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

        $agentId = $this->conversations->autoAssign($conversationId);
        if ($agentId !== null && Schema::hasTable('customer_service_cases')) {
            DB::table('customer_service_cases')
                ->where('conversation_id', $conversationId)
                ->where('status', 'open')
                ->whereNull('assigned_agent_id')
                ->update(['assigned_agent_id' => $agentId, 'updated_at' => now()]);
        }

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
            // Meta renders interactive.body.text as the visible message body and does
            // not separately render whats_app_messages.body. Keep both surfaces in
            // sync so a resolved service result is not replaced by a generic menu.
            $interactive['body'] = is_array($interactive['body'] ?? null)
                ? $interactive['body']
                : [];
            $interactive['body']['text'] = mb_substr(trim($body), 0, 1024);
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
    private function homeInteractive(?int $platformCustomerId = null): array
    {
        $rows = [];
        $segmentCodes = $this->segmentation->codesFor($platformCustomerId);
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

        foreach ($this->features->visibleEnabled($segmentCodes) as $feature) {
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
            'new_order', 'add_product', 'view_cart' => 'new_order',
            'repeat_order', 'repeat_to_cart' => 'repeat_order',
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
        return $prefix.'_'.$this->locale();
    }

    private function t(string $key): string
    {
        return (string) Lang::get('chatbot.customer.'.$key, [], $this->locale());
    }

    private function status(string $status): string
    {
        $key = 'chatbot.customer.statuses.'.strtolower($status);
        $translated = Lang::get($key, [], $this->locale());

        return $translated === $key ? $status : (string) $translated;
    }

    private function interactiveNumericId(string $prefix): ?int
    {
        $id = $this->currentMetadata['interactive_id'] ?? null;
        if (! is_string($id) || ! str_starts_with($id, $prefix)) {
            return null;
        }

        $value = substr($id, strlen($prefix));

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param array<string, mixed> $metadata */
    private function complaintType(array $metadata): ?string
    {
        $id = $metadata['interactive_id'] ?? null;
        if (! is_string($id)) {
            return null;
        }

        return match ($id) {
            'chatbot:human_handoff_missing' => 'missing',
            'chatbot:human_handoff_damaged' => 'damaged',
            'chatbot:human_handoff_quantity' => 'incorrect_quantity',
            'chatbot:human_handoff_delivery' => 'delivery',
            'chatbot:human_handoff_invoice' => 'invoice',
            'chatbot:human_handoff_other' => 'other',
            default => null,
        };
    }

    private function locale(): string
    {
        $locale = strtolower((string) ($this->runtime->settings()['default_locale'] ?? 'ar'));

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';
    }

    private function menuTextAllowed(string $body): bool
    {
        $body = trim($body);

        return preg_match('/^[1-8]$/', $body) === 1
            || str_contains(mb_strtolower($body, 'UTF-8'), 'menu')
            || str_contains($body, 'القائمة');
    }
}
