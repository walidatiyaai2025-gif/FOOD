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
use Illuminate\Database\Query\Builder as QueryBuilder;
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

        return $this->documentQuery($scope, $context)
            ->when(
                $context->isRetail(),
                fn (Builder $query): Builder => $query->where('store_id', $scope->storeId),
            )
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
            'source_invoice_id' => ['nullable', 'integer', 'min:1'],
            'entry_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:entry_date'],
            'amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'receivable_account_id' => ['required', 'integer', 'min:1'],
            'offset_account_id' => ['required', 'integer', 'min:1', 'different:receivable_account_id'],
            'description' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $this->assertStoreInScope($scope, (int) $data['store_id']);
        if ($context->isWholesale()) {
            $this->assertStoreChannel((int) $data['store_id'], 'B2B');
        }
        // A Retail operator cannot create an AR document in a sibling store,
        // even when both stores map to the same enterprise legal entity.
        if ($context->isRetail()) {
            abort_unless($context->ownsStore((int) $data['store_id']), 404);
        }
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
        $sourceInvoiceId = isset($data['source_invoice_id']) ? (int) $data['source_invoice_id'] : null;
        $description = $this->trimNullable($data['description'] ?? null);
        $idempotencyKey = trim((string) $data['idempotency_key']);
        // Preserve the historical idempotency hash for manual/legacy AR
        // documents. A source invoice participates only when explicitly bound.
        $requestPayload = [
            'store_id' => (int) $data['store_id'],
            'customer_id' => (int) $data['customer_id'],
            'entry_date' => $entryDate,
            'due_date' => $dueDate,
            'amount' => $amount,
            'currency_code' => $currency,
            'receivable_account_id' => (int) $data['receivable_account_id'],
            'offset_account_id' => (int) $data['offset_account_id'],
            'description' => $description,
        ];
        if ($sourceInvoiceId !== null) {
            $requestPayload['source_invoice_id'] = $sourceInvoiceId;
        }
        $requestHash = hash('sha256', json_encode($requestPayload, JSON_THROW_ON_ERROR));

        $existing = $this->documentQuery($scope, $context)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        // A source-linked draft is not trusted forever: even a matching
        // idempotency retry must recheck the invoice inside a transaction.
        // Retain the fast path for legacy manual AR drafts only.
        if ($existing instanceof EnterpriseAccountsReceivableDocument && $sourceInvoiceId === null) {
            $this->assertRequestHash($existing->request_hash, $requestHash);

            return $existing;
        }

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $data,
            $entryDate,
            $dueDate,
            $amount,
            $currency,
            $sourceInvoiceId,
            $description,
            $idempotencyKey,
            $requestHash,
        ): EnterpriseAccountsReceivableDocument {
            $existing = $this->documentQuery($scope, $context)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseAccountsReceivableDocument) {
                $this->assertRequestHash($existing->request_hash, $requestHash);

                // A voided, reissued with changed totals, or cross-store
                // invoice must not be re-attested by replaying an old draft
                // request. This shares the invoice lock with fresh drafts.
                if ($sourceInvoiceId !== null) {
                    $this->assertSourceInvoiceMatches(
                        $sourceInvoiceId,
                        (int) $data['store_id'],
                        (int) $data['customer_id'],
                        $currency,
                        $amount,
                    );
                }

                return $existing;
            }

            if ($sourceInvoiceId !== null) {
                // Lock the source invoice as part of the draft transaction. Do not
                // trust a free-text receipt number or a document from another store.
                $this->assertSourceInvoiceMatches(
                    $sourceInvoiceId,
                    (int) $data['store_id'],
                    (int) $data['customer_id'],
                    $currency,
                    $amount,
                );
                if (EnterpriseAccountsReceivableDocument::query()
                    ->where('legal_entity_id', $scope->legalEntityId)
                    ->where('source_invoice_id', $sourceInvoiceId)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'source_invoice_id' => [__('finance_workbench.receivables.source_invoice_invalid')],
                    ]);
                }
            }

            $document = EnterpriseAccountsReceivableDocument::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => (int) $data['store_id'],
                'customer_id' => (int) $data['customer_id'],
                'source_invoice_id' => $sourceInvoiceId,
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
            $document = $this->documentQuery($scope, $context)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            // Invoice-linked AR posting is not yet an independently
            // certified revenue/GL handoff. Never treat an old posted row as
            // approved simply because this call is an idempotent retry.
            if ($document->source_invoice_id !== null && $document->status === 'posted') {
                throw ValidationException::withMessages([
                    'source_invoice_id' => [__('finance_workbench.receivables.source_invoice_invalid')],
                ]);
            }

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

            if ($document->source_invoice_id !== null) {
                // An invoice can be voided or revised after the AR draft. Prevent
                // a stale source from being posted as an authenticated receivable.
                $this->assertSourceInvoiceMatches(
                    (int) $document->source_invoice_id,
                    (int) $document->store_id,
                    (int) $document->customer_id,
                    (string) $document->currency_code,
                    (string) $document->amount,
                );
                // Invoice identity is not yet a certified accounting handoff.
                // An operational invoice/payment may already have its own GL
                // source posting: never post another revenue/AR journal merely
                // because the customer/store/amount metadata matches.
                throw ValidationException::withMessages([
                    'source_invoice_id' => [__('finance_workbench.receivables.source_invoice_invalid')],
                ]);
            }

            $journal = $this->generalLedger->createDraft(
                $requester,
                $context,
                [
                    'idempotency_key' => 'ar-document:'.$document->id.':'.$document->request_hash,
                    'entry_date' => CarbonImmutable::parse((string) $document->entry_date)->toDateString(),
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
            $journal = $this->generalLedger->post(
                $actor,
                $context,
                $journal->id,
                new FinancePostingApprovalContext(
                    action: FinanceApprovalService::ACTION_AR_POST,
                    entityType: 'enterprise_ar_document',
                    entityId: (string) $document->id,
                    makerId: (int) $document->created_by,
                    amount: (string) $document->amount,
                    currencyCode: (string) $document->currency_code,
                ),
            );

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

        return DB::transaction(function () use ($actor, $context, $scope, $documentId): EnterpriseAccountsReceivableDocument {
            $document = $this->documentQuery($scope, $context)
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
            'source_collection_allocation_id' => ['nullable', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:180'],
        ])->validate();

        // One authoritative row lock for all draft idempotency and outstanding
        // checks on this receivable. Keep the draft, its audit entry and retry
        // identity atomic; the database unique constraint remains the last
        // defense against duplicate requests.
        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $documentId,
            $data,
        ): EnterpriseAccountsReceivableAllocation {
            $document = $this->documentQuery($scope, $context)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== 'posted') {
                throw ValidationException::withMessages([
                    'status' => ['Only posted AR documents can receive allocations.'],
                ]);
            }

            // A settlement must debit a distinct cash/settlement account.
            // Debiting and crediting the receivable account itself would
            // falsely clear outstanding AR with no collected consideration.
            if ((int) $data['settlement_account_id'] === (int) $document->receivable_account_id) {
                throw ValidationException::withMessages([
                    'settlement_account_id' => [__('finance_workbench.receivables.settlement_account_same_as_ar')],
                ]);
            }

            $this->assertPostingAccount($scope, (int) $data['settlement_account_id']);
            $amount = $this->formatMills($this->toMills($data['amount']));
            if ($this->toMills($amount) < 1) {
                throw ValidationException::withMessages([
                    'amount' => ['Allocation amount must be greater than zero.'],
                ]);
            }

            $allocationDate = CarbonImmutable::parse((string) $data['allocation_date'])->toDateString();
            $idempotencyKey = trim((string) $data['idempotency_key']);
            $reference = $this->trimNullable($data['reference'] ?? null);
            $sourceCollectionAllocationId = isset($data['source_collection_allocation_id'])
                ? (int) $data['source_collection_allocation_id'] : null;
            $requestPayload = [
                'allocation_date' => $allocationDate,
                'amount' => $amount,
                'settlement_account_id' => (int) $data['settlement_account_id'],
                'reference' => $reference,
            ];
            // Preserve the exact legacy idempotency hash when no source is bound.
            if ($sourceCollectionAllocationId !== null) {
                $requestPayload['source_collection_allocation_id'] = $sourceCollectionAllocationId;
            }
            $requestHash = hash('sha256', json_encode($requestPayload, JSON_THROW_ON_ERROR));

            $existing = EnterpriseAccountsReceivableAllocation::query()
                ->where('ar_document_id', $document->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof EnterpriseAccountsReceivableAllocation) {
                $this->assertRequestHash($existing->request_hash, $requestHash);

                // A successful draft retry is not evidence that its original
                // operational receipt is still valid. Collections may later
                // be reversed, disabled or moved out of scope; revalidate
                // under the original document/source locks without claiming
                // this allocation's own unique source as a second consumer.
                if ($sourceCollectionAllocationId !== null) {
                    $this->assertCollectionAllocationMatches(
                        $document,
                        $context,
                        $sourceCollectionAllocationId,
                        $amount,
                        (int) $existing->getKey(),
                    );
                }

                return $existing;
            }

            if ($this->toMills($amount) > $this->toMills($this->outstandingAmount($document))) {
                throw ValidationException::withMessages([
                    'amount' => ['Allocation exceeds the outstanding AR amount.'],
                ]);
            }

            if ($sourceCollectionAllocationId !== null) {
                $this->assertCollectionAllocationMatches(
                    $document,
                    $context,
                    $sourceCollectionAllocationId,
                    $amount,
                );
            }

            $allocation = EnterpriseAccountsReceivableAllocation::query()->create([
                'ar_document_id' => $document->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'allocation_date' => $allocationDate,
                'amount' => $amount,
                'settlement_account_id' => (int) $data['settlement_account_id'],
                'source_collection_allocation_id' => $sourceCollectionAllocationId,
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
        }, 3);
    }

    public function postAllocation(
        User $actor,
        ResolvedTenantContext $context,
        int $allocationId,
        bool $requiresVerifiedCollectionSource = false,
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
            $requiresVerifiedCollectionSource,
        ): EnterpriseAccountsReceivableAllocation {
            // Read the scoped allocation identity without a row lock first.
            // Draft creation locks the parent AR document before touching
            // allocations. Posting must use the same lock order to prevent
            // opposite-order MySQL deadlocks under concurrent draft/post calls.
            $allocationIdentity = EnterpriseAccountsReceivableAllocation::query()
                ->whereHas(
                    'document',
                    fn (Builder $query): Builder => $query
                        ->where('organization_id', $scope->organizationId)
                        ->where('company_id', $scope->companyId)
                        ->where('legal_entity_id', $scope->legalEntityId),
                )
                ->whereKey($allocationId)
                ->firstOrFail(['ar_document_id']);

            // Check canonical store/channel scope BEFORE touching the allocation
            // or returning an idempotent posted row. The locked document is the
            // authoritative parent; Retail sibling stores remain inaccessible.
            $document = $this->documentQuery($scope, $context)
                ->whereKey($allocationIdentity->ar_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-read and lock the allocation only after the parent document.
            // Never act on the unlocked identity snapshot under concurrency.
            $allocation = EnterpriseAccountsReceivableAllocation::query()
                ->where('ar_document_id', $document->id)
                ->whereKey($allocationId)
                ->lockForUpdate()
                ->firstOrFail();

            // A typed operational receipt is already associated with a
            // collected payment. Until the independent accounting handoff is
            // certified, NEVER post it via this manual AR cash/GL service,
            // including callers using the legacy default-false flag or a
            // previously posted row's idempotent replay. The route-level
            // P303 flag alone cannot enforce this financial invariant.
            if ($allocation->getAttribute('source_collection_allocation_id') !== null) {
                throw ValidationException::withMessages([
                    'collection_source' => [__('finance_workbench.receivables.verification_pending')],
                ]);
            }

            // A historic allocation posted by another legitimate AR flow is
            // not proof that the P303 collection-backed workflow verified it.
            if ($requiresVerifiedCollectionSource && $allocation->status === 'posted') {
                throw ValidationException::withMessages([
                    'collection_source' => [__('finance_workbench.receivables.verification_pending')],
                ]);
            }

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

            // P303 requires a verifiable collected receipt, not an editable
            // reference. The AR allocation schema does not yet persist a
            // trustworthy source link. Refuse this UI posting path until the
            // source can be verified and consumed atomically; preserve the
            // existing canonical manual-AR API for its separate callers.
            if ($requiresVerifiedCollectionSource) {
                throw ValidationException::withMessages([
                    'collection_source' => [__('finance_workbench.receivables.verification_pending')],
                ]);
            }

            // Also reject legacy drafts created before the input guard.
            // No AR journal may debit and credit the same account.
            if ((int) $allocation->settlement_account_id === (int) $document->receivable_account_id) {
                throw ValidationException::withMessages([
                    'settlement_account_id' => [__('finance_workbench.receivables.settlement_account_same_as_ar')],
                ]);
            }

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
                    'entry_date' => CarbonImmutable::parse((string) $allocation->allocation_date)->toDateString(),
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
            $journal = $this->generalLedger->post(
                $actor,
                $context,
                $journal->id,
                new FinancePostingApprovalContext(
                    action: FinanceApprovalService::ACTION_AR_ALLOCATION_POST,
                    entityType: 'enterprise_ar_allocation',
                    entityId: (string) $allocation->id,
                    makerId: (int) $allocation->created_by,
                    amount: (string) $allocation->amount,
                    currencyCode: (string) $document->currency_code,
                ),
            );

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

    /** @return Collection<int, \stdClass> */
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

        return $this->agingQuery($scope, $context, $customerId)
            ->orderBy('document.due_date')
            ->orderBy('document.id')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function paginateAging(
        User $actor,
        ResolvedTenantContext $context,
        ?int $customerId = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            self::READ_CAPABILITY,
        );

        return $this->agingQuery($scope, $context, $customerId)
            ->orderBy('document.due_date')
            ->orderBy('document.id')
            ->paginate(max(10, min(100, $perPage)), ['*'], 'aging_page')
            ->withQueryString();
    }

    /**
     * Complete, currency-separated outstanding balances, calculated in SQL
     * before row pagination; never sum a bounded page or mix currencies.
     *
     * @return Collection<int, \stdClass>
     */
    public function agingTotals(
        User $actor,
        ResolvedTenantContext $context,
        ?int $customerId = null,
    ): Collection {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            self::READ_CAPABILITY,
        );

        return DB::query()
            ->fromSub($this->agingQuery($scope, $context, $customerId), 'aging')
            ->select(['aging.aging_bucket', 'aging.currency_code'])
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw('SUM(aging.outstanding_amount) as outstanding_amount')
            ->groupBy('aging.aging_bucket', 'aging.currency_code')
            ->orderBy('aging.aging_bucket')
            ->orderBy('aging.currency_code')
            ->get();
    }

    private function agingQuery(EnterpriseDataScope $scope, ResolvedTenantContext $context, ?int $customerId): QueryBuilder
    {
        $today = CarbonImmutable::today();
        $allocations = DB::table('enterprise_ar_allocations')
            ->selectRaw('ar_document_id, SUM(amount) as allocated_amount')
            ->where('status', 'posted')
            ->groupBy('ar_document_id');

        return DB::table('enterprise_ar_documents as document')
            ->leftJoinSub($allocations, 'allocations', 'allocations.ar_document_id', '=', 'document.id')
            ->where('document.organization_id', $scope->organizationId)
            ->where('document.company_id', $scope->companyId)
            ->where('document.legal_entity_id', $scope->legalEntityId)
            // Retail context selects one store even when several stores share a legal entity.
            ->when(
                $context->isRetail(),
                fn (QueryBuilder $query) => $query->where('document.store_id', $scope->storeId),
            )
            // Finance is a shared legal-entity ledger, but the ERP AR aging
            // surface is Wholesale-only. Keep Retail documents out even when
            // both channels map to the same legal entity.
            ->when(
                $context->isWholesale(),
                fn (QueryBuilder $query) => $query->whereIn(
                    'document.store_id',
                    DB::table('stores as store')
                        ->join('store_types as type', 'type.id', '=', 'store.store_type_id')
                        ->where('type.code', 'B2B')
                        ->select('store.id'),
                ),
            )
            ->where('document.status', 'posted')
            ->when(
                $customerId !== null,
                fn (QueryBuilder $query) => $query->where('document.customer_id', $customerId),
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
                    $today->toDateString(),
                    $today->subDays(30)->toDateString(),
                    $today->subDays(60)->toDateString(),
                    $today->subDays(90)->toDateString(),
                ],
            );
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
        ResolvedTenantContext $context,
    ): Builder {
        return EnterpriseAccountsReceivableDocument::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->when($context->isRetail(), fn (Builder $query): Builder => $query->where('store_id', $context->requiresStore()))
            ->when(
                $context->isWholesale(),
                fn (Builder $query): Builder => $query->whereIn('store_id', DB::table('stores as store')
                    ->join('store_types as type', 'type.id', '=', 'store.store_type_id')
                    ->where('type.code', 'B2B')
                    ->select('store.id')),
            );
    }

    private function assertStoreChannel(int $storeId, string $channel): void
    {
        $exists = DB::table('stores as store')
            ->join('store_types as type', 'type.id', '=', 'store.store_type_id')
            ->where('store.id', $storeId)
            ->where('type.code', $channel)
            ->exists();

        abort_unless($exists, 404);
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

    /**
     * Certifies only invoice-to-AR identity, not receipt-to-AR settlement.
     * Legacy invoice rows without a store or with an untrusted status fail closed.
     * Called inside the document draft/post transaction to lock the source row.
     */
    private function assertSourceInvoiceMatches(
        int $invoiceId,
        int $storeId,
        int $customerId,
        string $currency,
        string $amount,
    ): void {
        $invoice = DB::table('invoices')
            ->where('id', $invoiceId)
            ->lockForUpdate()
            ->first(['store_id', 'customer_id', 'currency', 'total', 'status', 'voided_at']);

        if ($invoice === null
            || ! in_array((string) $invoice->status, ['issued', 'reissued'], true)
            || $invoice->voided_at !== null
            || (int) $invoice->store_id !== $storeId
            || (int) $invoice->customer_id !== $customerId
            || strtoupper((string) $invoice->currency) !== strtoupper($currency)
            || $this->toMills((string) $invoice->total) !== $this->toMills($amount)) {
            throw ValidationException::withMessages([
                'source_invoice_id' => [__('finance_workbench.receivables.source_invoice_invalid')],
            ]);
        }
    }

    /**
     * Reserve an immutable operational collection allocation as P303 draft
     * evidence. This is NOT an accounting handoff and MUST NOT authorize a
     * Finance GL post: the source may already be recognized in another ledger.
     * The parent AR document is locked by the caller before these reads.
     */
    private function assertCollectionAllocationMatches(
        EnterpriseAccountsReceivableDocument $document,
        ResolvedTenantContext $context,
        int $sourceAllocationId,
        string $amount,
        ?int $replayingAllocationId = null,
    ): void {
        if ($document->source_invoice_id === null) {
            $this->rejectCollectionSource();
        }

        // Matching a customer and store is not enough. Require the typed,
        // still-valid invoice association before inspecting its receipt.
        $this->assertSourceInvoiceMatches(
            (int) $document->source_invoice_id,
            (int) $document->store_id,
            (int) $document->customer_id,
            (string) $document->currency_code,
            (string) $document->amount,
        );

        $source = DB::table('collection_allocations')
            ->where('id', $sourceAllocationId)
            ->lockForUpdate()
            ->first();
        if ($source === null) {
            $this->rejectCollectionSource();
        }

        $transaction = DB::table('collection_transactions')
            ->where('id', $source->collection_transaction_id)
            ->lockForUpdate()
            ->first();
        if ($transaction === null) {
            $this->rejectCollectionSource();
        }

        $account = DB::table('collection_accounts')
            ->where('id', $transaction->collection_account_id)
            ->first();
        $payment = $transaction->payment_id === null
            ? null
            : DB::table('payments')->where('id', $transaction->payment_id)->first();

        $currency = strtoupper((string) $document->currency_code);
        $collector = $context->isRetail() ? 'driver' : 'van_rep';
        if (
            $account === null
            || $payment === null
            || (int) $source->invoice_id !== (int) $document->source_invoice_id
            || $this->toMills((string) $source->amount) !== $this->toMills($amount)
            || strtoupper((string) $source->currency) !== $currency
            || (int) $account->store_id !== (int) $document->store_id
            || (string) $account->status !== 'active'
            || (string) $account->actor_type !== $collector
            || strtoupper((string) $account->currency) !== $currency
            || (string) $transaction->type !== 'collection'
            || (string) $transaction->status !== 'posted'
            // A wallet actor_type alone cannot attest a source: a forged
            // driver-origin collection must never become Wholesale Van cash,
            // nor can a Van-origin receipt be claimed for a Retail Driver.
            || (string) $transaction->source !== ($context->isRetail() ? 'driver_app' : 'van_app')
            || strtoupper((string) $transaction->currency) !== $currency
            || (string) $payment->status !== 'paid'
            // The operational receipt and its Payment must represent the
            // exact same collected funds, down to the KWD mill. A smaller
            // allocation cannot legitimize mismatched source totals.
            || $this->toMills((string) $transaction->amount) !== $this->toMills((string) $payment->amount)
            || (string) $payment->provider !== 'field_collection'
            || (string) $payment->provider_reference !== (string) $transaction->idempotency_key
            || ($payment->invoice_id !== null
                && (int) $payment->invoice_id !== (int) $document->source_invoice_id)
            || strtoupper((string) $payment->currency) !== $currency
        ) {
            $this->rejectCollectionSource();
        }

        // Reversal provenance can be a distinct, later transaction. A reversed
        // source must never be reclassified as a fresh collectible receipt.
        if (DB::table('collection_transactions')
            ->where('reversal_of_id', $transaction->id)
            ->where('status', 'posted')
            ->exists()) {
            $this->rejectCollectionSource();
        }

        $sourceTotal = (string) DB::table('collection_allocations')
            ->where('collection_transaction_id', $transaction->id)
            ->sum('amount');

        $otherClaim = DB::table('enterprise_ar_allocations')
            ->where('source_collection_allocation_id', $sourceAllocationId);
        if ($replayingAllocationId !== null) {
            $otherClaim->where('id', '!=', $replayingAllocationId);
        }

        if (
            $this->toMills($sourceTotal) > $this->toMills((string) $transaction->amount)
            || $this->toMills($sourceTotal) > $this->toMills((string) $payment->amount)
            || $otherClaim->exists()
        ) {
            $this->rejectCollectionSource();
        }
    }

    private function rejectCollectionSource(): never
    {
        throw ValidationException::withMessages([
            'source_collection_allocation_id' => [
                __('finance_workbench.receivables.collection_source_invalid'),
            ],
        ]);
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
