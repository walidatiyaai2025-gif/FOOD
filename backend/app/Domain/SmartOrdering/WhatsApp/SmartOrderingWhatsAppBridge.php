<?php

namespace App\Domain\SmartOrdering\WhatsApp;

use App\Domain\SmartOrdering\Events\SmartOrderingOrderCreated;
use App\Domain\SmartOrdering\Events\SmartOrderingOrderStatusChanged;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\RuntimeState;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Domain\WhatsApp\ConversationService;
use App\Domain\WhatsApp\ModuleState;
use App\Domain\WhatsApp\TransactionalNotificationEngine;
use App\Models\Order;
use App\Models\SmartOrderingOrderContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SmartOrderingWhatsAppBridge
{
    public function __construct(
        private readonly SmartOrderingRuntimePolicy $smartOrderingRuntime,
        private readonly TransactionalNotificationEngine $notifications,
        private readonly ConversationService $conversations,
    ) {}

    public function handleCreated(SmartOrderingOrderCreated $event): void
    {
        $this->notify(
            orderId: $event->orderId,
            contextId: null,
            eventName: 'order.created',
            eventKey: 'smart-ordering:'.$event->publicReference.':received',
        );
    }

    public function handleStatusChanged(SmartOrderingOrderStatusChanged $event): void
    {
        $eventName = $this->eventForStatus($event->toStatus, $event->fulfillmentMode);
        if ($eventName === null) {
            $this->refreshLink($event->orderId, $event->orderContextId, null, null);

            return;
        }

        $this->notify(
            orderId: $event->orderId,
            contextId: $event->orderContextId,
            eventName: $eventName,
            eventKey: 'smart-ordering:'.$event->publicReference.':'.strtolower($event->toStatus),
        );
    }

    public function scheduledReminder(int|SmartOrderingOrderContext $context): ?int
    {
        $context = $context instanceof SmartOrderingOrderContext
            ? $context
            : SmartOrderingOrderContext::query()->find($context);

        if (! $context instanceof SmartOrderingOrderContext || $context->scheduled_for === null) {
            return null;
        }

        return $this->notify(
            orderId: (int) $context->order_id,
            contextId: (int) $context->getKey(),
            eventName: 'order.scheduled_reminder',
            eventKey: 'smart-ordering:'.$context->public_reference.':scheduled-reminder',
        );
    }

    private function notify(
        int $orderId,
        ?int $contextId,
        string $eventName,
        string $eventKey,
    ): ?int {
        try {
            $order = Order::query()->find($orderId);
            if (! $order instanceof Order || strtolower((string) $order->channel) !== 'b2c') {
                return null;
            }

            $context = $contextId === null
                ? SmartOrderingOrderContext::query()->where('order_id', $orderId)->first()
                : SmartOrderingOrderContext::query()->find($contextId);
            if (! $context instanceof SmartOrderingOrderContext) {
                return null;
            }

            $branch = SmartOrderingBranch::query()->with('site')->find((int) $context->branch_id);
            if (! $branch instanceof SmartOrderingBranch || $branch->site === null) {
                return null;
            }

            $identity = $this->identity($order);
            $conversationId = $this->existingConversation(
                (int) $order->store_id,
                $identity['platform_customer_id'],
                $identity['phone'],
            );

            $this->persistLink($order, $context, $branch, $identity['platform_customer_id'], $conversationId, null, null);

            if (! $this->smartOrderingAllowsWhatsApp($branch)) {
                $this->persistLink(
                    $order,
                    $context,
                    $branch,
                    $identity['platform_customer_id'],
                    $conversationId,
                    $eventName,
                    'skipped_smart_ordering_runtime',
                );

                return null;
            }

            if ($identity['phone'] === null) {
                $this->persistLink(
                    $order,
                    $context,
                    $branch,
                    $identity['platform_customer_id'],
                    $conversationId,
                    $eventName,
                    'skipped_recipient_unavailable',
                );

                return null;
            }

            $payload = $this->safeSnapshot($order, $context, $branch);
            $eventId = $this->notifications->forOrder(
                $order,
                $eventName,
                $eventKey,
                ['smart_ordering' => $payload],
            );

            $status = $eventId === null
                ? 'not_recorded'
                : (string) (DB::table('whats_app_transactional_events')->where('id', $eventId)->value('status') ?? 'recorded');

            if ($conversationId === null && $status === 'queued') {
                $conversationId = $this->conversations->findOrCreateForSystem(
                    $identity['platform_customer_id'],
                    (int) $order->store_id,
                    $identity['phone'],
                );
            }

            $this->persistLink(
                $order,
                $context,
                $branch,
                $identity['platform_customer_id'],
                $conversationId,
                $eventName,
                $status,
                $eventId,
            );

            return $eventId;
        } catch (Throwable $exception) {
            try {
                if ($contextId !== null && Schema::hasTable('whats_app_smart_ordering_links')) {
                    DB::table('whats_app_smart_ordering_links')
                        ->where('order_context_id', $contextId)
                        ->update([
                            'last_event_status' => 'bridge_failed',
                            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                            'updated_at' => now(),
                        ]);
                }
            } catch (Throwable) {
                // Optional WhatsApp bridge failures must remain isolated from ordering.
            }

            return null;
        }
    }

    private function refreshLink(
        int $orderId,
        int $contextId,
        ?string $eventName,
        ?string $status,
    ): void {
        $order = Order::query()->find($orderId);
        $context = SmartOrderingOrderContext::query()->find($contextId);
        if (! $order instanceof Order || ! $context instanceof SmartOrderingOrderContext) {
            return;
        }

        $branch = SmartOrderingBranch::query()->with('site')->find((int) $context->branch_id);
        if (! $branch instanceof SmartOrderingBranch) {
            return;
        }

        $identity = $this->identity($order);
        $conversationId = $this->existingConversation(
            (int) $order->store_id,
            $identity['platform_customer_id'],
            $identity['phone'],
        );

        $this->persistLink(
            $order,
            $context,
            $branch,
            $identity['platform_customer_id'],
            $conversationId,
            $eventName,
            $status,
        );
    }

    private function smartOrderingAllowsWhatsApp(SmartOrderingBranch $branch): bool
    {
        $site = $branch->site;
        if ($site === null
            || ! $branch->is_active
            || trim((string) $branch->whatsapp_routing_identity) === ''
            || ! $this->smartOrderingRuntime->entitled($site, Module::WHATSAPP, $branch)) {
            return false;
        }

        return in_array($this->smartOrderingRuntime->effectiveState($site), [
            RuntimeState::INTERNAL_TEST,
            RuntimeState::PILOT,
            RuntimeState::ENABLED,
        ], true);
    }

    /** @return array{platform_customer_id:?int,phone:?string} */
    private function identity(Order $order): array
    {
        $platform = DB::table('platform_customers')
            ->where('legacy_customer_id', (int) $order->customer_id)
            ->where('is_active', true)
            ->first(['id', 'phone']);

        return [
            'platform_customer_id' => $platform?->id === null ? null : (int) $platform->id,
            'phone' => $this->normalizePhone($platform?->phone),
        ];
    }

    private function existingConversation(int $storeId, ?int $platformCustomerId, ?string $phone): ?int
    {
        if (! Schema::hasTable('whats_app_conversations')) {
            return null;
        }

        $query = DB::table('whats_app_conversations')
            ->where('store_id', $storeId)
            ->where('channel', 'whatsapp')
            ->whereNotIn('status', ['RESOLVED', 'CLOSED']);

        if ($platformCustomerId !== null) {
            $query->where('platform_customer_id', $platformCustomerId);
        } elseif ($phone !== null) {
            $query->whereNull('platform_customer_id')->where('external_participant_phone', $phone);
        } else {
            return null;
        }

        $id = $query->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array<string,mixed> */
    private function safeSnapshot(
        Order $order,
        SmartOrderingOrderContext $context,
        SmartOrderingBranch $branch,
    ): array {
        $items = DB::table('order_items')
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->limit(50)
            ->get(['name_snapshot', 'quantity', 'unit_price'])
            ->map(static fn (object $item): array => [
                'name' => (string) $item->name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
            ])
            ->all();

        $tableLabel = $context->table_id === null
            ? null
            : DB::table('smart_ordering_tables')->where('id', $context->table_id)->value('label');

        return [
            'reference' => (string) $context->public_reference,
            'order_number' => (string) $order->order_number,
            'lifecycle_status' => (string) $context->lifecycle_status,
            'fulfillment_mode' => (string) $context->fulfillment_mode,
            'branch' => [
                'public_id' => (string) $branch->public_id,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
            ],
            'scheduled_for' => $context->scheduled_for === null
                ? null
                : CarbonImmutable::parse((string) $context->scheduled_for)->toAtomString(),
            'table' => $tableLabel === null ? null : ['label' => (string) $tableLabel],
            'delivery_address' => $context->delivery_address,
            'note' => $order->customer_note,
            'currency' => (string) $order->currency,
            'totals' => [
                'subtotal' => (float) $context->subtotal,
                'discount' => (float) $context->discount_total,
                'delivery' => (float) $context->delivery_total,
                'service_charge' => (float) $context->service_charge_total,
                'tax' => (float) $context->tax_total,
                'grand_total' => (float) $context->grand_total,
            ],
            'items' => $items,
        ];
    }

    private function persistLink(
        Order $order,
        SmartOrderingOrderContext $context,
        SmartOrderingBranch $branch,
        ?int $platformCustomerId,
        ?int $conversationId,
        ?string $eventName,
        ?string $eventStatus,
        ?int $transactionalEventId = null,
    ): void {
        if (! Schema::hasTable('whats_app_smart_ordering_links')) {
            return;
        }

        $now = now();
        $values = [
            'conversation_id' => $conversationId,
            'order_id' => (int) $order->getKey(),
            'store_id' => (int) $order->store_id,
            'branch_id' => (int) $branch->getKey(),
            'platform_customer_id' => $platformCustomerId,
            'public_reference' => (string) $context->public_reference,
            'fulfillment_mode' => (string) $context->fulfillment_mode,
            'lifecycle_status' => (string) $context->lifecycle_status,
            'snapshot' => json_encode(
                $this->safeSnapshot($order, $context, $branch),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'last_event_name' => $eventName,
            'last_event_status' => $eventStatus,
            'last_transactional_event_id' => $transactionalEventId,
            'last_error' => null,
            'updated_at' => $now,
        ];

        DB::table('whats_app_smart_ordering_links')->updateOrInsert(
            ['order_context_id' => (int) $context->getKey()],
            $values + ['created_at' => $now],
        );
    }

    private function eventForStatus(string $status, string $mode): ?string
    {
        return match (strtoupper(trim($status))) {
            'CONFIRMED' => 'order.confirmed',
            'PREPARING' => 'order.preparing',
            'READY' => strtolower($mode) === 'pickup' ? 'order.ready_for_pickup' : null,
            'OUT_FOR_DELIVERY' => 'order.dispatched',
            'COMPLETED' => 'order.delivered',
            'CANCELLED' => 'order.cancelled',
            default => null,
        };
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }

        $normalized = '+'.$digits;

        return ModuleState::validNumber($normalized) ? $normalized : null;
    }
}
