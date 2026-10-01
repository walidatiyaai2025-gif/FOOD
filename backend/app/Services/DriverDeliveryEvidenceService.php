<?php

namespace App\Services;

use App\Models\DeliveryProof;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DriverDeliveryEvidenceService
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
    ) {}

    /** @return array<string,mixed> */
    public function assignment(User $actor, int $assignmentId): array
    {
        $assignment = DriverAssignment::query()->findOrFail($assignmentId);
        $this->authorizeAssignment($actor, $assignment);

        return $this->serialize($assignment);
    }

    /** @return list<array<string,mixed>> */
    public function order(User $actor, Order $order): array
    {
        $this->scope->assertStore(
            $actor,
            (int) $order->store_id,
            'orders.view',
            (string) $order->channel,
        );

        return DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(fn (DriverAssignment $assignment): array => $this->serialize($assignment))
            ->all();
    }

    public function proof(User $actor, int $assignmentId, int $proofId): DeliveryProof
    {
        $assignment = DriverAssignment::query()->findOrFail($assignmentId);
        $this->authorizeAssignment($actor, $assignment);

        return DeliveryProof::query()
            ->whereKey($proofId)
            ->where('driver_assignment_id', $assignment->getKey())
            ->where('order_id', $assignment->order_id)
            ->whereNotNull('file_path')
            ->firstOrFail();
    }

    private function authorizeAssignment(User $actor, DriverAssignment $assignment): void
    {
        $storeId = (int) $assignment->store_id;
        $channel = strtolower((string) $assignment->assignment_type);

        $allowed = collect([
            ...$this->scope->allowedStoreIds($actor, 'orders.view', $channel),
            ...$this->scope->allowedStoreIds($actor, 'drivers.tracking.view', $channel),
        ])->containsStrict($storeId);

        abort_unless($allowed, 404);
    }

    /** @return array<string,mixed> */
    private function serialize(DriverAssignment $assignment): array
    {
        $driverName = DB::table('drivers')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->where('drivers.id', $assignment->driver_id)
            ->value('users.name');
        $orderNumber = DB::table('orders')
            ->where('id', $assignment->order_id)
            ->value('order_number');

        $timeline = DeliveryProof::query()
            ->where('driver_assignment_id', $assignment->getKey())
            ->where('order_id', $assignment->order_id)
            ->orderBy('id')
            ->get()
            ->map(function (DeliveryProof $proof) use ($assignment): array {
                $hasImage = is_string($proof->file_path) && trim($proof->file_path) !== '';

                return [
                    'id' => (int) $proof->getKey(),
                    'from_status' => $proof->from_status,
                    'to_status' => $proof->to_status,
                    'proof_type' => $proof->proof_type,
                    'reason_code' => $proof->reason_code,
                    'note' => $proof->note,
                    'captured_at' => $proof->captured_at?->toISOString(),
                    'proof' => $hasImage ? [
                        'available' => true,
                        'url' => route('admin.driver-live-tracking.proofs.show', [
                            'assignment' => $assignment->getKey(),
                            'proof' => $proof->getKey(),
                        ]),
                    ] : null,
                ];
            })
            ->all();

        return [
            'id' => (int) $assignment->getKey(),
            'order_id' => (int) $assignment->order_id,
            'order_number' => $orderNumber === null ? null : (string) $orderNumber,
            'driver_id' => (int) $assignment->driver_id,
            'driver_name' => $driverName === null ? '#'.$assignment->driver_id : (string) $driverName,
            'store_id' => (int) $assignment->store_id,
            'channel' => strtolower((string) $assignment->assignment_type),
            'status' => (string) $assignment->status,
            'assigned_at' => $assignment->assigned_at?->toISOString(),
            'completed_at' => $assignment->completed_at?->toISOString(),
            'timeline' => $timeline,
        ];
    }
}
