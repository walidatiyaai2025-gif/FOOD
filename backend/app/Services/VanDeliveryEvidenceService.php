<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderVanAssignment;
use App\Models\OrderVanExecutionEvent;
use App\Models\OrderVanExecutionState;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class VanDeliveryEvidenceService
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
    ) {}

    /** @return list<array<string,mixed>> */
    public function order(User $actor, Order $order): array
    {
        abort_unless(strtolower((string) $order->channel) === 'b2b', 404);

        $this->scope->assertStore(
            $actor,
            (int) $order->store_id,
            'orders.view',
            'b2b',
        );

        return OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(fn (OrderVanAssignment $assignment): array => $this->serialize($assignment))
            ->all();
    }

    public function proof(
        User $actor,
        int $assignmentId,
        int $eventId,
    ): OrderVanExecutionEvent {
        $assignment = OrderVanAssignment::query()->findOrFail($assignmentId);
        $order = Order::query()
            ->whereKey($assignment->order_id)
            ->where('channel', 'b2b')
            ->firstOrFail();

        $this->scope->assertStore(
            $actor,
            (int) $order->store_id,
            'orders.view',
            'b2b',
        );

        return OrderVanExecutionEvent::query()
            ->whereKey($eventId)
            ->where('order_van_assignment_id', $assignment->getKey())
            ->where('order_id', $order->getKey())
            ->whereNotNull('proof_path')
            ->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function serialize(OrderVanAssignment $assignment): array
    {
        $van = DB::table('vans')
            ->where('id', $assignment->van_id)
            ->first(['code', 'plate_number']);
        $state = OrderVanExecutionState::query()
            ->where('order_van_assignment_id', $assignment->getKey())
            ->first();

        $timeline = [];
        foreach (
            OrderVanExecutionEvent::query()
                ->where('order_van_assignment_id', $assignment->getKey())
                ->where('order_id', $assignment->order_id)
                ->orderBy('id')
                ->get() as $event
        ) {
            $hasProof = is_string($event->proof_path)
                && trim((string) $event->proof_path) !== '';

            $timeline[] = [
                'id' => (int) $event->getKey(),
                'action' => (string) $event->action,
                'from_status' => (string) $event->from_status,
                'to_status' => (string) $event->to_status,
                'proof_type' => $event->proof_type,
                'reason_code' => $event->reason_code,
                'note' => $event->note,
                'captured_at' => $this->isoTimestamp($event->getAttribute('captured_at')),
                'proof' => $hasProof ? [
                    'available' => true,
                    'url' => route('admin.operations.orders.van-proofs.show', [
                        'assignment' => $assignment->getKey(),
                        'proof' => $event->getKey(),
                    ]),
                ] : null,
            ];
        }

        $vanLabel = $van === null
            ? '#'.$assignment->van_id
            : trim((string) $van->code)
                .($van->plate_number ? ' · '.$van->plate_number : '');

        return [
            'id' => (int) $assignment->getKey(),
            'order_id' => (int) $assignment->order_id,
            'van_id' => (int) $assignment->van_id,
            'van_name' => $vanLabel,
            'assignment_status' => (string) $assignment->status,
            'execution_status' => $state instanceof OrderVanExecutionState
                ? (string) $state->status
                : null,
            'assigned_at' => $this->isoTimestamp($assignment->getAttribute('assigned_at')),
            'ended_at' => $this->isoTimestamp($assignment->getAttribute('ended_at')),
            'timeline' => $timeline,
        ];
    }

    private function isoTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toISOString();
    }
}
