<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Events\SmartOrderingOrderStatusChanged;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\SmartOrderingOrderContext;
use App\Models\SmartOrderingOrderEvent;
use App\Models\User;
use App\Services\CommercialPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LifecycleService
{
    private const CANONICAL_STATUS = [
        'NEW' => 'pending',
        'CONFIRMED' => 'confirmed',
        'PREPARING' => 'preparing',
        'READY' => 'ready',
        'OUT_FOR_DELIVERY' => 'out_for_delivery',
        'COMPLETED' => 'delivered',
        'CANCELLED' => 'cancelled',
    ];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CommercialPolicyService $commercialPolicy,
        private readonly SmartOrderingAnalyticsService $analytics,
    ) {}

    public function transition(
        SmartOrderingOrderContext $context,
        User $actor,
        string $target,
        ?string $note = null,
        ?string $eventKey = null,
    ): SmartOrderingOrderContext {
        $target = strtoupper(trim($target));
        abort_unless(array_key_exists($target, self::CANONICAL_STATUS), 422, 'Unsupported lifecycle status.');

        $fromStatus = strtoupper((string) $context->lifecycle_status);
        $changed = false;

        $updated = DB::transaction(function () use ($context, $actor, $target, $note, $eventKey, &$fromStatus, &$changed): SmartOrderingOrderContext {
            $locked = SmartOrderingOrderContext::query()->whereKey($context->getKey())->lockForUpdate()->firstOrFail();
            $from = strtoupper((string) $locked->lifecycle_status);
            $fromStatus = $from;

            if ($from === $target) {
                return $locked;
            }

            $allowed = $this->allowedTargets($from, (string) $locked->fulfillment_mode);
            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Invalid Smart Ordering transition {$from} -> {$target}."],
                ]);
            }

            if ($eventKey !== null && $eventKey !== '') {
                $existing = SmartOrderingOrderEvent::query()
                    ->where('order_context_id', $locked->getKey())
                    ->where('event_key', $eventKey)
                    ->first();
                if ($existing instanceof SmartOrderingOrderEvent) {
                    abort_unless((string) $existing->to_status === $target, 409, 'Transition idempotency key was reused.');

                    return $locked;
                }
            }

            $order = Order::query()->whereKey((int) $locked->order_id)->lockForUpdate()->firstOrFail();

            if ($target === 'CANCELLED') {
                $this->inventory->release($order, (int) $actor->getKey());
                $this->commercialPolicy->releaseOrderReservations((int) $order->getKey());
            } elseif ($target === 'COMPLETED') {
                $this->inventory->consume($order, (int) $actor->getKey());
                DB::table('commercial_quota_reservations')
                    ->where('order_id', $order->getKey())
                    ->where('status', CommercialPolicyService::RESERVATION_RESERVED)
                    ->pluck('reservation_token')
                    ->each(fn (string $token) => $this->commercialPolicy->consumeReservation($token));
            }

            $changed = true;
            $canonical = self::CANONICAL_STATUS[$target];
            $previousCanonical = (string) $order->status;
            $order->update(['status' => $canonical]);
            $locked->update(['lifecycle_status' => $target]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->getKey(),
                'store_id' => (int) $order->store_id,
                'user_id' => $actor->getKey(),
                'from_status' => $previousCanonical,
                'to_status' => $canonical,
                'note' => $note ?: 'smart_ordering_transition',
            ]);

            SmartOrderingOrderEvent::query()->create([
                'order_context_id' => $locked->getKey(),
                'user_id' => $actor->getKey(),
                'from_status' => $from,
                'to_status' => $target,
                'event_key' => $eventKey,
                'note' => $note,
                'metadata' => ['canonical_status' => $canonical],
            ]);

            return $locked->refresh();
        }, 3);

        if ($changed) {
            try {
                event(new SmartOrderingOrderStatusChanged(
                    orderId: (int) $updated->order_id,
                    orderContextId: (int) $updated->getKey(),
                    publicReference: (string) $updated->public_reference,
                    fromStatus: $fromStatus,
                    toStatus: $target,
                    fulfillmentMode: (string) $updated->fulfillment_mode,
                    eventKey: $eventKey,
                ));
            } catch (Throwable) {
                // Messaging is downstream; notification failures never roll back order state.
            }
        }

        if ($changed && in_array($target, ['CONFIRMED', 'COMPLETED', 'CANCELLED'], true)) {
            try {
                $order = Order::query()->findOrFail((int) $updated->order_id);
                $analyticsEvent = match ($target) {
                    'CONFIRMED' => 'order_confirmed',
                    'COMPLETED' => 'order_completed',
                    default => 'order_cancelled',
                };
                $priorCompleted = DB::table('orders')
                    ->where('store_id', (int) $order->store_id)
                    ->where('customer_id', (int) $order->customer_id)
                    ->where('id', '<>', (int) $order->getKey())
                    ->whereIn('status', ['delivered', 'completed'])
                    ->exists();

                $cartAttribution = $updated->cart_id === null
                    ? null
                    : DB::table('smart_ordering_carts')
                        ->where('id', (int) $updated->cart_id)
                        ->first(['qr_public_id', 'source_snapshot', 'campaign_snapshot', 'offer_reference']);

                $this->analytics->record(
                    (int) $order->store_id,
                    $analyticsEvent,
                    'order:'.$order->getKey().':'.$analyticsEvent,
                    [
                        'branch_id' => (int) $updated->branch_id,
                        'qr_public_id' => $cartAttribution?->qr_public_id,
                        'source' => $cartAttribution?->source_snapshot,
                        'campaign' => $cartAttribution?->campaign_snapshot,
                        'offer_reference' => $cartAttribution?->offer_reference,
                        'order_id' => (int) $order->getKey(),
                        'customer_type' => $priorCompleted ? 'repeat' : 'new',
                        'fulfillment_mode' => (string) $updated->fulfillment_mode,
                        'revenue' => $target === 'COMPLETED' ? (float) $order->grand_total : 0.0,
                        'metadata' => ['order_reference' => (string) $order->order_number],
                    ],
                );

                if ($target === 'COMPLETED') {
                    DB::table('order_items as oi')
                        ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
                        ->where('oi.order_id', $order->getKey())
                        ->whereNotNull('oi.product_id')
                        ->distinct()
                        ->get(['oi.product_id', 'p.category_id'])
                        ->each(function (object $line) use ($order, $updated): void {
                            $this->analytics->rollupProductOnly(
                                (int) $order->store_id,
                                (int) $updated->branch_id,
                                (int) $line->product_id,
                                'order_completed',
                            );
                            if ($line->category_id !== null) {
                                $this->analytics->rollupCategoryOnly(
                                    (int) $order->store_id,
                                    (int) $updated->branch_id,
                                    (int) $line->category_id,
                                    'order_completed',
                                );
                            }
                        });
                }
            } catch (Throwable) {
                // Analytics is downstream and never changes or rolls back business transaction state.
            }
        }

        return $updated;
    }

    /** @return list<string> */
    public function allowedTargets(string $status, string $mode): array
    {
        $status = strtoupper($status);

        return match ($status) {
            'NEW' => ['CONFIRMED', 'CANCELLED'],
            'CONFIRMED' => ['PREPARING', 'CANCELLED'],
            'PREPARING' => ['READY', 'CANCELLED'],
            'READY' => $mode === 'delivery'
                ? ['OUT_FOR_DELIVERY', 'CANCELLED']
                : ['COMPLETED', 'CANCELLED'],
            'OUT_FOR_DELIVERY' => ['COMPLETED'],
            default => [],
        };
    }

    /** @return array<string,mixed> */
    public function tracking(string $publicReference): array
    {
        $context = SmartOrderingOrderContext::query()
            ->where('public_reference', $publicReference)
            ->firstOrFail();
        $order = Order::query()->findOrFail((int) $context->order_id);
        $branch = DB::table('smart_ordering_branches')
            ->where('id', $context->branch_id)
            ->first(['name_ar', 'name_en']);

        return [
            'reference' => (string) $context->public_reference,
            'order_number' => (string) $order->order_number,
            'status' => (string) $context->lifecycle_status,
            'fulfillment_mode' => (string) $context->fulfillment_mode,
            'branch' => app()->getLocale() === 'ar'
                ? ($branch?->name_ar ?: $branch?->name_en)
                : ($branch?->name_en ?: $branch?->name_ar),
            'scheduled_for' => $context->scheduled_for === null
                ? null
                : CarbonImmutable::parse((string) $context->scheduled_for)->toAtomString(),
            'promised_for' => $context->promised_for === null
                ? null
                : CarbonImmutable::parse((string) $context->promised_for)->toAtomString(),
            'currency' => (string) $order->currency,
            'total' => (float) $context->grand_total,
            'timeline' => SmartOrderingOrderEvent::query()
                ->where('order_context_id', $context->getKey())
                ->orderBy('id')
                ->get()
                ->map(static fn (SmartOrderingOrderEvent $event): array => [
                    'from' => $event->from_status,
                    'to' => (string) $event->to_status,
                    'occurred_at' => $event->created_at?->toAtomString(),
                ])
                ->all(),
        ];
    }
}
