<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Workflow\WorkflowDefinition;
use App\Enterprise\Workflow\WorkflowEngine;
use App\Enterprise\Workflow\WorkflowStatus;
use App\Enterprise\Workflow\WorkflowStepDefinition;
use App\Models\EnterpriseFinanceApprovalDelegation;
use App\Models\EnterpriseFinanceApprovalLimit;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\EnterpriseWorkflowStep;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FinanceApprovalService
{
    public const ACTION_GL_POST = 'gl.post';

    public const ACTION_OPENING_BALANCE_POST = 'opening_balance.post';

    public const ACTION_AR_POST = 'ar.post';

    public const ACTION_AR_ALLOCATION_POST = 'ar.allocation.post';

    public const ACTION_AP_POST = 'ap.post';

    public const ACTION_AP_ALLOCATION_POST = 'ap.allocation.post';

    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const APPROVE_PERMISSION = 'finance.approve';

    private const MANAGE_CAPABILITY = 'finance.approvals.manage';

    private const APPROVE_CAPABILITY = 'finance.approvals.approve';

    /** @var list<string> */
    private const ACTIONS = [
        self::ACTION_GL_POST,
        self::ACTION_OPENING_BALANCE_POST,
        self::ACTION_AR_POST,
        self::ACTION_AR_ALLOCATION_POST,
        self::ACTION_AP_POST,
        self::ACTION_AP_ALLOCATION_POST,
    ];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly WorkflowEngine $workflow,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function defineLimit(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFinanceApprovalLimit {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );

        $data = Validator::make($attributes, [
            'policy_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'role_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(self::ACTIONS)],
            'max_amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
        ])->validate();

        $role = Role::query()->whereKey((int) $data['role_id'])->where('is_active', true)->firstOrFail();
        if (! $role->permissions()->where('code', self::APPROVE_PERMISSION)->exists()) {
            throw ValidationException::withMessages([
                'role_id' => ['The approval-limit role must carry finance.approve.'],
            ]);
        }

        $amount = $this->normalizeAmount($data['max_amount']);
        if ($this->toMills($amount) < 1) {
            throw ValidationException::withMessages([
                'max_amount' => ['Approval limit must be greater than zero.'],
            ]);
        }

        $currency = $this->normalizeCurrency($data['currency_code'] ?? null);
        $effectiveFrom = (isset($data['effective_from'])
            ? CarbonImmutable::parse((string) $data['effective_from'])
            : CarbonImmutable::now())->setMicrosecond(0);
        $effectiveUntil = isset($data['effective_until'])
            ? CarbonImmutable::parse((string) $data['effective_until'])->setMicrosecond(0)
            : null;

        if ($effectiveUntil !== null && $effectiveUntil->lessThanOrEqualTo($effectiveFrom)) {
            throw ValidationException::withMessages([
                'effective_until' => ['Approval limit expiry must be after its effective start.'],
            ]);
        }

        $policyKey = trim((string) $data['policy_key']);
        $requestHash = hash('sha256', json_encode([
            'role_id' => $role->id,
            'action' => $data['action'],
            'max_amount' => $amount,
            'currency_code' => $currency,
            'effective_from' => $effectiveFrom->utc()->toIso8601String(),
            'effective_until' => $effectiveUntil?->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $actor,
            $scope,
            $role,
            $data,
            $amount,
            $currency,
            $effectiveFrom,
            $effectiveUntil,
            $policyKey,
            $requestHash,
        ): EnterpriseFinanceApprovalLimit {
            $existing = $this->limitQuery($scope)
                ->where('policy_key', $policyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseFinanceApprovalLimit) {
                $this->assertRequestHash($existing->request_hash, $requestHash, 'policy_key');

                return $existing;
            }

            $limit = EnterpriseFinanceApprovalLimit::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'role_id' => $role->id,
                'policy_key' => $policyKey,
                'request_hash' => $requestHash,
                'action' => (string) $data['action'],
                'max_amount' => $amount,
                'currency_code' => $currency,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'is_active' => true,
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.finance.approval_limit_defined',
                $actor,
                $limit,
                null,
                $limit->toArray(),
            );

            return $limit->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function delegateLimit(
        User $delegator,
        ResolvedTenantContext $context,
        int $approvalLimitId,
        array $attributes,
    ): EnterpriseFinanceApprovalDelegation {
        $scope = $this->authorizedScope(
            $delegator,
            $context,
            self::VIEW_PERMISSION,
            self::APPROVE_CAPABILITY,
        );

        $data = Validator::make($attributes, [
            'delegation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'delegate_user_id' => ['required', 'integer', 'min:1', 'different:delegator_user_id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ])->validate();

        $limit = $this->limitQuery($scope)->whereKey($approvalLimitId)->firstOrFail();
        if (! $this->canUseDirectLimit($delegator, $limit, $scope->storeId, CarbonImmutable::now())) {
            throw ValidationException::withMessages([
                'approval_limit_id' => ['Only a current direct holder of the approval limit may delegate it.'],
            ]);
        }

        $delegate = User::query()
            ->whereKey((int) $data['delegate_user_id'])
            ->where('is_active', true)
            ->firstOrFail();

        if ((int) $delegate->id === (int) $delegator->id) {
            throw ValidationException::withMessages([
                'delegate_user_id' => ['Delegation must target a different active user.'],
            ]);
        }

        $startsAt = CarbonImmutable::parse((string) $data['starts_at'])->setMicrosecond(0);
        $endsAt = CarbonImmutable::parse((string) $data['ends_at'])->setMicrosecond(0);
        $delegationKey = trim((string) $data['delegation_key']);
        $requestHash = hash('sha256', json_encode([
            'approval_limit_id' => $limit->id,
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'starts_at' => $startsAt->utc()->toIso8601String(),
            'ends_at' => $endsAt->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $delegator,
            $delegate,
            $scope,
            $limit,
            $startsAt,
            $endsAt,
            $delegationKey,
            $requestHash,
        ): EnterpriseFinanceApprovalDelegation {
            $existing = $this->delegationQuery($scope)
                ->where('delegation_key', $delegationKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseFinanceApprovalDelegation) {
                $this->assertRequestHash($existing->request_hash, $requestHash, 'delegation_key');

                return $existing;
            }

            $delegation = EnterpriseFinanceApprovalDelegation::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'approval_limit_id' => $limit->id,
                'delegator_user_id' => $delegator->id,
                'delegate_user_id' => $delegate->id,
                'delegation_key' => $delegationKey,
                'request_hash' => $requestHash,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'created_by' => $delegator->id,
            ]);

            $this->audit->record(
                'enterprise.finance.approval_delegated',
                $delegator,
                $delegation,
                null,
                $delegation->toArray(),
            );

            return $delegation->refresh();
        }, 3);
    }

    public function requestApproval(
        User $maker,
        ResolvedTenantContext $context,
        string $action,
        string $entityType,
        string|int $entityId,
        mixed $amount,
        ?string $currencyCode = null,
    ): EnterpriseWorkflowRequest {
        $scope = $this->authorizedScope(
            $maker,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );
        $this->assertAction($action);

        $entityType = trim($entityType);
        $entityId = trim((string) $entityId);
        if ($entityType === '' || strlen($entityType) > 160 || $entityId === '' || strlen($entityId) > 160) {
            throw ValidationException::withMessages([
                'entity' => ['A bounded Finance approval entity type and identity are required.'],
            ]);
        }

        $normalizedAmount = $this->normalizeAmount($amount);
        $currency = $this->normalizeCurrency($currencyCode);
        if (! $this->approvalRequired($scope, $action, $currency, CarbonImmutable::now())) {
            throw ValidationException::withMessages([
                'approval_limit' => ['No active approval policy exists for this Finance action and currency.'],
            ]);
        }

        $expectedContext = $this->workflowContext(
            $scope,
            $action,
            $normalizedAmount,
            $currency,
        );
        $idempotencyKey = 'finance-approval:'.hash('sha256', implode('|', [
            (string) $scope->legalEntityId,
            (string) $scope->storeId,
            $action,
            $entityType,
            $entityId,
            (string) $maker->id,
        ]));

        $request = $this->workflow->request(
            new WorkflowDefinition(
                key: $this->workflowKey($action),
                version: 1,
                steps: [
                    new WorkflowStepDefinition(
                        code: 'checker',
                        permission: self::VIEW_PERMISSION,
                        allowRequesterDecision: false,
                    ),
                ],
            ),
            $entityType,
            $entityId,
            $maker,
            'store',
            $scope->storeId,
            $idempotencyKey,
            $expectedContext,
        );

        $this->assertWorkflowContext($request, $scope, $action, $normalizedAmount, $currency);

        return $request;
    }

    public function approve(
        User $checker,
        ResolvedTenantContext $context,
        int $workflowRequestId,
        ?string $comment = null,
    ): EnterpriseWorkflowRequest {
        $scope = $this->authorizedScope(
            $checker,
            $context,
            self::VIEW_PERMISSION,
            self::APPROVE_CAPABILITY,
        );

        $request = EnterpriseWorkflowRequest::query()
            ->with('steps')
            ->whereKey($workflowRequestId)
            ->firstOrFail();

        $workflowContext = is_array($request->context) ? $request->context : [];
        $action = (string) ($workflowContext['action'] ?? '');
        $this->assertAction($action);
        $amount = $this->normalizeAmount($workflowContext['amount'] ?? null);
        $currency = $this->normalizeCurrency(
            isset($workflowContext['currency_code']) ? (string) $workflowContext['currency_code'] : null,
        );

        $this->assertWorkflowContext($request, $scope, $action, $amount, $currency);

        /** @var EnterpriseWorkflowStep|null $step */
        $step = $request->steps->firstWhere('step_code', 'checker');
        if (! $step instanceof EnterpriseWorkflowStep) {
            throw ValidationException::withMessages([
                'approval' => ['The Finance approval checker step is unavailable.'],
            ]);
        }

        if (
            $request->status === WorkflowStatus::APPROVED
            && $step->status === WorkflowStatus::APPROVED->value
            && (int) $step->actor_id === (int) $checker->id
        ) {
            return $request;
        }

        $authority = $this->resolveAuthority(
            $checker,
            $scope,
            $action,
            $amount,
            $currency,
            CarbonImmutable::now(),
        );
        if ($authority === null) {
            throw ValidationException::withMessages([
                'approval_limit' => ['No current direct or delegated approval limit authorizes this amount.'],
            ]);
        }

        $approved = $this->workflow->decide(
            $request->id,
            'checker',
            $checker,
            WorkflowStatus::APPROVED->value,
            $comment,
        );

        $approvalContext = is_array($approved->context) ? $approved->context : [];
        $approvalContext['approval_limit_id'] = $authority['limit']->id;
        $approvalContext['approval_delegation_id'] = $authority['delegation']?->id;
        $approvalContext['approval_role_id'] = $authority['limit']->role_id;
        $approvalContext['approved_max_amount'] = (string) $authority['limit']->max_amount;
        $approvalContext['authority_checked_at'] = CarbonImmutable::now()->utc()->toIso8601String();
        $approvalContext['authority_effective_until'] = $this->authorityEffectiveUntil(
            $authority['limit'],
            $authority['delegation'],
        )?->utc()->toIso8601String();

        $approved->forceFill(['context' => $approvalContext])->save();

        $this->audit->record(
            'enterprise.finance.approval_authorized',
            $checker,
            $approved,
            null,
            [
                'action' => $action,
                'amount' => $amount,
                'currency_code' => $currency,
                'approval_limit_id' => $authority['limit']->id,
                'approval_delegation_id' => $authority['delegation']?->id,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
            ],
        );

        return $approved->refresh()->load('steps');
    }

    public function assertPostingAuthorized(
        User $poster,
        ResolvedTenantContext $context,
        FinancePostingApprovalContext $posting,
    ): void {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');
        $scope = $context->enterpriseScope;
        $this->assertAction($posting->action);

        $amount = $this->normalizeAmount($posting->amount);
        $currency = $this->normalizeCurrency($posting->currencyCode);
        if (! $this->approvalRequired($scope, $posting->action, $currency, CarbonImmutable::now())) {
            return;
        }

        $request = EnterpriseWorkflowRequest::query()
            ->with('steps')
            ->where('workflow_key', $this->workflowKey($posting->action))
            ->where('entity_type', $posting->entityType)
            ->where('entity_id', $posting->entityId)
            ->where('scope_type', 'store')
            ->where('scope_id', $scope->storeId)
            ->where('requester_id', $posting->makerId)
            ->where('status', WorkflowStatus::APPROVED->value)
            ->orderByDesc('id')
            ->first();

        if (! $request instanceof EnterpriseWorkflowRequest) {
            throw ValidationException::withMessages([
                'approval' => ['An approved checker workflow is required before this Finance posting.'],
            ]);
        }

        $this->assertWorkflowContext(
            $request,
            $scope,
            $posting->action,
            $amount,
            $currency,
        );

        /** @var EnterpriseWorkflowStep|null $checkerStep */
        $checkerStep = $request->steps->firstWhere('step_code', 'checker');
        if (
            ! $checkerStep instanceof EnterpriseWorkflowStep
            || $checkerStep->status !== WorkflowStatus::APPROVED->value
            || $checkerStep->actor_id === null
        ) {
            throw ValidationException::withMessages([
                'approval' => ['Finance posting approval is incomplete.'],
            ]);
        }

        if ((int) $posting->makerId === (int) $poster->id) {
            throw ValidationException::withMessages([
                'poster' => ['The Finance maker cannot post the same transaction.'],
            ]);
        }

        if ((int) $checkerStep->actor_id === (int) $poster->id) {
            throw ValidationException::withMessages([
                'poster' => ['The Finance checker cannot post the same transaction.'],
            ]);
        }
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
        string $capability,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize(
            $actor,
            $context,
            $permission,
            'legal_entity',
            $scope->legalEntityId,
        );

        abort_unless(
            $this->entitlements->allows(
                FinanceFoundationService::MODULE,
                new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
                $capability,
            ),
            403,
        );

        return $scope;
    }

    /**
     * @return array{limit:EnterpriseFinanceApprovalLimit,delegation:?EnterpriseFinanceApprovalDelegation}|null
     */
    private function resolveAuthority(
        User $checker,
        EnterpriseDataScope $scope,
        string $action,
        string $amount,
        ?string $currency,
        CarbonImmutable $at,
    ): ?array {
        $direct = $this->resolveDirectLimit($checker, $scope, $action, $amount, $currency, $at);
        if ($direct instanceof EnterpriseFinanceApprovalLimit) {
            return ['limit' => $direct, 'delegation' => null];
        }

        $delegations = $this->delegationQuery($scope)
            ->where('delegate_user_id', $checker->id)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>=', $at)
            ->with(['limit', 'delegator'])
            ->orderByDesc('ends_at')
            ->limit(50)
            ->get();

        foreach ($delegations as $delegation) {
            $limit = $delegation->limit;
            $delegator = $delegation->delegator;
            if (
                ! $limit instanceof EnterpriseFinanceApprovalLimit
                || ! $delegator instanceof User
                || ! $this->limitMatches($limit, $scope, $action, $amount, $currency, $at)
                || ! $this->canUseDirectLimit($delegator, $limit, $scope->storeId, $at)
            ) {
                continue;
            }

            return ['limit' => $limit, 'delegation' => $delegation];
        }

        return null;
    }

    private function resolveDirectLimit(
        User $actor,
        EnterpriseDataScope $scope,
        string $action,
        string $amount,
        ?string $currency,
        CarbonImmutable $at,
    ): ?EnterpriseFinanceApprovalLimit {
        if (! $actor->hasPermission(self::APPROVE_PERMISSION, $scope->storeId)) {
            return null;
        }

        $roleIds = $this->roleIdsForActor($actor, $scope->storeId);
        if ($roleIds->isEmpty()) {
            return null;
        }

        return $this->activeLimitQuery($scope, $action, $currency, $at)
            ->whereIn('role_id', $roleIds->all())
            ->where('max_amount', '>=', $amount)
            ->orderByDesc('max_amount')
            ->orderByDesc('id')
            ->first();
    }

    private function canUseDirectLimit(
        User $actor,
        EnterpriseFinanceApprovalLimit $limit,
        int $storeId,
        CarbonImmutable $at,
    ): bool {
        if (! $actor->is_active || ! $actor->hasPermission(self::APPROVE_PERMISSION, $storeId)) {
            return false;
        }

        if (! $this->limitIsEffective($limit, $at)) {
            return false;
        }

        return $this->roleIdsForActor($actor, $storeId)->contains((int) $limit->role_id);
    }

    private function approvalRequired(
        EnterpriseDataScope $scope,
        string $action,
        ?string $currency,
        CarbonImmutable $at,
    ): bool {
        return $this->activeLimitQuery($scope, $action, $currency, $at)->exists();
    }

    /** @return Builder<EnterpriseFinanceApprovalLimit> */
    private function activeLimitQuery(
        EnterpriseDataScope $scope,
        string $action,
        ?string $currency,
        CarbonImmutable $at,
    ): Builder {
        return $this->limitQuery($scope)
            ->where('action', $action)
            ->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $at);
            })
            ->where(function (Builder $query) use ($currency): void {
                if ($currency === null) {
                    $query->whereNull('currency_code');

                    return;
                }

                $query->whereNull('currency_code')->orWhere('currency_code', $currency);
            });
    }

    private function limitMatches(
        EnterpriseFinanceApprovalLimit $limit,
        EnterpriseDataScope $scope,
        string $action,
        string $amount,
        ?string $currency,
        CarbonImmutable $at,
    ): bool {
        if (
            (int) $limit->organization_id !== $scope->organizationId
            || (int) $limit->company_id !== $scope->companyId
            || (int) $limit->legal_entity_id !== $scope->legalEntityId
            || (string) $limit->action !== $action
            || ! $this->limitIsEffective($limit, $at)
            || $this->toMills($limit->max_amount) < $this->toMills($amount)
        ) {
            return false;
        }

        $limitCurrency = $this->normalizeCurrency($limit->currency_code);
        return $limitCurrency === null || $limitCurrency === $currency;
    }

    private function limitIsEffective(
        EnterpriseFinanceApprovalLimit $limit,
        CarbonImmutable $at,
    ): bool {
        if (! $limit->is_active || $limit->effective_from === null) {
            return false;
        }

        if (CarbonImmutable::parse($limit->effective_from)->isAfter($at)) {
            return false;
        }

        return $limit->effective_until === null
            || CarbonImmutable::parse($limit->effective_until)->greaterThanOrEqualTo($at);
    }

    /** @return Collection<int, int> */
    private function roleIdsForActor(User $actor, int $storeId): Collection
    {
        $global = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $actor->id)
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->pluck('roles.id');

        $store = DB::table('user_store_roles')
            ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
            ->where('user_store_roles.user_id', $actor->id)
            ->where('user_store_roles.store_id', $storeId)
            ->where('roles.is_active', true)
            ->pluck('roles.id');

        return $global
            ->merge($store)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function assertWorkflowContext(
        EnterpriseWorkflowRequest $request,
        EnterpriseDataScope $scope,
        string $action,
        string $amount,
        ?string $currency,
    ): void {
        $context = is_array($request->context) ? $request->context : [];
        $expected = $this->workflowContext($scope, $action, $amount, $currency);

        foreach ($expected as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                throw ValidationException::withMessages([
                    'approval' => ['Finance approval context does not match the current transaction scope or amount.'],
                ]);
            }
        }

        if (
            $request->workflow_key !== $this->workflowKey($action)
            || $request->scope_type !== 'store'
            || (int) $request->scope_id !== $scope->storeId
        ) {
            throw ValidationException::withMessages([
                'approval' => ['Finance approval workflow scope is invalid.'],
            ]);
        }
    }

    /** @return array<string, int|string|null> */
    private function workflowContext(
        EnterpriseDataScope $scope,
        string $action,
        string $amount,
        ?string $currency,
    ): array {
        return [
            'action' => $action,
            'amount' => $amount,
            'currency_code' => $currency,
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'store_id' => $scope->storeId,
        ];
    }

    private function workflowKey(string $action): string
    {
        return 'finance.approval.'.$action;
    }

    private function authorityEffectiveUntil(
        EnterpriseFinanceApprovalLimit $limit,
        ?EnterpriseFinanceApprovalDelegation $delegation,
    ): ?CarbonImmutable {
        $limitEnd = $limit->effective_until === null
            ? null
            : CarbonImmutable::parse($limit->effective_until);
        $delegationEnd = $delegation?->ends_at === null
            ? null
            : CarbonImmutable::parse($delegation->ends_at);

        if ($limitEnd === null) {
            return $delegationEnd;
        }
        if ($delegationEnd === null) {
            return $limitEnd;
        }

        return $limitEnd->lessThan($delegationEnd) ? $limitEnd : $delegationEnd;
    }

    private function assertAction(string $action): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages([
                'action' => ['Unsupported Finance approval action.'],
            ]);
        }
    }

    private function normalizeCurrency(mixed $currency): ?string
    {
        if ($currency === null || trim((string) $currency) === '') {
            return null;
        }

        $normalized = strtoupper(trim((string) $currency));
        if (preg_match('/^[A-Z]{3}$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'currency_code' => ['Currency must be a three-letter code.'],
            ]);
        }

        return $normalized;
    }

    private function normalizeAmount(mixed $value): string
    {
        $raw = trim((string) $value);
        if (! preg_match('/^(\d{1,15})(?:\.(\d{1,3}))?$/', $raw, $matches)) {
            throw ValidationException::withMessages([
                'amount' => ['Finance approval amounts must use at most three decimal places.'],
            ]);
        }

        return $matches[1].'.'.str_pad($matches[2] ?? '', 3, '0');
    }

    private function toMills(mixed $value): int
    {
        $normalized = $this->normalizeAmount($value);
        [$whole, $fraction] = explode('.', $normalized, 2);

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private function assertRequestHash(
        string $existingHash,
        string $requestHash,
        string $field,
    ): void {
        if (! hash_equals($existingHash, $requestHash)) {
            throw ValidationException::withMessages([
                $field => ['The idempotency key is already bound to different Finance approval data.'],
            ]);
        }
    }

    /** @return Builder<EnterpriseFinanceApprovalLimit> */
    private function limitQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceApprovalLimit::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseFinanceApprovalDelegation> */
    private function delegationQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceApprovalDelegation::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }
}
