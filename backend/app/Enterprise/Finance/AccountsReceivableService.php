<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\Customer;
use App\Models\EnterpriseAccountsReceivableAllocation;
use App\Models\EnterpriseAccountsReceivableDocument;
use App\Models\EnterpriseFinanceAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountsReceivableService
{
    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const POST_PERMISSION = 'finance.post';

    private const READ_CAPABILITY = 'finance.ar.read';

    private const MANAGE_CAPABILITY = 'finance.ar.manage';

    private const POST_CAPABILITY = 'finance.ar.post';

    private const STATUSES = ['draft', 'posted', 'cancelled'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly GeneralLedgerService $generalLedger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{customer_id?:int,status?:string,date_from?:string,date_to?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseAccountsReceivableDocument>
     */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            self::READ_CAPABILITY,
        );
        $validated = Validator::make($filters, [
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ])->validate();

        $perPage = max(10, min(100, $perPage));

        return $this->documentQuery($scope)
            ->when(
                $validated['customer_id'] ?? null,
                fn (Builder $query, int $customerId): Builder => $query->where('customer_id', $customerId),
            )
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['date_from'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '>=', $date),
            )
            ->when(
                $validated['date_to'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '<=', $date),
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function createDraft(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseAccountsReceivableDocument {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );
        $data = Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'store_id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'entry_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:entry_date'],
            'amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'receivable_account_id' => ['required', 'integer', 'min:1'],
            'offset_account_id' => ['required', 'integer', 'min:1', 'different:receivable_account_id'],
            'description' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $this->assertStoreInScope($scope, (int) $data['store_id']);
        Customer::query()->findOrFail((int) $data['customer_id']);
        $this->assertPostingAccount($scope, (int) $data['receivable_account_id']);
        $this->assertPostingAccount($scope, (int) $data['offset_account_id']);

        $entryDate = CarbonImmutable::parse((string) $data['entry_date'])->toDateString();
        $dueDate = CarbonImmutable::parse((string) $data['due_date'])->toDateString();
        $amount = $this->formatMills($this->toMills($data['amount']));
        if ($this->toMills($amount) < 1) {
            throw ValidationException::withMessages([
                'amount' => ['AR amount must be greater than zero.'],
            ]);
        }

        $currency = strtoupper((string) ($data['currency_code'] ?? 'KWD'));
        $description = $this->trimNullable($data['description'] ?? null);
        $idempotencyKey = trim((string) $data['idempotency_key']);
        $requestHash = hash('sha256', json_encode([
            'store_id' => (int) $data['store_id'],
            'customer_id' => (int) $data['customer_id'],
            'entry_date' => $entryDate,
            'due_date' => $dueDate,
            'amount' => $amount,
            'currency_code' => $currency,
            'receivable_account_id' => (int) $data['receivable_account_id'],
            'offset_account_id' => (int) $data['offset_account_id'],
            'description' => $description,
        ], JSON_THROW_ON_ERROR));

        $existing = $this->documentQuery($scope)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof EnterpriseAccountsReceivableDocument) {
            $this->assertRequestHash($existing->request_hash, $requestHash);

            return $existing;
        }

        return DB::transaction(function () use (
            $actor,
            $scope,
            $data,
            $entryDate,
            $dueDate,
            $amount,
            $currency,
            $description,
            $idempotencyKey,
            $requestHash,
        ): EnterpriseAccountsReceivableDocument {
            $existing = $this->documentQuery($scope)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseAccountsReceivableDocument) {
                $this->assertRequestHash($existing->request_hash, $requestHash);

                return $existing;
            }

            $document = EnterpriseAccountsReceivableDocument::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => (int) $data['store_id'],
                'customer_id' => (int) $data['customer_id'],
                'document_number' => 'AR-'.Str::ulid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'document_type' => 'invoice',
                'entry_date' => $entryDate,
                'due_date' => $dueDate,
                'amount' => $amount,
                'currency_code' => $currency,
                'receivable_account_id' => (int) $data['receivable_account_id'],
                'offset_account_id' => (int) $data['offset_account_id'],
                'description' => $description,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.finance.ar_document_created',
                $actor,
                $document,
                null,
                $document->fresh()->toArray(),
            );

            return $document->refresh();
        }, 3);
    }

    public function post(
        User $actor,
        ResolvedTenantContext $context,
        int $documentId,
    ): EnterpriseAccountsReceivableDocument {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::POST_PERMISSION,
            self::POST_CAPABILITY,
        );

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $documentId,
        ): EnterpriseAccountsReceivableDocument {
            $document = $this->documentQuery($scope)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status === 'posted') {
                return $document;
            }

            if ($document->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Only draft AR documents can be posted.'],
                ]);
            }

            if ((int) $document->created_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'poster' => ['The AR document creator cannot post the same document.'],
                ]);
            }

            $requester = User::query()->find($document->created_by);
            if (! $requester instanceof User) {
                throw ValidationException::withMessages([
                    'created_by' => ['The AR document creator is unavailable.'],
                ]);
            }

            $journal = $this->generalLedger->createDraft(
                $requester,
                $context,
                [
                    'idempotency_key' => 'ar-document:'.$document->id.':'.$document->request_hash,
                    'entry_date' => $document->entry_date?->toDateString() ?? (string) $document->entry_date,
                    'description' => Str::limit(
                        'AR '.$document->document_number.($document->description ? ': '.$document->description : ''),
                        255,
                        '',
                    ),
                    'lines' => [
                        [
                            'account_id' => (int) $document->receivable_account_id,
                            'debit' => $document->amount,
                            'credit' => '0.000',
                            'memo' => 'AR receivable '.$document->document_number,
                        ],
                        [
                            'account_id' => (int) $document->offset_account_id,
                            'debit' => '0.000',
                            'credit' => $document->amount,
                            'memo' => 'AR offset '.$document->document_number,
                        ],
                    ],
                ],
            );
            $journal = $this->generalLedger->post($actor, $context, $journal->id);

            $before = $document->toArray();
            $document->forceFill([
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_at' => $journal->posted_at ?? now(),
                'posted_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.ar_document_posted',
                $actor,
                $document,
                $before,
                $document->fresh()->toArray(),
            );

            return $document->refresh();
        }, 3);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $documentId,
    ): EnterpriseAccountsReceivableDocument {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );

        return DB::transaction(function () use ($actor, $scope, $documentId): EnterpriseAccountsReceivableDocument {
            $document = $this->documentQuery($scope)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status === 'cancelled') {
                return $document;
            }

            if ($document->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Posted AR documents require a controlled reversal path.'],
                ]);
            }

            $before = $document->toArray();
            $document->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.ar_document_cancelled',
                $actor,
                $document,
                $before,
                $document->fresh()->toArray(),
            );

            return $document->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function createAllocation(
        User $actor,
        ResolvedTenantContext $context,
        int $documentId,
        array $attributes,
    ): EnterpriseAccountsReceivableAllocation {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );
        $data = Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'allocation_date' => ['required', 'date'],
            'amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'settlement_account_id' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:180'],
        ])->validate();

        $document = $this->documentQuery($scope)->whereKey($documentId)->firstOrFail();
        if ($document->status !== 'posted') {
            throw ValidationException::withMessages([
                'status' => ['Only posted AR documents can receive allocations.'],
            ]);
        }

        $this->assertPostingAccount($scope, (int) $data['settlement_account_id']);
        $amount = $this->formatMills($this->toMills($data['amount']));
        if ($this->toMills($amount) < 1) {
            throw ValidationException::withMessages([
                'amount' => ['Allocation amount must be greater than zero.'],
            ]);
        }

        if ($this->toMills($amount) > $this->toMills($this->outstandingAmount($document))) {
            throw ValidationException::withMessages([
                'amount' => ['Allocation exceeds the outstanding AR amount.'],
            ]);
        }

        $allocationDate = CarbonImmutable::parse((string) $data['allocation_date'])->toDateString();
        $idempotencyKey = trim((string) $data['idempotency_key']);
        $reference = $this->trimNullable($data['reference'] ?? null);
        $requestHash = hash('sha256', json_encode([
            'allocation_date' => $allocationDate,
            'amount' => $amount,
            'settlement_account_id' => (int) $data['settlement_account_id'],
            'reference' => $reference,
        ], JSON_THROW_ON_ERROR));

        $existing = EnterpriseAccountsReceivableAllocation::query()
            ->where('ar_document_id', $document->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof EnterpriseAccountsReceivableAllocation) {
            $this->assertRequestHash($existing->request_hash, $requestHash);

            return $existing;
        }

        $allocation = EnterpriseAccountsReceivableAllocation::query()->create([
            'ar_document_id' => $document->id,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'allocation_date' => $allocationDate,
            'amount' => $amount,
            'settlement_account_id' => (int) $data['settlement_account_id'],
            'reference' => $reference,
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);

        $this->audit->record(
            'enterprise.finance.ar_allocation_created',
            $actor,
            $allocation,
            null,
            $allocation->toArray(),
        );

        return $allocation->refresh();
    }

    public function postAllocation(
        User $actor,
        ResolvedTenantContext $context,
        int $allocationId,
    ): EnterpriseAccountsReceivableAllocation {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::POST_PERMISSION,
            self::POST_CAPABILITY,
        );

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $allocationId,
        ): EnterpriseAccountsReceivableAllocation {
            $allocation = EnterpriseAccountsReceivableAllocation::query()
                ->whereHas(
                    'document',
                    fn (Builder $query): Builder => $query
                        ->where('organization_id', $scope->organizationId)
                        ->where('company_id', $scope->companyId)
                        ->where('legal_entity_id', $scope->legalEntityId),
                )
                ->whereKey($allocationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($allocation->status === 'posted') {
                return $allocation;
            }

            if ($allocation->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Only draft AR allocations can be posted.'],
                ]);
            }

            if ((int) $allocation->created_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'poster' => ['The allocation creator cannot post the same allocation.'],
                ]);
            }

            $document = $this->documentQuery($scope)
                ->whereKey($allocation->ar_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            $postedAllocations = EnterpriseAccountsReceivableAllocation::query()
                ->where('ar_document_id', $document->id)
                ->where('status', 'posted')
                ->sum('amount');

            if (
                $this->toMills($postedAllocations) + $this->toMills($allocation->amount)
                > $this->toMills($document->amount)
            ) {
                throw ValidationException::withMessages([
                    'amount' => ['Posting would over-allocate the AR document.'],
                ]);
            }

            $requester = User::query()->find($allocation->created_by);
            if (! $requester instanceof User) {
                throw ValidationException::withMessages([
                    'created_by' => ['The allocation creator is unavailable.'],
                ]);
            }

            $journal = $this->generalLedger->createDraft(
                $requester,
                $context,
                [
                    'idempotency_key' => 'ar-allocation:'.$allocation->id.':'.$allocation->request_hash,
                    'entry_date' => $allocation->allocation_date?->toDateString() ?? (string) $allocation->allocation_date,
                    'description' => 'AR settlement '.$document->document_number,
                    'lines' => [
                        [
                            'account_id' => (int) $allocation->settlement_account_id,
                            'debit' => $allocation->amount,
                            'credit' => '0.000',
                            'memo' => $allocation->reference,
                        ],
                        [
                            'account_id' => (int) $document->receivable_account_id,
                            'debit' => '0.000',
                            'credit' => $allocation->amount,
                            'memo' => 'AR settlement '.$document->document_number,
                        ],
                    ],
                ],
            );
            $journal = $this->generalLedger->post($actor, $context, $journal->id);

            $before = $allocation->toArray();
            $allocation->forceFill([
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_at' => $journal->posted_at ?? now(),
                'posted_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.ar_allocation_posted',
                $actor,
                $allocation,
                $before,
                $allocation->fresh()->toArray(),
            );

            return $allocation->refresh();
        }, 3);
    }

    /** @return Collection<int, object> */
    public function aging(
        User $actor,
        ResolvedTenantContext $context,
        ?int $customerId = null,
        int $limit = 25,
    ): Collection {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            self::READ_CAPABILITY,
        );
        $limit = max(1, min(100, $limit));

        $allocations = DB::table('enterprise_ar_allocations')
            ->selectRaw('ar_document_id, SUM(amount) as allocated_amount')
            ->where('status', 'posted')
            ->groupBy('ar_document_id');

        return DB::table('enterprise_ar_documents as document')
            ->leftJoinSub($allocations, 'allocations', 'allocations.ar_document_id', '=', 'document.id')
            ->where('document.organization_id', $scope->organizationId)
            ->where('document.company_id', $scope->companyId)
            ->where('document.legal_entity_id', $scope->legalEntityId)
            ->where('document.status', 'posted')
            ->when(
                $customerId !== null,
                fn ($query) => $query->where('document.customer_id', $customerId),
            )
            ->whereRaw('(document.amount - COALESCE(allocations.allocated_amount, 0)) > 0')
            ->select([
                'document.id',
                'document.document_number',
                'document.customer_id',
                'document.due_date',
                'document.amount',
                'document.currency_code',
            ])
            ->selectRaw('COALESCE(allocations.allocated_amount, 0) as allocated_amount')
            ->selectRaw(
                '(document.amount - COALESCE(allocations.allocated_amount, 0)) as outstanding_amount',
            )
            ->selectRaw(
                "CASE
                    WHEN document.due_date >= ? THEN 'current'
                    WHEN document.due_date >= ? THEN '1_30'
                    WHEN document.due_date >= ? THEN '31_60'
                    WHEN document.due_date >= ? THEN '61_90'
                    ELSE '91_plus'
                END as aging_bucket",
                [
                    now()->toDateString(),
                    now()->subDays(30)->toDateString(),
                    now()->subDays(60)->toDateString(),
                    now()->subDays(90)->toDateString(),
                ],
            )
            ->orderBy('document.due_date')
            ->orderBy('document.id')
            ->limit($limit)
            ->get();
    }

    private function outstandingAmount(
        EnterpriseAccountsReceivableDocument $document,
    ): string {
        $allocated = EnterpriseAccountsReceivableAllocation::query()
            ->where('ar_document_id', $document->id)
            ->where('status', 'posted')
            ->sum('amount');

        return $this->formatMills(
            max(
                0,
                $this->toMills($document->amount) - $this->toMills($allocated),
            ),
        );
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
                new EntitlementScope(
                    EntitlementScope::LEGAL_ENTITY,
                    $scope->legalEntityId,
                ),
                $capability,
            ),
            403,
        );

        return $scope;
    }

    /** @return Builder<EnterpriseAccountsReceivableDocument> */
    private function documentQuery(
        EnterpriseDataScope $scope,
    ): Builder {
        return EnterpriseAccountsReceivableDocument::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function assertStoreInScope(
        EnterpriseDataScope $scope,
        int $storeId,
    ): void {
        $exists = DB::table('enterprise_store_scopes as store_scope')
            ->join(
                'enterprise_business_units as business_unit',
                'business_unit.id',
                '=',
                'store_scope.business_unit_id',
            )
            ->where('store_scope.store_id', $storeId)
            ->where('business_unit.legal_entity_id', $scope->legalEntityId)
            ->exists();

        abort_unless($exists, 404);
    }

    private function assertPostingAccount(
        EnterpriseDataScope $scope,
        int $accountId,
    ): void {
        $exists = EnterpriseFinanceAccount::query()
            ->whereKey($accountId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('status', 'active')
            ->where('allow_posting', true)
            ->where('is_control', false)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'account_id' => [
                    'Finance account must be an active posting account in the same legal entity.',
                ],
            ]);
        }
    }

    private function toMills(mixed $value): int
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d{1,15}(?:\.\d{1,3})?$/', $raw)) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must have at most three decimals.'],
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private function formatMills(int $mills): string
    {
        return intdiv($mills, 1000).'.'.str_pad(
            (string) ($mills % 1000),
            3,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function trimNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertRequestHash(
        mixed $stored,
        string $requestHash,
    ): void {
        if (! is_string($stored) || ! hash_equals($stored, $requestHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'Idempotency key was already used for a different AR request.',
                ],
            ]);
        }
    }
}
