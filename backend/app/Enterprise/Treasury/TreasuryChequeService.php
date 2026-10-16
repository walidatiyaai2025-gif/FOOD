<?php

namespace App\Enterprise\Treasury;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Workflow\WorkflowDefinition;
use App\Enterprise\Workflow\WorkflowEngine;
use App\Enterprise\Workflow\WorkflowStepDefinition;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseTreasuryCheque;
use App\Models\EnterpriseTreasuryChequeTransition;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TreasuryChequeService
{
    public const MODULE = 'treasury';

    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const APPROVE_PERMISSION = 'finance.manage';

    private const READ_CAPABILITY = 'treasury.cheques.read';

    private const MANAGE_CAPABILITY = 'treasury.cheques.manage';

    private const APPROVE_CAPABILITY = 'treasury.cheques.approve';

    private const WORKFLOW_KEY = 'treasury_cheque_transition';

    private const WORKFLOW_STEP = 'approver';

    private const DEFAULT_LIST_WINDOW_DAYS = 90;

    private const MAX_LIST_WINDOW_DAYS = 366;

    private const DIRECTIONS = [
        EnterpriseTreasuryCheque::DIRECTION_ISSUED,
        EnterpriseTreasuryCheque::DIRECTION_RECEIVED,
    ];

    private const REFERENCE_TYPES = [
        EnterpriseTreasuryCheque::REFERENCE_PAYMENT,
        EnterpriseTreasuryCheque::REFERENCE_RECEIPT,
    ];

    private const STATUSES = [
        EnterpriseTreasuryCheque::STATUS_ISSUED,
        EnterpriseTreasuryCheque::STATUS_RECEIVED,
        EnterpriseTreasuryCheque::STATUS_PRESENTED,
        EnterpriseTreasuryCheque::STATUS_DEPOSITED,
        EnterpriseTreasuryCheque::STATUS_CLEARED,
        EnterpriseTreasuryCheque::STATUS_BOUNCED,
        EnterpriseTreasuryCheque::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
    ) {}

    /**
     * @param  array{
     *     status?:string,
     *     direction?:string,
     *     bank_account_id?:int,
     *     due_date_from?:string,
     *     due_date_to?:string,
     *     overdue?:bool
     * }  $filters
     * @return LengthAwarePaginator<int, EnterpriseTreasuryCheque>
     */
    public function paginateInstruments(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $validated = Validator::make($filters, [
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'direction' => ['nullable', Rule::in(self::DIRECTIONS)],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'due_date_from' => ['nullable', 'date'],
            'due_date_to' => ['nullable', 'date', 'after_or_equal:due_date_from'],
            'overdue' => ['nullable', 'boolean'],
        ])->validate();

        $window = $this->boundedDueWindow($validated);
        $perPage = max(10, min(100, $perPage));
        $overdue = (bool) ($validated['overdue'] ?? false);

        return $this->chequeQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['direction'] ?? null,
                fn (Builder $query, string $direction): Builder => $query->where('direction', $direction),
            )
            ->when(
                $validated['bank_account_id'] ?? null,
                fn (Builder $query, int $bankAccountId): Builder => $query->where(
                    'bank_account_id',
                    $bankAccountId,
                ),
            )
            ->whereDate('due_date', '>=', $window['from'])
            ->whereDate('due_date', '<=', $window['to'])
            ->when(
                $overdue,
                fn (Builder $query): Builder => $query
                    ->whereDate('due_date', '<', CarbonImmutable::today()->toDateString())
                    ->whereNotIn('status', [
                        EnterpriseTreasuryCheque::STATUS_CLEARED,
                        EnterpriseTreasuryCheque::STATUS_BOUNCED,
                        EnterpriseTreasuryCheque::STATUS_CANCELLED,
                    ]),
            )
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function createInstrument(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseTreasuryCheque {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = $this->validatedCreate($attributes);

        $direction = (string) $data['direction'];
        $referenceType = (string) $data['treasury_reference_type'];
        $referenceId = (int) $data['treasury_reference_id'];
        $bankAccountId = (int) $data['bank_account_id'];
        $this->assertDirectionReference($direction, $referenceType);

        $bank = $this->bankAccountForScope($scope, $bankAccountId, false);
        $source = $this->treasuryReferenceForScope(
            $scope,
            $referenceType,
            $referenceId,
            $bankAccountId,
            false,
        );

        if (strtoupper((string) $source->currency_code) !== strtoupper((string) $bank->currency_code)) {
            throw ValidationException::withMessages([
                'bank_account_id' => ['Cheque bank account currency must match the linked Treasury transaction.'],
            ]);
        }

        $normalized = [
            'direction' => $direction,
            'treasury_reference_type' => $referenceType,
            'treasury_reference_id' => $referenceId,
            'bank_account_id' => $bankAccountId,
            'instrument_number' => trim((string) $data['instrument_number']),
            'issue_date' => CarbonImmutable::parse((string) $data['issue_date'])->toDateString(),
            'due_date' => CarbonImmutable::parse((string) $data['due_date'])->toDateString(),
            'payee_or_drawer' => $this->trimNullable($data['payee_or_drawer'] ?? null),
            'amount' => (string) $source->amount,
            'currency_code' => strtoupper((string) $source->currency_code),
        ];
        $idempotencyKey = trim((string) $data['idempotency_key']);
        $requestHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        $existing = $this->chequeQuery($scope)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing instanceof EnterpriseTreasuryCheque) {
            $this->assertRequestHash($existing->request_hash, $requestHash);

            return $existing;
        }

        return DB::transaction(function () use (
            $actor,
            $scope,
            $idempotencyKey,
            $requestHash,
            $normalized,
        ): EnterpriseTreasuryCheque {
            $existing = $this->chequeQuery($scope)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof EnterpriseTreasuryCheque) {
                $this->assertRequestHash($existing->request_hash, $requestHash);

                return $existing;
            }

            $this->bankAccountForScope(
                $scope,
                (int) $normalized['bank_account_id'],
                true,
            );
            $this->treasuryReferenceForScope(
                $scope,
                (string) $normalized['treasury_reference_type'],
                (int) $normalized['treasury_reference_id'],
                (int) $normalized['bank_account_id'],
                true,
            );

            $linked = EnterpriseTreasuryCheque::query()
                ->where('treasury_reference_type', $normalized['treasury_reference_type'])
                ->where('treasury_reference_id', $normalized['treasury_reference_id'])
                ->lockForUpdate()
                ->first();
            if ($linked instanceof EnterpriseTreasuryCheque) {
                throw ValidationException::withMessages([
                    'treasury_reference_id' => [
                        'The linked Treasury transaction already has a cheque/PDC instrument.',
                    ],
                ]);
            }

            $cheque = EnterpriseTreasuryCheque::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
                'bank_account_id' => $normalized['bank_account_id'],
                'instrument_number' => $normalized['instrument_number'],
                'direction' => $normalized['direction'],
                'treasury_reference_type' => $normalized['treasury_reference_type'],
                'treasury_reference_id' => $normalized['treasury_reference_id'],
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'amount' => $normalized['amount'],
                'currency_code' => $normalized['currency_code'],
                'issue_date' => $normalized['issue_date'],
                'due_date' => $normalized['due_date'],
                'payee_or_drawer' => $normalized['payee_or_drawer'],
                'status' => $normalized['direction'] === EnterpriseTreasuryCheque::DIRECTION_ISSUED
                    ? EnterpriseTreasuryCheque::STATUS_ISSUED
                    : EnterpriseTreasuryCheque::STATUS_RECEIVED,
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.treasury.cheque_created',
                $actor,
                $cheque,
                null,
                $cheque->fresh()->toArray(),
            );

            return $cheque->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function transition(
        User $actor,
        ResolvedTenantContext $context,
        int $chequeId,
        array $attributes,
    ): EnterpriseTreasuryCheque {
        $scope = $this->authorizedScope($actor, $context, self::APPROVE_PERMISSION, self::APPROVE_CAPABILITY);
        $data = Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'to_status' => ['required', Rule::in(self::STATUSES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $idempotencyKey = trim((string) $data['idempotency_key']);
        $toStatus = (string) $data['to_status'];
        $reason = $this->trimNullable($data['reason'] ?? null);
        $requestHash = hash('sha256', json_encode([
            'cheque_id' => $chequeId,
            'to_status' => $toStatus,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $actor,
            $scope,
            $chequeId,
            $idempotencyKey,
            $toStatus,
            $reason,
            $requestHash,
        ): EnterpriseTreasuryCheque {
            $retry = EnterpriseTreasuryChequeTransition::query()
                ->where('store_id', $scope->storeId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($retry instanceof EnterpriseTreasuryChequeTransition) {
                $this->assertRequestHash($retry->request_hash, $requestHash);
                if ((int) $retry->cheque_id !== $chequeId) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => [
                            'Transition idempotency key is already bound to another cheque/PDC instrument.',
                        ],
                    ]);
                }

                return $this->chequeQuery($scope)->whereKey($chequeId)->firstOrFail();
            }

            $cheque = $this->chequeQuery($scope)
                ->whereKey($chequeId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $cheque->requested_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'approver' => ['The cheque/PDC maker cannot approve its lifecycle transition.'],
                ]);
            }

            $fromStatus = (string) $cheque->status;
            $this->assertTransitionAllowed($fromStatus, $toStatus);

            if (
                in_array($toStatus, [
                    EnterpriseTreasuryCheque::STATUS_PRESENTED,
                    EnterpriseTreasuryCheque::STATUS_DEPOSITED,
                ], true)
                && CarbonImmutable::today()->lt(
                    CarbonImmutable::parse((string) $cheque->due_date)->startOfDay(),
                )
            ) {
                throw ValidationException::withMessages([
                    'due_date' => ['Post-dated cheque/PDC cannot be presented or deposited before maturity.'],
                ]);
            }

            $requester = User::query()->findOrFail((int) $cheque->requested_by);
            $approval = $this->workflow->request(
                new WorkflowDefinition(self::WORKFLOW_KEY, 1, [
                    new WorkflowStepDefinition(self::WORKFLOW_STEP, self::APPROVE_PERMISSION),
                ]),
                'treasury_cheque',
                (string) $cheque->getKey(),
                $requester,
                'store',
                $scope->storeId,
                'treasury-cheque-'.$cheque->getKey().'-'.substr(hash('sha256', $idempotencyKey), 0, 24),
                [
                    'amount' => (string) $cheque->amount,
                    'currency' => (string) $cheque->currency_code,
                    'direction' => (string) $cheque->direction,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'due_date' => CarbonImmutable::parse((string) $cheque->due_date)->toDateString(),
                ],
            );
            $this->workflow->decide(
                (int) $approval->getKey(),
                self::WORKFLOW_STEP,
                $actor,
                'APPROVED',
            );

            $before = $cheque->toArray();
            $occurredAt = now();
            $cheque->forceFill([
                'status' => $toStatus,
                'last_transition_at' => $occurredAt,
                'last_transition_by' => $actor->id,
            ])->save();

            EnterpriseTreasuryChequeTransition::query()->create([
                'cheque_id' => $cheque->id,
                'store_id' => $scope->storeId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_id' => $actor->id,
                'workflow_request_id' => $approval->getKey(),
                'reason' => $reason,
                'metadata' => [
                    'amount' => (string) $cheque->amount,
                    'currency' => (string) $cheque->currency_code,
                    'direction' => (string) $cheque->direction,
                    'due_date' => CarbonImmutable::parse((string) $cheque->due_date)->toDateString(),
                ],
                'occurred_at' => $occurredAt,
            ]);

            $this->audit->record(
                'enterprise.treasury.cheque_transitioned',
                $actor,
                $cheque,
                $before,
                $cheque->fresh()->toArray(),
            );

            return $cheque->refresh();
        }, 3);
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
        string $capability,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');
        $scope = $context->enterpriseScope;

        abort_unless(
            $this->entitlements->allows(
                self::MODULE,
                new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
                $capability,
            ),
            403,
        );

        $this->authorizer->authorize($actor, $context, $permission, 'store', $scope->storeId);

        return $scope;
    }

    /** @return Builder<EnterpriseTreasuryCheque> */
    private function chequeQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseTreasuryCheque::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId);
    }

    private function bankAccountForScope(
        EnterpriseDataScope $scope,
        int $bankAccountId,
        bool $lock,
    ): EnterpriseBankAccount {
        $query = EnterpriseBankAccount::query()
            ->whereKey($bankAccountId)
            ->where('company_id', $scope->companyId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $bank = $query->first();
        if (! $bank instanceof EnterpriseBankAccount) {
            throw (new ModelNotFoundException)->setModel(EnterpriseBankAccount::class, [$bankAccountId]);
        }
        if (! $bank->is_active) {
            throw ValidationException::withMessages([
                'bank_account_id' => ['Cheque/PDC bank account must be active.'],
            ]);
        }

        if (
            $bank->operator_visibility !== EnterpriseBankAccount::VISIBILITY_COMPANY
            && (
                $bank->operator_visibility !== EnterpriseBankAccount::VISIBILITY_STORE
                || (int) $bank->store_id !== $scope->storeId
            )
        ) {
            throw (new ModelNotFoundException)->setModel(EnterpriseBankAccount::class, [$bankAccountId]);
        }

        return $bank;
    }

    private function treasuryReferenceForScope(
        EnterpriseDataScope $scope,
        string $referenceType,
        int $referenceId,
        int $bankAccountId,
        bool $lock,
    ): EnterpriseTreasuryReceipt|EnterpriseTreasuryPayment {
        if ($referenceType === EnterpriseTreasuryCheque::REFERENCE_PAYMENT) {
            $query = EnterpriseTreasuryPayment::query()
                ->whereKey($referenceId)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where('status', EnterpriseTreasuryPayment::STATUS_POSTED);
            if ($lock) {
                $query->lockForUpdate();
            }
            $source = $query->first();
            if (! $source instanceof EnterpriseTreasuryPayment) {
                throw (new ModelNotFoundException)->setModel(EnterpriseTreasuryPayment::class, [$referenceId]);
            }
        } elseif ($referenceType === EnterpriseTreasuryCheque::REFERENCE_RECEIPT) {
            $query = EnterpriseTreasuryReceipt::query()
                ->whereKey($referenceId)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where('status', EnterpriseTreasuryReceipt::STATUS_POSTED);
            if ($lock) {
                $query->lockForUpdate();
            }
            $source = $query->first();
            if (! $source instanceof EnterpriseTreasuryReceipt) {
                throw (new ModelNotFoundException)->setModel(EnterpriseTreasuryReceipt::class, [$referenceId]);
            }
        } else {
            throw ValidationException::withMessages([
                'treasury_reference_type' => ['Unsupported Treasury cheque/PDC reference type.'],
            ]);
        }

        if (
            (string) $source->target_type !== EnterpriseTreasuryPayment::TARGET_BANK_ACCOUNT
            || (int) $source->target_id !== $bankAccountId
        ) {
            throw ValidationException::withMessages([
                'treasury_reference_id' => [
                    'Cheque/PDC must reference a posted Treasury bank-account transaction for this bank account.',
                ],
            ]);
        }

        return $source;
    }

    private function assertDirectionReference(string $direction, string $referenceType): void
    {
        $expected = $direction === EnterpriseTreasuryCheque::DIRECTION_ISSUED
            ? EnterpriseTreasuryCheque::REFERENCE_PAYMENT
            : EnterpriseTreasuryCheque::REFERENCE_RECEIPT;

        if ($referenceType !== $expected) {
            throw ValidationException::withMessages([
                'treasury_reference_type' => [
                    'Issued cheques must reference a Treasury payment and received cheques must reference a Treasury receipt.',
                ],
            ]);
        }
    }

    private function assertTransitionAllowed(string $fromStatus, string $toStatus): void
    {
        $allowed = match ($fromStatus) {
            EnterpriseTreasuryCheque::STATUS_ISSUED => [
                EnterpriseTreasuryCheque::STATUS_PRESENTED,
                EnterpriseTreasuryCheque::STATUS_CANCELLED,
            ],
            EnterpriseTreasuryCheque::STATUS_RECEIVED => [
                EnterpriseTreasuryCheque::STATUS_DEPOSITED,
                EnterpriseTreasuryCheque::STATUS_CANCELLED,
            ],
            EnterpriseTreasuryCheque::STATUS_PRESENTED,
            EnterpriseTreasuryCheque::STATUS_DEPOSITED => [
                EnterpriseTreasuryCheque::STATUS_CLEARED,
                EnterpriseTreasuryCheque::STATUS_BOUNCED,
            ],
            default => [],
        };

        if (! in_array($toStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'to_status' => [
                    'Invalid cheque/PDC lifecycle transition from '.$fromStatus.' to '.$toStatus.'.',
                ],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function validatedCreate(array $attributes): array
    {
        foreach (['idempotency_key', 'instrument_number', 'payee_or_drawer'] as $field) {
            if (array_key_exists($field, $attributes) && is_string($attributes[$field])) {
                $attributes[$field] = trim($attributes[$field]);
            }
        }

        return Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'direction' => ['required', Rule::in(self::DIRECTIONS)],
            'treasury_reference_type' => ['required', Rule::in(self::REFERENCE_TYPES)],
            'treasury_reference_id' => ['required', 'integer', 'min:1'],
            'bank_account_id' => ['required', 'integer', 'min:1'],
            'instrument_number' => ['required', 'string', 'max:80'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'payee_or_drawer' => ['nullable', 'string', 'max:190'],
        ])->validate();
    }

    /**
     * @param  array{due_date_from?:string,due_date_to?:string,overdue?:bool}  $filters
     * @return array{from:string,to:string}
     */
    private function boundedDueWindow(array $filters): array
    {
        $hasFrom = isset($filters['due_date_from']);
        $hasTo = isset($filters['due_date_to']);

        if ($hasFrom) {
            $from = CarbonImmutable::parse((string) $filters['due_date_from'])->startOfDay();
            $to = $hasTo
                ? CarbonImmutable::parse((string) $filters['due_date_to'])->startOfDay()
                : $from->addDays(self::DEFAULT_LIST_WINDOW_DAYS - 1);
        } elseif ($hasTo) {
            $to = CarbonImmutable::parse((string) $filters['due_date_to'])->startOfDay();
            $from = $to->subDays(self::DEFAULT_LIST_WINDOW_DAYS - 1);
        } elseif ((bool) ($filters['overdue'] ?? false)) {
            $to = CarbonImmutable::today()->subDay();
            $from = $to->subDays(self::DEFAULT_LIST_WINDOW_DAYS - 1);
        } else {
            $from = CarbonImmutable::today();
            $to = $from->addDays(self::DEFAULT_LIST_WINDOW_DAYS - 1);
        }

        if (($from->diffInDays($to) + 1) > self::MAX_LIST_WINDOW_DAYS) {
            throw ValidationException::withMessages([
                'due_date_to' => ['Due-date range may not exceed 366 days.'],
            ]);
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    private function trimNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertRequestHash(mixed $stored, string $requestHash): void
    {
        if (! is_string($stored) || ! hash_equals($stored, $requestHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'Idempotency key is already bound to a different Treasury cheque/PDC request.',
                ],
            ]);
        }
    }
}
