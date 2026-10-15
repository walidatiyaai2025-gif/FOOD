<?php

namespace App\Services;

use App\Enterprise\Workflow\WorkflowDefinition;
use App\Enterprise\Workflow\WorkflowEngine;
use App\Enterprise\Workflow\WorkflowStatus;
use App\Enterprise\Workflow\WorkflowStepDefinition;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class EnterpriseSecurityAdministration
{
    public const DATA_SCOPE_UPSERT = 'data_scope.upsert';

    public const DATA_SCOPE_REMOVE = 'data_scope.remove';

    public const APPROVAL_LIMIT_UPSERT = 'approval_limit.upsert';

    public const APPROVAL_LIMIT_REMOVE = 'approval_limit.remove';

    public const DELEGATION_CREATE = 'delegation.create';

    public const DELEGATION_REVOKE = 'delegation.revoke';

    private const WORKFLOW_KEY = 'enterprise.security.change';

    private const REVIEW_STEP = 'security_review';

    /** @var array<string, string> */
    private const SCOPE_TABLES = [
        'organization' => 'enterprise_organizations',
        'company' => 'enterprise_companies',
        'legal_entity' => 'enterprise_legal_entities',
        'business_unit' => 'enterprise_business_units',
        'store' => 'stores',
    ];

    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly AuditLogger $audit,
    ) {}

    public function available(): bool
    {
        return Schema::hasTable('enterprise_user_data_scopes')
            && Schema::hasTable('enterprise_user_approval_limits')
            && Schema::hasTable('enterprise_user_delegations')
            && Schema::hasTable('enterprise_workflow_requests');
    }

    /**
     * @return array{
     *   scope_options:list<array{value:string,label:string}>,
     *   scope_labels:array<string,string>,
     *   data_scopes:Collection<int, object>,
     *   approval_limits:Collection<int, object>,
     *   delegations:Collection<int, object>,
     *   pending_changes:Collection<int, EnterpriseWorkflowRequest>,
     *   delegation_candidates:Collection<int, User>
     * }
     */
    public function dashboard(User $actor): array
    {
        if (! $this->available()) {
            return [
                'scope_options' => [],
                'scope_labels' => [],
                'data_scopes' => collect(),
                'approval_limits' => collect(),
                'delegations' => collect(),
                'pending_changes' => collect(),
                'delegation_candidates' => collect(),
            ];
        }

        [$scopeOptions, $scopeLabels] = $this->scopeOptions($actor);

        $dataScopes = DB::table('enterprise_user_data_scopes as scope')
            ->join('users', 'users.id', '=', 'scope.user_id')
            ->leftJoin('users as grantor', 'grantor.id', '=', 'scope.granted_by')
            ->select([
                'scope.id',
                'scope.user_id',
                'users.name as user_name',
                'users.email as user_email',
                'scope.scope_type',
                'scope.scope_id',
                'scope.starts_at',
                'scope.ends_at',
                'grantor.name as granted_by_name',
            ])
            ->orderBy('users.name')
            ->orderBy('scope.scope_type')
            ->limit(100)
            ->get();

        $dataScopes = $dataScopes
            ->filter(fn ($row): bool => isset($scopeLabels[(string) $row->scope_type.':'.(int) $row->scope_id]))
            ->values();
        $dataScopes->each(function ($row): void {
            $row->scope_label = $this->scopeLabel((string) $row->scope_type, (int) $row->scope_id);
        });

        $approvalLimits = DB::table('enterprise_user_approval_limits as limits')
            ->join('users', 'users.id', '=', 'limits.user_id')
            ->leftJoin('users as grantor', 'grantor.id', '=', 'limits.granted_by')
            ->select([
                'limits.id',
                'limits.user_id',
                'users.name as user_name',
                'users.email as user_email',
                'limits.workflow_key',
                'limits.currency',
                'limits.amount',
                'limits.scope_type',
                'limits.scope_id',
                'limits.starts_at',
                'limits.ends_at',
                'grantor.name as granted_by_name',
            ])
            ->orderBy('users.name')
            ->orderBy('limits.workflow_key')
            ->limit(100)
            ->get();

        $approvalLimits = $approvalLimits
            ->filter(function ($row) use ($scopeLabels): bool {
                $key = $row->scope_type === 'global' || $row->scope_id === null
                    ? 'global'
                    : (string) $row->scope_type.':'.(int) $row->scope_id;

                return isset($scopeLabels[$key]);
            })
            ->values();
        $approvalLimits->each(function ($row): void {
            $row->scope_label = $this->scopeLabel(
                (string) $row->scope_type,
                $row->scope_id === null ? null : (int) $row->scope_id,
            );
        });

        $delegations = DB::table('enterprise_user_delegations as delegation')
            ->join('users as delegator', 'delegator.id', '=', 'delegation.delegator_id')
            ->join('users as delegate', 'delegate.id', '=', 'delegation.delegate_id')
            ->select([
                'delegation.id',
                'delegation.delegator_id',
                'delegation.delegate_id',
                'delegator.name as delegator_name',
                'delegate.name as delegate_name',
                'delegate.email as delegate_email',
                'delegation.permission_code',
                'delegation.scope_type',
                'delegation.scope_id',
                'delegation.starts_at',
                'delegation.ends_at',
                'delegation.revoked_at',
            ])
            ->orderByDesc('delegation.created_at')
            ->limit(100)
            ->get();

        $delegations = $delegations
            ->filter(function ($row) use ($scopeLabels): bool {
                $key = $row->scope_type === 'global' || $row->scope_id === null
                    ? 'global'
                    : (string) $row->scope_type.':'.(int) $row->scope_id;

                return isset($scopeLabels[$key]);
            })
            ->values();
        $delegations->each(function ($row): void {
            $row->scope_label = $this->scopeLabel(
                (string) $row->scope_type,
                $row->scope_id === null ? null : (int) $row->scope_id,
            );
        });

        $pendingChanges = EnterpriseWorkflowRequest::query()
            ->with('requester:id,name,email')
            ->where('workflow_key', self::WORKFLOW_KEY)
            ->where('status', WorkflowStatus::PENDING)
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(function (EnterpriseWorkflowRequest $workflow) use ($actor): bool {
                return $this->actorCanManageScope(
                    $actor,
                    (string) $workflow->scope_type,
                    $workflow->scope_id === null ? null : (int) $workflow->scope_id,
                );
            })
            ->values();

        $delegationCandidates = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $actor->getKey())
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'email']);

        return [
            'scope_options' => $scopeOptions,
            'scope_labels' => $scopeLabels,
            'data_scopes' => $dataScopes,
            'approval_limits' => $approvalLimits,
            'delegations' => $delegations,
            'pending_changes' => $pendingChanges,
            'delegation_candidates' => $delegationCandidates,
        ];
    }

    /**
     * @return array{scope_type:string,scope_id:?int,scope_key:string}
     */
    public function resolveScopeReference(string $reference, User $actor, bool $allowGlobal = true): array
    {
        $reference = trim($reference);
        if ($allowGlobal && $reference === 'global') {
            abort_unless($actor->hasRole('SUPER_ADMIN'), 403);

            return ['scope_type' => 'global', 'scope_id' => null, 'scope_key' => 'global'];
        }

        if (! preg_match('/^([a-z_]+):(\d+)$/', $reference, $matches)) {
            throw ValidationException::withMessages([
                'scope_reference' => [__('enterprise_security.errors.scope_invalid')],
            ]);
        }

        $scopeType = $matches[1];
        $scopeId = (int) $matches[2];
        $table = self::SCOPE_TABLES[$scopeType] ?? null;

        if ($scopeId < 1 || $table === null || ! DB::table($table)->where('id', $scopeId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'scope_reference' => [__('enterprise_security.errors.scope_invalid')],
            ]);
        }

        abort_unless($this->actorCanManageScope($actor, $scopeType, $scopeId), 403);

        return [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'scope_key' => $scopeType.':'.$scopeId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function requestChange(User $actor, string $operation, array $payload, string $requestToken): EnterpriseWorkflowRequest
    {
        abort_unless($actor->hasPermission('roles.manage'), 403);
        $this->assertOperation($operation);
        $payload = $this->hydrateRemovalPayload($operation, $payload);
        $this->assertProposalAllowed($actor, $operation, $payload);

        [$scopeType, $scopeId] = $this->workflowScope($operation, $payload);

        $definition = new WorkflowDefinition(
            key: self::WORKFLOW_KEY,
            version: 1,
            steps: [new WorkflowStepDefinition(self::REVIEW_STEP, 'roles.manage')],
        );

        return $this->workflow->request(
            definition: $definition,
            entityType: 'enterprise.security.policy',
            entityId: hash(
                'sha256',
                $requestToken.'|'.json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            ),
            requester: $actor,
            scopeType: $scopeType,
            scopeId: $scopeId,
            idempotencyKey: self::WORKFLOW_KEY.':'.$requestToken,
            context: [
                'operation' => $operation,
                'payload' => $payload,
            ],
        );
    }

    public function decideChange(
        EnterpriseWorkflowRequest $workflowRequest,
        User $actor,
        string $decision,
        ?string $comment = null,
    ): EnterpriseWorkflowRequest {
        abort_unless($workflowRequest->workflow_key === self::WORKFLOW_KEY, 404);

        return DB::transaction(function () use ($workflowRequest, $actor, $decision, $comment): EnterpriseWorkflowRequest {
            $decided = $this->workflow->decide(
                (int) $workflowRequest->getKey(),
                self::REVIEW_STEP,
                $actor,
                $decision,
                $comment,
            );

            if ($decided->status === WorkflowStatus::APPROVED) {
                $this->applyApprovedChange((int) $decided->getKey(), $actor);
            }

            return $decided->refresh()->load('steps');
        });
    }

    /** @return array<string, mixed> */
    public function summarizeRequest(EnterpriseWorkflowRequest $request): array
    {
        $context = is_array($request->context) ? $request->context : [];
        $payload = is_array($context['payload'] ?? null) ? $context['payload'] : [];
        $operation = (string) ($context['operation'] ?? '');

        $targetUserId = (int) ($payload['target_user_id'] ?? $payload['delegate_id'] ?? 0);
        $target = $targetUserId > 0
            ? User::query()->find($targetUserId, ['id', 'name', 'email'])
            : null;

        return [
            'operation' => $operation,
            'operation_label' => __('enterprise_security.operations.'.$operation),
            'target' => $target?->name ?? __('enterprise_security.not_applicable'),
            'target_email' => $target?->email,
            'scope_label' => $this->scopeLabel(
                (string) ($payload['scope_type'] ?? $request->scope_type),
                isset($payload['scope_id']) ? (int) $payload['scope_id'] : $request->scope_id,
            ),
            'workflow_key' => (string) ($payload['workflow_key'] ?? ''),
            'currency' => (string) ($payload['currency'] ?? ''),
            'amount' => (string) ($payload['amount'] ?? ''),
            'permission_code' => (string) ($payload['permission_code'] ?? ''),
        ];
    }

    /**
     * Freeze the business identity of a removal target so a checker never
     * approves one policy and applies the decision to a materially changed row.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function hydrateRemovalPayload(string $operation, array $payload): array
    {
        if (! in_array($operation, [self::DATA_SCOPE_REMOVE, self::APPROVAL_LIMIT_REMOVE, self::DELEGATION_REVOKE], true)) {
            return $payload;
        }

        $id = (int) ($payload['policy_id'] ?? 0);
        $table = match ($operation) {
            self::DATA_SCOPE_REMOVE => 'enterprise_user_data_scopes',
            self::APPROVAL_LIMIT_REMOVE => 'enterprise_user_approval_limits',
            self::DELEGATION_REVOKE => 'enterprise_user_delegations',
            default => '',
        };
        $row = $id > 0 ? DB::table($table)->where('id', $id)->first() : null;

        if ($row === null) {
            throw ValidationException::withMessages([
                'policy_id' => [__('enterprise_security.errors.policy_missing')],
            ]);
        }

        $payload['scope_type'] = (string) $row->scope_type;
        $payload['scope_id'] = $row->scope_id === null ? null : (int) $row->scope_id;
        $payload['scope_key'] = $row->scope_type === 'global' || $row->scope_id === null
            ? 'global'
            : (string) $row->scope_type.':'.(int) $row->scope_id;
        $payload['expected_updated_at'] = (string) $row->updated_at;

        if ($operation === self::DATA_SCOPE_REMOVE) {
            $payload['target_user_id'] = (int) $row->user_id;
        } elseif ($operation === self::APPROVAL_LIMIT_REMOVE) {
            $payload['target_user_id'] = (int) $row->user_id;
            $payload['workflow_key'] = (string) $row->workflow_key;
            $payload['currency'] = (string) $row->currency;
            $payload['amount'] = (string) $row->amount;
        } else {
            $payload['delegator_id'] = (int) $row->delegator_id;
            $payload['delegate_id'] = (int) $row->delegate_id;
            $payload['permission_code'] = (string) $row->permission_code;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertProposalAllowed(User $actor, string $operation, array $payload): void
    {
        if (in_array($operation, [self::DATA_SCOPE_REMOVE, self::APPROVAL_LIMIT_REMOVE, self::DELEGATION_REVOKE], true)) {
            $this->assertExistingPolicyMayBeChanged($actor, $operation, $payload);

            return;
        }

        $targetUserId = (int) ($payload['target_user_id'] ?? 0);
        if (in_array($operation, [self::DATA_SCOPE_UPSERT, self::APPROVAL_LIMIT_UPSERT], true)
            && ($targetUserId < 1 || ! User::query()->whereKey($targetUserId)->where('is_active', true)->exists())) {
            throw ValidationException::withMessages([
                'target_user_id' => [__('enterprise_security.errors.user_invalid')],
            ]);
        }

        $scopeType = (string) ($payload['scope_type'] ?? 'global');
        $scopeId = isset($payload['scope_id']) ? (int) $payload['scope_id'] : null;
        abort_unless($this->actorCanManageScope($actor, $scopeType, $scopeId), 403);

        if (in_array($operation, [self::DATA_SCOPE_UPSERT, self::APPROVAL_LIMIT_UPSERT], true)) {
            $startsAt = $payload['starts_at'] ?? null;
            $endsAt = $payload['ends_at'] ?? null;
            if ($startsAt !== null && $endsAt !== null
                && strtotime((string) $startsAt) >= strtotime((string) $endsAt)) {
                throw ValidationException::withMessages([
                    'ends_at' => [__('enterprise_security.errors.time_window_invalid')],
                ]);
            }
        }

        if ($operation === self::DELEGATION_CREATE) {
            $delegateId = (int) ($payload['delegate_id'] ?? 0);
            $permissionCode = (string) ($payload['permission_code'] ?? '');

            if ($delegateId < 1 || $delegateId === (int) $actor->getKey()
                || ! User::query()->whereKey($delegateId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'delegate_id' => [__('enterprise_security.errors.delegate_invalid')],
                ]);
            }

            if (! Permission::query()->where('code', $permissionCode)->exists()
                || ! $this->actorHasPermissionInScope($actor, $permissionCode, $scopeType, $scopeId)) {
                throw ValidationException::withMessages([
                    'permission_code' => [__('enterprise_security.errors.permission_invalid')],
                ]);
            }

            $startsAt = $payload['starts_at'] ?? null;
            $endsAt = $payload['ends_at'] ?? null;
            if ($startsAt === null || $endsAt === null || strtotime((string) $startsAt) >= strtotime((string) $endsAt)) {
                throw ValidationException::withMessages([
                    'ends_at' => [__('enterprise_security.errors.time_window_invalid')],
                ]);
            }
        }

        if ($operation === self::APPROVAL_LIMIT_UPSERT) {
            if ($this->scaledAmount((string) ($payload['amount'] ?? '')) === null) {
                throw ValidationException::withMessages([
                    'amount' => [__('enterprise_security.errors.amount_invalid')],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertExistingPolicyMayBeChanged(User $actor, string $operation, array $payload): void
    {
        $id = (int) ($payload['policy_id'] ?? 0);
        $table = match ($operation) {
            self::DATA_SCOPE_REMOVE => 'enterprise_user_data_scopes',
            self::APPROVAL_LIMIT_REMOVE => 'enterprise_user_approval_limits',
            self::DELEGATION_REVOKE => 'enterprise_user_delegations',
            default => null,
        };

        $row = $table === null || $id < 1 ? null : DB::table($table)->where('id', $id)->first();
        if ($row === null) {
            throw ValidationException::withMessages([
                'policy_id' => [__('enterprise_security.errors.policy_missing')],
            ]);
        }

        if (isset($payload['expected_updated_at'])
            && (string) $row->updated_at !== (string) $payload['expected_updated_at']) {
            abort(409, __('enterprise_security.errors.policy_changed'));
        }

        if ($operation === self::DELEGATION_REVOKE
            && ! $actor->hasRole('SUPER_ADMIN')
            && (int) $row->delegator_id !== (int) $actor->getKey()) {
            abort(403);
        }

        abort_unless(
            $this->actorCanManageScope($actor, (string) $row->scope_type, $row->scope_id === null ? null : (int) $row->scope_id),
            403,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{string, ?int}
     */
    private function workflowScope(string $operation, array $payload): array
    {
        if (in_array($operation, [self::DATA_SCOPE_REMOVE, self::APPROVAL_LIMIT_REMOVE, self::DELEGATION_REVOKE], true)) {
            if (isset($payload['scope_type'])) {
                return [
                    (string) $payload['scope_type'],
                    isset($payload['scope_id']) ? (int) $payload['scope_id'] : null,
                ];
            }

            $id = (int) ($payload['policy_id'] ?? 0);
            $table = match ($operation) {
                self::DATA_SCOPE_REMOVE => 'enterprise_user_data_scopes',
                self::APPROVAL_LIMIT_REMOVE => 'enterprise_user_approval_limits',
                self::DELEGATION_REVOKE => 'enterprise_user_delegations',
                default => '',
            };
            $row = DB::table($table)->where('id', $id)->first();
            abort_if($row === null, 404);

            return [(string) $row->scope_type, $row->scope_id === null ? null : (int) $row->scope_id];
        }

        return [
            (string) ($payload['scope_type'] ?? 'global'),
            isset($payload['scope_id']) ? (int) $payload['scope_id'] : null,
        ];
    }

    private function applyApprovedChange(int $requestId, User $reviewer): void
    {
        $request = EnterpriseWorkflowRequest::query()->lockForUpdate()->findOrFail($requestId);
        $context = is_array($request->context) ? $request->context : [];

        if (isset($context['applied_at'])) {
            return;
        }

        $operation = (string) ($context['operation'] ?? '');
        $payload = is_array($context['payload'] ?? null) ? $context['payload'] : [];
        $this->assertOperation($operation);
        [$scopeType, $scopeId] = $this->workflowScope($operation, $payload);

        if ($scopeType === 'global') {
            abort_unless($reviewer->hasRole('SUPER_ADMIN'), 403);
        } else {
            abort_unless($this->actorCanManageScope($reviewer, $scopeType, $scopeId), 403);
        }

        $before = null;
        $after = null;

        match ($operation) {
            self::DATA_SCOPE_UPSERT => [$before, $after] = $this->applyDataScope($payload, $reviewer),
            self::DATA_SCOPE_REMOVE => [$before, $after] = $this->removeDataScope($payload),
            self::APPROVAL_LIMIT_UPSERT => [$before, $after] = $this->applyApprovalLimit($payload, $reviewer),
            self::APPROVAL_LIMIT_REMOVE => [$before, $after] = $this->removeApprovalLimit($payload),
            self::DELEGATION_CREATE => [$before, $after] = $this->applyDelegation($payload, $request->requester),
            self::DELEGATION_REVOKE => [$before, $after] = $this->revokeDelegation($payload),
        };

        $event = match ($operation) {
            self::DATA_SCOPE_UPSERT, self::DATA_SCOPE_REMOVE => 'enterprise.security.data_scope.changed',
            self::APPROVAL_LIMIT_UPSERT, self::APPROVAL_LIMIT_REMOVE => 'enterprise.security.approval_limit.changed',
            self::DELEGATION_CREATE => 'enterprise.security.delegation.created',
            self::DELEGATION_REVOKE => 'enterprise.security.delegation.revoked',
        };

        $this->audit->record($event, $reviewer, $request, $before, $after);

        $context['applied_at'] = now()->toIso8601String();
        $context['applied_by'] = (int) $reviewer->getKey();
        $request->forceFill(['context' => $context])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{?array<string,mixed>, array<string,mixed>}
     */
    private function applyDataScope(array $payload, User $reviewer): array
    {
        $key = [
            'user_id' => (int) $payload['target_user_id'],
            'scope_type' => (string) $payload['scope_type'],
            'scope_id' => (int) $payload['scope_id'],
        ];
        $before = DB::table('enterprise_user_data_scopes')->where($key)->first();
        $values = [
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
            'granted_by' => $reviewer->getKey(),
            'updated_at' => now(),
        ];

        DB::table('enterprise_user_data_scopes')->updateOrInsert($key, $values + ['created_at' => now()]);
        $after = DB::table('enterprise_user_data_scopes')->where($key)->first();

        return [$before === null ? null : (array) $before, (array) $after];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{array<string,mixed>, null}
     */
    private function removeDataScope(array $payload): array
    {
        $row = DB::table('enterprise_user_data_scopes')->where('id', (int) $payload['policy_id'])->first();
        abort_if($row === null, 404);
        if (isset($payload['expected_updated_at'])
            && (string) $row->updated_at !== (string) $payload['expected_updated_at']) {
            abort(409, __('enterprise_security.errors.policy_changed'));
        }
        DB::table('enterprise_user_data_scopes')->where('id', $row->id)->delete();

        return [(array) $row, null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{?array<string,mixed>, array<string,mixed>}
     */
    private function applyApprovalLimit(array $payload, User $reviewer): array
    {
        $key = [
            'user_id' => (int) $payload['target_user_id'],
            'workflow_key' => (string) $payload['workflow_key'],
            'currency' => strtoupper((string) $payload['currency']),
            'scope_key' => (string) $payload['scope_key'],
        ];
        $before = DB::table('enterprise_user_approval_limits')->where($key)->first();
        $values = [
            'amount' => (string) $payload['amount'],
            'scope_type' => (string) $payload['scope_type'],
            'scope_id' => $payload['scope_id'] ?? null,
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
            'granted_by' => $reviewer->getKey(),
            'updated_at' => now(),
        ];

        DB::table('enterprise_user_approval_limits')->updateOrInsert($key, $values + ['created_at' => now()]);
        $after = DB::table('enterprise_user_approval_limits')->where($key)->first();

        return [$before === null ? null : (array) $before, (array) $after];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{array<string,mixed>, null}
     */
    private function removeApprovalLimit(array $payload): array
    {
        $row = DB::table('enterprise_user_approval_limits')->where('id', (int) $payload['policy_id'])->first();
        abort_if($row === null, 404);
        if (isset($payload['expected_updated_at'])
            && (string) $row->updated_at !== (string) $payload['expected_updated_at']) {
            abort(409, __('enterprise_security.errors.policy_changed'));
        }
        DB::table('enterprise_user_approval_limits')->where('id', $row->id)->delete();

        return [(array) $row, null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{null, array<string,mixed>}
     */
    private function applyDelegation(array $payload, User $delegator): array
    {
        abort_unless(
            (bool) $delegator->is_active
                && $this->actorHasPermissionInScope(
                    $delegator,
                    (string) $payload['permission_code'],
                    (string) $payload['scope_type'],
                    $payload['scope_id'] === null ? null : (int) $payload['scope_id'],
                ),
            403,
        );

        $overlap = DB::table('enterprise_user_delegations')
            ->where('delegator_id', $delegator->getKey())
            ->where('delegate_id', (int) $payload['delegate_id'])
            ->where('permission_code', (string) $payload['permission_code'])
            ->where('scope_key', (string) $payload['scope_key'])
            ->whereNull('revoked_at')
            ->where('starts_at', '<', (string) $payload['ends_at'])
            ->where('ends_at', '>', (string) $payload['starts_at'])
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'delegate_id' => [__('enterprise_security.errors.delegation_overlap')],
            ]);
        }

        $id = DB::table('enterprise_user_delegations')->insertGetId([
            'delegator_id' => $delegator->getKey(),
            'delegate_id' => (int) $payload['delegate_id'],
            'permission_code' => (string) $payload['permission_code'],
            'scope_type' => (string) $payload['scope_type'],
            'scope_id' => $payload['scope_id'] ?? null,
            'scope_key' => (string) $payload['scope_key'],
            'starts_at' => (string) $payload['starts_at'],
            'ends_at' => (string) $payload['ends_at'],
            'created_by' => $delegator->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = DB::table('enterprise_user_delegations')->where('id', $id)->first();

        return [null, (array) $after];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{array<string,mixed>, array<string,mixed>}
     */
    private function revokeDelegation(array $payload): array
    {
        $row = DB::table('enterprise_user_delegations')->where('id', (int) $payload['policy_id'])->first();
        abort_if($row === null, 404);
        if (isset($payload['expected_updated_at'])
            && (string) $row->updated_at !== (string) $payload['expected_updated_at']) {
            abort(409, __('enterprise_security.errors.policy_changed'));
        }
        DB::table('enterprise_user_delegations')
            ->where('id', $row->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        $after = DB::table('enterprise_user_delegations')->where('id', $row->id)->first();

        return [(array) $row, (array) $after];
    }

    private function actorCanManageScope(User $actor, string $scopeType, ?int $scopeId): bool
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return true;
        }

        if ($scopeType === 'global' || $scopeId === null || $scopeId < 1) {
            return false;
        }

        if ($scopeType === 'store') {
            return $actor->hasPermission('roles.manage', $scopeId);
        }

        if (! array_key_exists($scopeType, self::SCOPE_TABLES) || ! $actor->hasPermission('roles.manage')) {
            return false;
        }

        return $this->activeDataScopeQuery((int) $actor->getKey(), $scopeType, $scopeId)->exists();
    }

    private function actorHasPermissionInScope(User $actor, string $permission, string $scopeType, ?int $scopeId): bool
    {
        if ($scopeType === 'store' && $scopeId !== null) {
            return $actor->hasPermission($permission, $scopeId);
        }

        return $actor->hasPermission($permission) && $this->actorCanManageScope($actor, $scopeType, $scopeId);
    }

    private function activeDataScopeQuery(int $userId, string $scopeType, int $scopeId)
    {
        return DB::table('enterprise_user_data_scopes')
            ->where('user_id', $userId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    /**
     * @return array{list<array{value:string,label:string}>, array<string,string>}
     */
    private function scopeOptions(User $actor): array
    {
        $options = [];
        $labels = [];

        if ($actor->hasRole('SUPER_ADMIN')) {
            $options[] = ['value' => 'global', 'label' => __('enterprise_security.scope.global')];
            $labels['global'] = __('enterprise_security.scope.global');
        }

        foreach (self::SCOPE_TABLES as $type => $table) {
            $rows = DB::table($table)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'code']);

            foreach ($rows as $row) {
                $id = (int) $row->id;
                if (! $this->actorCanManageScope($actor, $type, $id)) {
                    continue;
                }

                $key = $type.':'.$id;
                $label = __('enterprise_security.scope.'.$type).' · '.$row->name.' ('.$row->code.')';
                $options[] = ['value' => $key, 'label' => $label];
                $labels[$key] = $label;
            }
        }

        return [$options, $labels];
    }

    private function scopeLabel(string $scopeType, ?int $scopeId): string
    {
        if ($scopeType === 'global') {
            return __('enterprise_security.scope.global');
        }

        $table = self::SCOPE_TABLES[$scopeType] ?? null;
        if ($table === null || $scopeId === null) {
            return __('enterprise_security.not_available');
        }

        $row = DB::table($table)->where('id', $scopeId)->first(['name', 'code']);
        if ($row === null) {
            return __('enterprise_security.not_available');
        }

        return __('enterprise_security.scope.'.$scopeType).' · '.$row->name.' ('.$row->code.')';
    }

    private function assertOperation(string $operation): void
    {
        if (! in_array($operation, [
            self::DATA_SCOPE_UPSERT,
            self::DATA_SCOPE_REMOVE,
            self::APPROVAL_LIMIT_UPSERT,
            self::APPROVAL_LIMIT_REMOVE,
            self::DELEGATION_CREATE,
            self::DELEGATION_REVOKE,
        ], true)) {
            throw ValidationException::withMessages([
                'operation' => [__('enterprise_security.errors.operation_invalid')],
            ]);
        }
    }

    private function scaledAmount(string $amount): ?int
    {
        $amount = trim($amount);
        if (! preg_match('/^(\d{1,14})(?:\.(\d{1,4}))?$/', $amount, $matches)) {
            return null;
        }

        $fraction = str_pad($matches[2] ?? '', 4, '0');

        return (int) ($matches[1].$fraction);
    }
}
