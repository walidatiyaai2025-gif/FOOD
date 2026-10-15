<?php

namespace App\Domain\SmartOrdering;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\SmartOrderingOrderContext;
use App\Models\SmartOrderingOrderEvent;
use App\Models\User;
use App\Services\CommercialPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        return DB::transaction(function () use ($context, $actor, $target, $note, $eventKey): SmartOrderingOrderContext {
            $locked = SmartOrderingOrderContext::query()->whereKey($context->getKey())->lockForUpdate()->firstOrFail();
            $from = strtoupper((string) $locked->lifecycle_status);

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
            }

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
        $branch = DB::table('smart_ordering_branches')->where('id', $context->branch_id)->first();

        return [
            'reference' => (string) $context->public_reference,
            'order_number' => (string) $order->order_number,
            'status' => (string) $context->lifecycle_status,
            'fulfillment_mode' => (string) $context->fulfillment_mode,
            'branch' => $branch?->name,
            'scheduled_for' => $context->scheduled_for?->toAtomString(),
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
