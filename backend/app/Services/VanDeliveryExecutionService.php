<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderVanAssignment;
use App\Models\OrderVanExecutionEvent;
use App\Models\OrderVanExecutionState;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class VanDeliveryExecutionService
{
    public function __construct(
        private readonly DeliveryExecutionService $execution,
        private readonly VanExecutionStateService $states,
        private readonly DashboardOperationalNotifier $notifier,
    ) {}

    /** @param array<string,mixed> $runtime */
    public function snapshot(User $actor, array $runtime, int $orderId): array
    {
        [$assignment, $state, $order] = $this->ownedExecution($actor, $runtime, $orderId);

        return $this->payload($assignment, $state, $order);
    }

    /**
     * @param  array<string,mixed>  $runtime
     * @return array<string,mixed>
     */
    public function transition(
        User $actor,
        array $runtime,
        int $orderId,
        string $targetStatus,
        ?string $note,
        Request $request,
        ?UploadedFile $proofImage = null,
        ?string $failureReason = null,
        ?string $idempotencyKey = null,
    ): array {
        $normalizedNote = $this->execution->normalizeNote($note);
        $normalizedFailureReason = $this->execution->normalizeFailureReason($targetStatus, $failureReason);
        $idempotencyKey = $this->execution->normalizeIdempotencyKey($idempotencyKey);
        $requestFingerprint = $idempotencyKey === null
            ? null
            : $this->execution->transitionFingerprint(
                $targetStatus,
                $normalizedNote,
                $normalizedFailureReason,
                $proofImage,
            );
        $proofPath = null;
        $beforeOrder = null;
        $afterOrder = null;
        $replayed = false;

        try {
            [$assignment, $state, $order] = DB::transaction(function () use (
                $actor,
                $runtime,
                $orderId,
                $targetStatus,
                $normalizedNote,
                $request,
                $proofImage,
                $normalizedFailureReason,
                $idempotencyKey,
                $requestFingerprint,
                &$proofPath,
                &$beforeOrder,
                &$afterOrder,
                &$replayed,
            ): array {
                [$assignment, $state, $order] = $this->ownedExecution(
                    $actor,
                    $runtime,
                    $orderId,
                    lock: true,
                );

                if ($idempotencyKey !== null) {
                    $prior = OrderVanExecutionEvent::query()
                        ->where('order_van_assignment_id', $assignment->getKey())
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($prior instanceof OrderVanExecutionEvent) {
                        abort_unless((string) $prior->action === 'transition', 409);
                        $this->execution->assertReplayMatches(
                            (string) $prior->to_status,
                            $prior->request_fingerprint,
                            $targetStatus,
                            $requestFingerprint,
                        );
                        $replayed = true;

                        return [$assignment, $state, $order];
                    }
                }

                $beforeState = (string) $state->status;
                $this->execution->assertTransitionAllowed($beforeState, $order, $targetStatus);

                $hasStoredDeliveryProof = $targetStatus === 'delivered'
                    && $this->hasCurrentDeliveryProof($assignment, $state);

                $this->execution->assertTransitionRequirements(
                    $order,
                    $targetStatus,
                    $normalizedNote,
                    $normalizedFailureReason,
                    $proofImage,
                    $hasStoredDeliveryProof,
                );

                $orderSync = $this->execution->synchronizeOrder(
                    $order,
                    $actor,
                    $beforeState,
                    $targetStatus,
                    $normalizedNote,
                    $normalizedFailureReason,
                    $request,
                    'van',
                );
                $beforeOrder = $orderSync['before'];
                $afterOrder = $orderSync['after'];

                if ($proofImage !== null && in_array($targetStatus, ['delivered', 'failed'], true)) {
                    $storedPath = $proofImage->store('delivery-proofs/van', 'public');
                    if (! is_string($storedPath) || $storedPath === '') {
                        throw ValidationException::withMessages([
                            'proof_image' => ['The delivery proof image could not be stored.'],
                        ]);
                    }
                    $proofPath = $storedPath;
                }

                $proofType = match (true) {
                    $targetStatus === 'delivered' && $proofPath !== null => 'delivery_image',
                    $targetStatus === 'failed' && $proofPath !== null => 'failure_image',
                    $targetStatus === 'failed' => 'failure_note',
                    default => 'status_note',
                };

                $event = OrderVanExecutionEvent::query()->create([
                    'order_van_assignment_id' => $assignment->getKey(),
                    'order_van_execution_state_id' => $state->getKey(),
                    'order_id' => $order->getKey(),
                    'van_id' => $assignment->van_id,
                    'user_id' => $actor->getKey(),
                    'action' => 'transition',
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $requestFingerprint,
                    'from_status' => $beforeState,
                    'to_status' => $targetStatus,
                    'proof_type' => $proofType,
                    'proof_path' => $proofPath,
                    'reason_code' => $targetStatus === 'failed' ? $normalizedFailureReason : null,
                    'note' => $normalizedNote,
                    'captured_at' => now(),
                ]);

                $context = $this->contextArray($state->getAttribute('context'));
                $state->forceFill([
                    'status' => $targetStatus,
                    'failure_reason_code' => $targetStatus === 'failed' ? $normalizedFailureReason : null,
                    'failure_note' => $targetStatus === 'failed' ? $normalizedNote : null,
                    'version' => ((int) $state->version) + 1,
                    'context' => [
                        ...$context,
                        'last_actor_user_id' => (int) $actor->getKey(),
                        'last_source' => 'van',
                        'last_event_id' => (int) $event->getKey(),
                    ],
                    'last_transition_at' => now(),
                ])->save();

                $this->execution->recordTransitionAudit(
                    $state,
                    $actor,
                    $beforeState,
                    $targetStatus,
                    $normalizedFailureReason,
                    $normalizedNote,
                    $idempotencyKey,
                    $request,
                    'van.delivery.execution.status_changed',
                );

                return [$assignment, $state->fresh(), $order->fresh()];
            }, 3);
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                Storage::disk('public')->delete($proofPath);
            }

            throw $exception;
        }

        if (! $replayed && $beforeOrder !== null && $afterOrder !== null && $beforeOrder !== $afterOrder) {
            $this->notifier->orderStatusChanged($order, $beforeOrder, $afterOrder);
        }

        return [
            ...$this->payload($assignment, $state, $order),
            'replayed' => $replayed,
        ];
    }

    /**
     * @param  array<string,mixed>  $runtime
     * @return array<string,mixed>
     */
    public function uploadProof(
        User $actor,
        array $runtime,
        int $orderId,
        UploadedFile $proofImage,
        ?string $note,
        Request $request,
        string $idempotencyKey,
    ): array {
        $normalizedNote = $this->execution->normalizeNote($note);
        $normalizedKey = $this->execution->normalizeIdempotencyKey($idempotencyKey);
        $fingerprint = $this->execution->transitionFingerprint(
            'proof_upload',
            $normalizedNote,
            null,
            $proofImage,
        );
        $proofPath = null;

        try {
            [$assignment, $state, $order, $proofEvent, $replayed] = DB::transaction(function () use (
                $actor,
                $runtime,
                $orderId,
                $proofImage,
                $normalizedNote,
                $request,
                $normalizedKey,
                $fingerprint,
                &$proofPath,
            ): array {
                [$assignment, $state, $order] = $this->ownedExecution(
                    $actor,
                    $runtime,
                    $orderId,
                    lock: true,
                );

                abort_unless(
                    (string) $state->status === 'out_for_delivery'
                        && (string) $order->status === 'out_for_delivery',
                    409,
                    'Delivery proof can only be uploaded while the order is out for delivery.',
                );

                if ($normalizedKey !== null) {
                    $prior = OrderVanExecutionEvent::query()
                        ->where('order_van_assignment_id', $assignment->getKey())
                        ->where('idempotency_key', $normalizedKey)
                        ->lockForUpdate()
                        ->first();

                    if ($prior instanceof OrderVanExecutionEvent) {
                        abort_unless((string) $prior->action === 'proof_upload', 409);
                        $this->execution->assertReplayMatches(
                            'proof_upload',
                            $prior->request_fingerprint,
                            'proof_upload',
                            $fingerprint,
                        );

                        return [$assignment, $state, $order, $prior, true];
                    }
                }

                $this->execution->assertTransitionRequirements(
                    $order,
                    'proof_upload',
                    $normalizedNote,
                    null,
                    $proofImage,
                );

                $storedPath = $proofImage->store('delivery-proofs/van', 'public');
                if (! is_string($storedPath) || $storedPath === '') {
                    throw ValidationException::withMessages([
                        'proof_image' => ['The delivery proof image could not be stored.'],
                    ]);
                }
                $proofPath = $storedPath;

                $event = OrderVanExecutionEvent::query()->create([
                    'order_van_assignment_id' => $assignment->getKey(),
                    'order_van_execution_state_id' => $state->getKey(),
                    'order_id' => $order->getKey(),
                    'van_id' => $assignment->van_id,
                    'user_id' => $actor->getKey(),
                    'action' => 'proof_upload',
                    'idempotency_key' => $normalizedKey,
                    'request_fingerprint' => $fingerprint,
                    'from_status' => (string) $state->status,
                    'to_status' => (string) $state->status,
                    'proof_type' => 'delivery_image',
                    'proof_path' => $proofPath,
                    'note' => $normalizedNote,
                    'captured_at' => now(),
                ]);

                $context = $this->contextArray($state->getAttribute('context'));
                $state->forceFill([
                    'version' => ((int) $state->version) + 1,
                    'context' => [
                        ...$context,
                        'last_actor_user_id' => (int) $actor->getKey(),
                        'last_source' => 'van',
                        'last_proof_event_id' => (int) $event->getKey(),
                    ],
                ])->save();

                $this->execution->recordTransitionAudit(
                    $state,
                    $actor,
                    (string) $state->status,
                    (string) $state->status,
                    null,
                    $normalizedNote,
                    $normalizedKey,
                    $request,
                    'van.delivery.proof_uploaded',
                );

                return [$assignment, $state->fresh(), $order, $event, false];
            }, 3);
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                Storage::disk('public')->delete($proofPath);
            }

            throw $exception;
        }

        return [
            ...$this->payload($assignment, $state, $order),
            'proof' => $this->proofPayload($proofEvent),
            'replayed' => $replayed,
        ];
    }

    /**
     * @param  array<string,mixed>  $runtime
     * @return array{0:OrderVanAssignment,1:OrderVanExecutionState,2:Order}
     */
    private function ownedExecution(
        User $actor,
        array $runtime,
        int $orderId,
        bool $lock = false,
    ): array {
        $vanId = isset($runtime['van_id']) && is_numeric($runtime['van_id'])
            ? (int) $runtime['van_id']
            : 0;
        $runtimeAssignmentId = isset($runtime['assignment_id']) && is_numeric($runtime['assignment_id'])
            ? (int) $runtime['assignment_id']
            : 0;

        abort_unless($vanId > 0 && $runtimeAssignmentId > 0, 403, 'A valid Van runtime context is required.');

        $runtimeQuery = DB::table('van_assignments')
            ->join('vans', 'vans.id', '=', 'van_assignments.van_id')
            ->leftJoin('drivers', 'drivers.id', '=', 'van_assignments.driver_id')
            ->where('van_assignments.id', $runtimeAssignmentId)
            ->where('van_assignments.van_id', $vanId)
            ->where('van_assignments.status', 'active')
            ->where('vans.status', 'active')
            ->where('van_assignments.effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('van_assignments.effective_until')
                    ->orWhere('van_assignments.effective_until', '>', now());
            })
            ->where(function ($query) use ($actor): void {
                $query->where('van_assignments.representative_user_id', $actor->getKey())
                    ->orWhere('drivers.user_id', $actor->getKey());
            });

        abort_unless($runtimeQuery->exists(), 403, 'The Van runtime assignment is no longer effective.');

        $assignmentQuery = OrderVanAssignment::query()
            ->where('order_id', $orderId)
            ->where('van_id', $vanId)
            ->where('van_assignment_id', $runtimeAssignmentId)
            ->where('status', 'active');

        if ($lock) {
            $assignmentQuery->lockForUpdate();
        }

        $assignments = $assignmentQuery->get();
        abort_unless($assignments->count() === 1, 404);
        /** @var OrderVanAssignment $assignment */
        $assignment = $assignments->first();

        $orderQuery = Order::query()
            ->whereKey($assignment->order_id)
            ->where('channel', 'b2b');
        if ($lock) {
            $orderQuery->lockForUpdate();
        }
        $order = $orderQuery->firstOrFail();

        $stateQuery = OrderVanExecutionState::query()
            ->where('order_van_assignment_id', $assignment->getKey());
        if ($lock) {
            $stateQuery->lockForUpdate();
        }
        $state = $stateQuery->first();

        if (! $state instanceof OrderVanExecutionState) {
            $state = $this->states->initialize($assignment);
            if ($lock) {
                $state = OrderVanExecutionState::query()
                    ->whereKey($state->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
            }
        }

        abort_unless(
            (int) $state->order_id === (int) $order->getKey()
                && (int) $state->van_id === $vanId,
            409,
            'Van execution ownership is inconsistent.',
        );

        return [$assignment, $state, $order];
    }

    private function hasCurrentDeliveryProof(
        OrderVanAssignment $assignment,
        OrderVanExecutionState $state,
    ): bool {
        $query = OrderVanExecutionEvent::query()
            ->where('order_van_assignment_id', $assignment->getKey())
            ->where('action', 'proof_upload')
            ->where('proof_type', 'delivery_image')
            ->whereNotNull('proof_path');

        if ($state->last_transition_at !== null) {
            $query->where('captured_at', '>=', $state->last_transition_at);
        }

        return $query->exists();
    }

    /** @return array<string,mixed> */
    private function payload(
        OrderVanAssignment $assignment,
        OrderVanExecutionState $state,
        Order $order,
    ): array {
        $latestProof = OrderVanExecutionEvent::query()
            ->where('order_van_assignment_id', $assignment->getKey())
            ->whereNotNull('proof_path')
            ->latest('id')
            ->first();

        return [
            'order_id' => (int) $order->getKey(),
            'order_status' => (string) $order->status,
            'order_van_assignment_id' => (int) $assignment->getKey(),
            'van_id' => (int) $assignment->van_id,
            'status' => (string) $state->status,
            'version' => (int) $state->version,
            'failure_reason_code' => $state->failure_reason_code,
            'failure_note' => $state->failure_note,
            'allowed_actions' => $this->execution->availableStatuses((string) $state->status, $order),
            'proof_required_for_delivered' => true,
            'latest_proof' => $latestProof instanceof OrderVanExecutionEvent
                ? $this->proofPayload($latestProof)
                : null,
            'last_transition_at' => $this->atomTimestamp($state->getAttribute('last_transition_at')),
        ];
    }

    /** @return array<string,mixed> */
    private function contextArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function atomTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string,mixed> */
    private function proofPayload(OrderVanExecutionEvent $event): array
    {
        return [
            'id' => (int) $event->getKey(),
            'type' => (string) $event->proof_type,
            'available' => $event->proof_path !== null,
            'captured_at' => $this->atomTimestamp($event->getAttribute('captured_at')),
        ];
    }
}
