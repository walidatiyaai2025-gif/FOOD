<?php

namespace App\Enterprise\Workflow;

use App\Models\EnterpriseWorkflowRequest;
use App\Models\EnterpriseWorkflowStep;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class WorkflowEngine
{
    public function __construct(
        private readonly WorkflowAuthorizer $authorizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function request(
        WorkflowDefinition $definition,
        string $entityType,
        string $entityId,
        User $requester,
        string $scopeType,
        ?int $scopeId,
        string $idempotencyKey,
        array $context = [],
    ): EnterpriseWorkflowRequest {
        if ($entityType === '' || $entityId === '' || $scopeType === '' || $idempotencyKey === '') {
            throw ValidationException::withMessages([
                'workflow' => ['Entity, scope and idempotency key are required.'],
            ]);
        }

        return DB::transaction(function () use (
            $definition,
            $entityType,
            $entityId,
            $requester,
            $scopeType,
            $scopeId,
            $idempotencyKey,
            $context,
        ): EnterpriseWorkflowRequest {
            $existing = EnterpriseWorkflowRequest::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseWorkflowRequest) {
                $sameRequest = $existing->workflow_key === $definition->key
                    && (int) $existing->definition_version === $definition->version
                    && $existing->entity_type === $entityType
                    && $existing->entity_id === $entityId
                    && (int) $existing->requester_id === (int) $requester->getKey();

                if ($sameRequest === false) {
                    throw new LogicException('Workflow idempotency key was already used for a different request.');
                }

                return $existing->load('steps');
            }

            $request = EnterpriseWorkflowRequest::query()->create([
                'workflow_key' => $definition->key,
                'definition_version' => $definition->version,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'requester_id' => $requester->getKey(),
                'status' => WorkflowStatus::PENDING,
                'idempotency_key' => $idempotencyKey,
                'context' => $context,
            ]);

            foreach ($definition->steps as $index => $step) {
                EnterpriseWorkflowStep::query()->create([
                    'workflow_request_id' => $request->getKey(),
                    'sequence' => $index + 1,
                    'step_code' => $step->code,
                    'required_permission' => $step->permission,
                    'allow_requester_decision' => $step->allowRequesterDecision,
                    'status' => 'PENDING',
                ]);
            }

            $this->audit->record(
                'enterprise.workflow.requested',
                $requester,
                $request,
                null,
                [
                    'workflow_key' => $definition->key,
                    'definition_version' => $definition->version,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                ],
            );

            return $request->load('steps');
        });
    }

    public function decide(
        int $requestId,
        string $stepCode,
        User $actor,
        string $decision,
        ?string $comment = null,
    ): EnterpriseWorkflowRequest {
        $decision = strtoupper(trim($decision));
        if (in_array($decision, ['APPROVED', 'REJECTED'], true) === false) {
            throw ValidationException::withMessages(['decision' => ['Decision must be APPROVED or REJECTED.']]);
        }

        return DB::transaction(function () use ($requestId, $stepCode, $actor, $decision, $comment): EnterpriseWorkflowRequest {
            $request = EnterpriseWorkflowRequest::query()->lockForUpdate()->findOrFail($requestId);

            /** @var EnterpriseWorkflowStep $step */
            $step = EnterpriseWorkflowStep::query()
                ->where('workflow_request_id', $request->getKey())
                ->where('step_code', $stepCode)
                ->lockForUpdate()
                ->firstOrFail();

            if ($step->status !== 'PENDING') {
                if ($step->status === $decision && (int) $step->actor_id === (int) $actor->getKey()) {
                    return $request->refresh()->load('steps');
                }

                throw new LogicException('Workflow step has already been decided.');
            }

            $requestStatus = $request->getAttribute('status');
            if ($requestStatus instanceof WorkflowStatus === false) {
                throw new LogicException('Workflow request has an invalid status.');
            }

            if ($requestStatus !== WorkflowStatus::PENDING) {
                throw new LogicException('Workflow request is already terminal.');
            }

            $priorPendingOrRejected = EnterpriseWorkflowStep::query()
                ->where('workflow_request_id', $request->getKey())
                ->where('sequence', '<', $step->sequence)
                ->where('status', '!=', 'APPROVED')
                ->exists();

            if ($priorPendingOrRejected) {
                throw new LogicException('Workflow steps must be decided in sequence.');
            }

            abort_unless($this->authorizer->canDecide($actor, $request, $step), 403);

            $step->fill([
                'status' => $decision,
                'actor_id' => $actor->getKey(),
                'comment' => $comment,
                'decided_at' => now(),
            ])->save();

            if ($decision === 'REJECTED') {
                $request->fill([
                    'status' => WorkflowStatus::REJECTED,
                    'completed_at' => now(),
                ])->save();
            } else {
                $hasPending = EnterpriseWorkflowStep::query()
                    ->where('workflow_request_id', $request->getKey())
                    ->where('status', 'PENDING')
                    ->exists();

                if ($hasPending === false) {
                    $request->fill([
                        'status' => WorkflowStatus::APPROVED,
                        'completed_at' => now(),
                    ])->save();
                }
            }

            $this->audit->record(
                'enterprise.workflow.decision_recorded',
                $actor,
                $request,
                null,
                [
                    'step_code' => $stepCode,
                    'decision' => $decision,
                    'scope_type' => $request->scope_type,
                    'scope_id' => $request->scope_id,
                ],
            );

            return $request->refresh()->load('steps');
        });
    }

    public function cancel(int $requestId, User $actor): EnterpriseWorkflowRequest
    {
        return DB::transaction(function () use ($requestId, $actor): EnterpriseWorkflowRequest {
            $request = EnterpriseWorkflowRequest::query()->lockForUpdate()->findOrFail($requestId);

            $requestStatus = $request->getAttribute('status');
            if ($requestStatus instanceof WorkflowStatus === false) {
                throw new LogicException('Workflow request has an invalid status.');
            }

            if ($requestStatus === WorkflowStatus::CANCELLED
                && (int) $request->requester_id === (int) $actor->getKey()) {
                return $request;
            }

            if ($requestStatus !== WorkflowStatus::PENDING
                || (int) $request->requester_id !== (int) $actor->getKey()) {
                throw new LogicException('Only the requester may cancel a pending workflow.');
            }

            $request->fill([
                'status' => WorkflowStatus::CANCELLED,
                'completed_at' => now(),
            ])->save();

            $this->audit->record(
                'enterprise.workflow.cancelled',
                $actor,
                $request,
                null,
                ['scope_type' => $request->scope_type, 'scope_id' => $request->scope_id],
            );

            return $request->refresh();
        });
    }
}
