<?php

namespace App\Services;

use App\Enterprise\Workflow\WorkflowDefinition;
use App\Enterprise\Workflow\WorkflowEngine;
use App\Enterprise\Workflow\WorkflowStatus;
use App\Enterprise\Workflow\WorkflowStepDefinition;
use App\Models\EnterpriseApprovalLimit;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseDelegation;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\EnterpriseRoleDataScope;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class EnterpriseSecurityManager
{
    private const SCOPE_TYPES = [
        'platform',
        'organization',
        'company',
        'legal_entity',
        'business_unit',
        'store',
    ];

    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly EnterpriseSecurityAccessResolver $access,
        private readonly AuditLogger $audit,
    ) {}

    public function requestRoleScopeGrant(
        User $actor,
        Role $role,
        string $scopeType,
        ?int $scopeId,
    ): EnterpriseWorkflowRequest {
        $this->assertScope($scopeType, $scopeId);
        $this->assertRoleGovernance($actor, $role, $scopeType, $scopeId, 'security.scopes.manage');

        return $this->requestChange($actor, 'role_scope.grant', [
            'role_id' => (int) $role->getKey(),
            'role_label' => (string) $role->name,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'scope_key' => $this->scopeKey($scopeType, $scopeId),
        ]);
    }

    public function requestRoleScopeRevoke(
        User $actor,
        EnterpriseRoleDataScope $scope,
    ): EnterpriseWorkflowRequest {
        $scope->loadMissing('role.permissions:id,code');
        $this->assertRoleGovernance(
            $actor,
            $scope->role,
            (string) $scope->scope_type,
            $scope->scope_id === null ? null : (int) $scope->scope_id,
            'security.scopes.manage',
        );

        return $this->requestChange($actor, 'role_scope.revoke', [
            'scope_grant_id' => (int) $scope->getKey(),
            'role_label' => (string) $scope->role->name,
            'scope_type' => (string) $scope->scope_type,
            'scope_id' => $scope->scope_id === null ? null : (int) $scope->scope_id,
        ]);
    }

    public function requestApprovalLimit(
        User $actor,
        Role $role,
        string $permission,
        string $scopeType,
        ?int $scopeId,
        string $currency,
        string $maxAmount,
    ): EnterpriseWorkflowRequest {
        $this->assertPermission($permission);
        $this->assertScope($scopeType, $scopeId);
        $this->assertRoleGovernance($actor, $role, $scopeType, $scopeId, 'security.approval_limits.manage');
        $role->loadMissing('permissions:id,code');
        if (! $role->permissions->contains('code', $permission)) {
            throw ValidationException::withMessages([
                'permission_code' => [__('enterprise_security.errors.role_permission')],
            ]);
        }

        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['currency' => [__('enterprise_security.errors.currency')]]);
        }

        if (! preg_match('/^\d{1,15}(?:\.\d{1,3})?$/', $maxAmount) || (float) $maxAmount <= 0) {
            throw ValidationException::withMessages(['max_amount' => [__('enterprise_security.errors.amount')]]);
        }

        return $this->requestChange($actor, 'approval_limit.upsert', [
            'role_id' => (int) $role->getKey(),
            'role_label' => (string) $role->name,
            'permission_code' => $permission,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'scope_key' => $this->scopeKey($scopeType, $scopeId),
            'currency' => $currency,
            'max_amount' => $maxAmount,
        ]);
    }

    public function requestApprovalLimitDeactivate(
        User $actor,
        EnterpriseApprovalLimit $limit,
    ): EnterpriseWorkflowRequest {
        $limit->loadMissing('role.permissions:id,code');
        $this->assertRoleGovernance(
            $actor,
            $limit->role,
            (string) $limit->scope_type,
            $limit->scope_id === null ? null : (int) $limit->scope_id,
            'security.approval_limits.manage',
        );

        return $this->requestChange($actor, 'approval_limit.deactivate', [
            'approval_limit_id' => (int) $limit->getKey(),
            'role_label' => (string) $limit->role->name,
            'permission_code' => (string) $limit->permission_code,
            'scope_type' => (string) $limit->scope_type,
            'scope_id' => $limit->scope_id === null ? null : (int) $limit->scope_id,
        ]);
    }

    public function requestDelegation(
        User $actor,
        User $delegator,
        User $delegate,
        string $permission,
        string $scopeType,
        ?int $scopeId,
        string $startsAt,
        string $endsAt,
    ): EnterpriseWorkflowRequest {
        $this->assertPermission($permission);
        $this->assertScope($scopeType, $scopeId);

        if (! $actor->is($delegator) && ! $actor->hasRole('SUPER_ADMIN')) {
            throw ValidationException::withMessages([
                'delegator_id' => [__('enterprise_security.errors.delegator_actor')],
            ]);
        }
        if (! $this->access->allows($actor, 'security.delegations.manage', $scopeType, $scopeId)) {
            throw ValidationException::withMessages([
                'scope_key' => [__('enterprise_security.errors.scope_access')],
            ]);
        }

        if ($delegator->is($delegate)) {
            throw ValidationException::withMessages(['delegate_id' => [__('enterprise_security.errors.self_delegate')]]);
        }

        $start = Carbon::parse($startsAt);
        $end = Carbon::parse($endsAt);
        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => [__('enterprise_security.errors.end_after_start')]]);
        }

        if (! $this->access->allows($delegator, $permission, $scopeType, $scopeId)) {
            throw ValidationException::withMessages(['permission_code' => [__('enterprise_security.errors.delegator_permission')]]);
        }

        return $this->requestChange($actor, 'delegation.create', [
            'delegator_id' => (int) $delegator->getKey(),
            'delegator_label' => (string) $delegator->name,
            'delegate_id' => (int) $delegate->getKey(),
            'delegate_label' => (string) $delegate->name,
            'permission_code' => $permission,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'scope_key' => $this->scopeKey($scopeType, $scopeId),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
        ]);
    }

    public function requestDelegationRevoke(
        User $actor,
        EnterpriseDelegation $delegation,
    ): EnterpriseWorkflowRequest {
        if (! $actor->hasRole('SUPER_ADMIN') && (int) $delegation->delegator_id !== (int) $actor->getKey()) {
            throw ValidationException::withMessages([
                'delegation' => [__('enterprise_security.errors.delegation_owner')],
            ]);
        }
        if (! $this->access->allows(
            $actor,
            'security.delegations.manage',
            (string) $delegation->scope_type,
            $delegation->scope_id === null ? null : (int) $delegation->scope_id,
        )) {
            throw ValidationException::withMessages([
                'scope_key' => [__('enterprise_security.errors.scope_access')],
            ]);
        }

        return $this->requestChange($actor, 'delegation.revoke', [
            'delegation_id' => (int) $delegation->getKey(),
            'permission_code' => (string) $delegation->permission_code,
            'delegate_label' => (string) $delegation->delegate->name,
            'scope_type' => (string) $delegation->scope_type,
            'scope_id' => $delegation->scope_id === null ? null : (int) $delegation->scope_id,
        ]);
    }

    public function decideAndApply(
        int $workflowRequestId,
        User $actor,
        string $decision,
        ?string $comment = null,
    ): EnterpriseWorkflowRequest {
        $request = $this->workflow->decide(
            $workflowRequestId,
            'checker',
            $actor,
            $decision,
            $comment,
        );

        if ($request->status !== WorkflowStatus::APPROVED) {
            return $request;
        }

        return DB::transaction(function () use ($workflowRequestId, $actor): EnterpriseWorkflowRequest {
            $locked = EnterpriseWorkflowRequest::query()
                ->lockForUpdate()
                ->findOrFail($workflowRequestId);

            /** @var array<string, mixed>|null $contextValue */
            $contextValue = $locked->getAttribute('context');
            $context = is_array($contextValue) ? $contextValue : [];
            if (($context['applied_at'] ?? null) !== null) {
                return $locked->load('steps');
            }

            $changeType = (string) ($context['change_type'] ?? '');
            $payload = is_array($context['payload'] ?? null) ? $context['payload'] : [];
            $target = $this->applyChange($changeType, $payload);

            $context['applied_at'] = now()->toIso8601String();
            $context['applied_by'] = (int) $actor->getKey();
            $locked->forceFill(['context' => $context])->save();

            $this->audit->record(
                'enterprise.security.change_applied',
                $actor,
                $target,
                null,
                ['change_type' => $changeType, 'payload' => $payload],
            );

            return $locked->refresh()->load('steps');
        });
    }

    private function requestChange(User $actor, string $changeType, array $payload): EnterpriseWorkflowRequest
    {
        $uuid = (string) Str::uuid();

        return $this->workflow->request(
            new WorkflowDefinition('enterprise_security_privileged_change', 1, [
                new WorkflowStepDefinition('checker', 'security.approve'),
            ]),
            'enterprise_security_change',
            $uuid,
            $actor,
            (string) ($payload['scope_type'] ?? 'platform'),
            isset($payload['scope_id']) ? (int) $payload['scope_id'] : null,
            'enterprise-security-'.$uuid,
            [
                'change_type' => $changeType,
                'payload' => $payload,
                'applied_at' => null,
            ],
        );
    }

    private function applyChange(string $changeType, array $payload): object
    {
        return match ($changeType) {
            'role_scope.grant' => EnterpriseRoleDataScope::query()->updateOrCreate(
                [
                    'role_id' => (int) $payload['role_id'],
                    'scope_key' => (string) $payload['scope_key'],
                ],
                [
                    'scope_type' => (string) $payload['scope_type'],
                    'scope_id' => $payload['scope_id'] === null ? null : (int) $payload['scope_id'],
                    'is_active' => true,
                ],
            ),
            'role_scope.revoke' => $this->deactivateScope((int) $payload['scope_grant_id']),
            'approval_limit.upsert' => EnterpriseApprovalLimit::query()->updateOrCreate(
                [
                    'role_id' => (int) $payload['role_id'],
                    'permission_code' => (string) $payload['permission_code'],
                    'scope_key' => (string) $payload['scope_key'],
                    'currency' => (string) $payload['currency'],
                ],
                [
                    'scope_type' => (string) $payload['scope_type'],
                    'scope_id' => $payload['scope_id'] === null ? null : (int) $payload['scope_id'],
                    'max_amount' => (string) $payload['max_amount'],
                    'is_active' => true,
                ],
            ),
            'approval_limit.deactivate' => $this->deactivateLimit((int) $payload['approval_limit_id']),
            'delegation.create' => EnterpriseDelegation::query()->create([
                'delegator_id' => (int) $payload['delegator_id'],
                'delegate_id' => (int) $payload['delegate_id'],
                'permission_code' => (string) $payload['permission_code'],
                'scope_type' => (string) $payload['scope_type'],
                'scope_id' => $payload['scope_id'] === null ? null : (int) $payload['scope_id'],
                'scope_key' => (string) $payload['scope_key'],
                'starts_at' => (string) $payload['starts_at'],
                'ends_at' => (string) $payload['ends_at'],
            ]),
            'delegation.revoke' => $this->revokeDelegation((int) $payload['delegation_id']),
            default => throw new LogicException('Unknown enterprise security change type.'),
        };
    }

    private function deactivateScope(int $id): EnterpriseRoleDataScope
    {
        $scope = EnterpriseRoleDataScope::query()->lockForUpdate()->findOrFail($id);
        $scope->forceFill(['is_active' => false])->save();

        return $scope;
    }

    private function deactivateLimit(int $id): EnterpriseApprovalLimit
    {
        $limit = EnterpriseApprovalLimit::query()->lockForUpdate()->findOrFail($id);
        $limit->forceFill(['is_active' => false])->save();

        return $limit;
    }

    private function revokeDelegation(int $id): EnterpriseDelegation
    {
        $delegation = EnterpriseDelegation::query()->lockForUpdate()->findOrFail($id);
        if ($delegation->revoked_at === null) {
            $delegation->forceFill(['revoked_at' => now()])->save();
        }

        return $delegation;
    }

    private function assertRoleGovernance(
        User $actor,
        Role $role,
        string $scopeType,
        ?int $scopeId,
        string $ability,
    ): void {
        if (! $this->access->allows($actor, $ability, $scopeType, $scopeId)) {
            throw ValidationException::withMessages([
                'scope_key' => [__('enterprise_security.errors.scope_access')],
            ]);
        }

        if ($actor->hasRole('SUPER_ADMIN')) {
            return;
        }

        $role->loadMissing('permissions:id,code');
        $storeId = $scopeType === 'store' ? $scopeId : null;
        $missing = $role->permissions->pluck('code')->diff($actor->effectivePermissionCodes($storeId));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'role' => [__('enterprise_security.errors.role_governance')],
            ]);
        }
    }

    private function assertPermission(string $permission): void
    {
        if (! array_key_exists($permission, (array) config('permissions.abilities', []))) {
            throw ValidationException::withMessages([
                'permission_code' => [__('enterprise_security.errors.permission')],
            ]);
        }
    }

    private function assertScope(string $scopeType, ?int $scopeId): void
    {
        if (! in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw ValidationException::withMessages(['scope_type' => [__('enterprise_security.errors.scope')]]);
        }

        if ($scopeType === 'platform') {
            if ($scopeId !== null) {
                throw ValidationException::withMessages(['scope_id' => [__('enterprise_security.errors.scope')]]);
            }

            return;
        }

        if ($scopeId === null || $scopeId < 1 || ! $this->scopeExists($scopeType, $scopeId)) {
            throw ValidationException::withMessages(['scope_id' => [__('enterprise_security.errors.scope')]]);
        }
    }

    private function scopeExists(string $scopeType, int $scopeId): bool
    {
        return match ($scopeType) {
            'organization' => EnterpriseOrganization::query()->whereKey($scopeId)->where('is_active', true)->exists(),
            'company' => EnterpriseCompany::query()->whereKey($scopeId)->where('is_active', true)->exists(),
            'legal_entity' => EnterpriseLegalEntity::query()->whereKey($scopeId)->where('is_active', true)->exists(),
            'business_unit' => EnterpriseBusinessUnit::query()->whereKey($scopeId)->where('is_active', true)->exists(),
            'store' => Store::query()->whereKey($scopeId)->where('is_active', true)->exists(),
            default => false,
        };
    }

    private function scopeKey(string $scopeType, ?int $scopeId): string
    {
        return $scopeType.':'.($scopeId === null ? '*' : (string) $scopeId);
    }
}
