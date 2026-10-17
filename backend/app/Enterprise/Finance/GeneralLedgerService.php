<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFinanceJournal;
use App\Models\EnterpriseFinanceJournalLine;
use App\Models\EnterpriseFinanceLedgerEntry;
use App\Models\EnterpriseFiscalPeriod;
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

final class GeneralLedgerService
{
    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const POST_PERMISSION = 'finance.post';

    private const READ_CAPABILITY = 'finance.gl.read';

    private const MANAGE_CAPABILITY = 'finance.gl.manage';

    private const POST_CAPABILITY = 'finance.gl.post';

    private const JOURNAL_STATUSES = ['draft', 'posted'];

    private const MAX_LINES = 200;

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
        private readonly FinanceApprovalService $approvals,
    ) {}

    /**
     * @param  array{q?:string,status?:string,fiscal_period_id?:int,date_from?:string,date_to?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseFinanceJournal>
     */
    public function paginateJournals(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::JOURNAL_STATUSES)],
            'fiscal_period_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ])->validate();

        $term = $this->safePrefix((string) ($validated['q'] ?? ''));
        $perPage = max(10, min(100, $perPage));

        return $this->journalQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['fiscal_period_id'] ?? null,
                fn (Builder $query, int $periodId): Builder => $query->where('fiscal_period_id', $periodId),
            )
            ->when(
                $validated['date_from'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '>=', $date),
            )
            ->when(
                $validated['date_to'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '<=', $date),
            )
            ->when(
                $term !== '',
                fn (Builder $query): Builder => $query->where('journal_number', 'like', $term.'%'),
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{account_id?:int,fiscal_period_id?:int,date_from?:string,date_to?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseFinanceLedgerEntry>
     */
    public function paginateLedger(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $validated = Validator::make($filters, [
            'account_id' => ['nullable', 'integer', 'min:1'],
            'fiscal_period_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ])->validate();

        $perPage = max(10, min(100, $perPage));

        return $this->ledgerQuery($scope)
            ->when(
                $validated['account_id'] ?? null,
                fn (Builder $query, int $accountId): Builder => $query->where('account_id', $accountId),
            )
            ->when(
                $validated['fiscal_period_id'] ?? null,
                fn (Builder $query, int $periodId): Builder => $query->where('fiscal_period_id', $periodId),
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

    /**
     * @param array{
     *   idempotency_key:mixed,
     *   entry_date:mixed,
     *   description?:mixed,
     *   lines:mixed
     * } $attributes
     */
    public function createDraft(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFinanceJournal {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = $this->validatedDraft($attributes);
        $entryDate = CarbonImmutable::parse((string) $data['entry_date'])->toDateString();
        $description = $this->trimNullable($data['description'] ?? null);
        $lines = $this->normalizeLines($data['lines']);

        $totalDebitMills = array_sum(array_column($lines, 'debit_mills'));
        $totalCreditMills = array_sum(array_column($lines, 'credit_mills'));

        if ($totalDebitMills < 1 || $totalDebitMills !== $totalCreditMills) {
            throw ValidationException::withMessages([
                'lines' => ['Journal debits and credits must be positive and exactly balanced.'],
            ]);
        }

        $idempotencyKey = trim((string) $data['idempotency_key']);
        $requestHash = $this->requestHash($entryDate, $description, $lines);
        $existing = $this->journalQuery($scope)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof EnterpriseFinanceJournal) {
            $this->assertMatchingIdempotentRequest($existing, $requestHash);

            return $existing->load('lines');
        }

        $period = $this->openPeriodForDate($scope, $entryDate);
        $this->assertPostingAccounts($scope, $lines);
        $this->assertAccountingDimensions($scope, $lines);

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $entryDate,
            $description,
            $lines,
            $totalDebitMills,
            $totalCreditMills,
            $idempotencyKey,
            $requestHash,
            $period,
        ): EnterpriseFinanceJournal {
            $existing = $this->journalQuery($scope)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseFinanceJournal) {
                $this->assertMatchingIdempotentRequest($existing, $requestHash);

                return $existing->load('lines');
            }

            $journal = EnterpriseFinanceJournal::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'fiscal_period_id' => $period->id,
                'journal_number' => 'GL-'.Str::ulid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'entry_date' => $entryDate,
                'description' => $description,
                'status' => 'draft',
                'total_debit' => $this->formatMills($totalDebitMills),
                'total_credit' => $this->formatMills($totalCreditMills),
                'requested_by' => $actor->id,
            ]);

            foreach ($lines as $index => $line) {
                EnterpriseFinanceJournalLine::query()->create([
                    'journal_id' => $journal->id,
                    'line_number' => $index + 1,
                    'account_id' => $line['account_id'],
                    'cost_center_id' => $line['cost_center_id'],
                    'profit_center_id' => $line['profit_center_id'],
                    'debit' => $this->formatMills($line['debit_mills']),
                    'credit' => $this->formatMills($line['credit_mills']),
                    'memo' => $line['memo'],
                ]);
            }

            $this->audit->record(
                'enterprise.finance.journal_created',
                $actor,
                $journal,
                null,
                $this->auditSnapshot($journal->fresh()->toArray(), $context),
            );

            return $journal->refresh()->load('lines');
        }, 3);
    }

    public function post(
        User $actor,
        ResolvedTenantContext $context,
        int $journalId,
        ?FinancePostingApprovalContext $approvalContext = null,
    ): EnterpriseFinanceJournal {
        $scope = $this->authorizedScope($actor, $context, self::POST_PERMISSION, self::POST_CAPABILITY);

        return DB::transaction(function () use ($actor, $context, $scope, $journalId, $approvalContext): EnterpriseFinanceJournal {
            /** @var EnterpriseFinanceJournal $journal */
            $journal = $this->journalQuery($scope)
                ->whereKey($journalId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($journal->status === 'posted') {
                return $journal->load('lines');
            }

            if ((int) $journal->requested_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'poster' => ['The journal requester cannot post the same journal.'],
                ]);
            }

            $entryDate = CarbonImmutable::parse((string) $journal->entry_date)->toDateString();
            $period = $this->fiscalPeriodQuery($scope)
                ->whereKey($journal->fiscal_period_id)
                ->where('status', 'open')
                ->whereDate('starts_on', '<=', $entryDate)
                ->whereDate('ends_on', '>=', $entryDate)
                ->lockForUpdate()
                ->first();

            if (! $period instanceof EnterpriseFiscalPeriod) {
                throw ValidationException::withMessages([
                    'entry_date' => ['The journal entry date must belong to an open fiscal period.'],
                ]);
            }

            /** @var Collection<int, EnterpriseFinanceJournalLine> $journalLines */
            $journalLines = EnterpriseFinanceJournalLine::query()
                ->where('journal_id', $journal->id)
                ->orderBy('line_number')
                ->lockForUpdate()
                ->get();

            if ($journalLines->count() < 2) {
                throw ValidationException::withMessages([
                    'lines' => ['A journal requires at least two lines.'],
                ]);
            }

            $normalizedLines = $journalLines
                ->map(fn (EnterpriseFinanceJournalLine $line): array => [
                    'account_id' => (int) $line->account_id,
                    'cost_center_id' => $line->cost_center_id !== null ? (int) $line->cost_center_id : null,
                    'profit_center_id' => $line->profit_center_id !== null ? (int) $line->profit_center_id : null,
                    'debit_mills' => $this->toMills($line->debit, 'lines'),
                    'credit_mills' => $this->toMills($line->credit, 'lines'),
                    'memo' => $line->memo,
                ])
                ->all();

            $this->assertPostingAccounts($scope, $normalizedLines);

            $totalDebitMills = array_sum(array_column($normalizedLines, 'debit_mills'));
            $totalCreditMills = array_sum(array_column($normalizedLines, 'credit_mills'));
            if (
                $totalDebitMills < 1
                || $totalDebitMills !== $totalCreditMills
                || $totalDebitMills !== $this->toMills($journal->total_debit, 'total_debit')
                || $totalCreditMills !== $this->toMills($journal->total_credit, 'total_credit')
            ) {
                throw ValidationException::withMessages([
                    'lines' => ['Journal totals no longer satisfy the balanced posting contract.'],
                ]);
            }

            if (
                $approvalContext !== null
                && (
                    $approvalContext->makerId !== (int) $journal->requested_by
                    || $this->toMills($approvalContext->amount, 'approval.amount') !== $totalDebitMills
                )
            ) {
                throw ValidationException::withMessages([
                    'approval' => ['Posting approval provenance must match the journal maker and balanced amount.'],
                ]);
            }

            $postingApproval = $approvalContext ?? new FinancePostingApprovalContext(
                action: FinanceApprovalService::ACTION_GL_POST,
                entityType: 'enterprise_finance_journal',
                entityId: (string) $journal->id,
                makerId: (int) $journal->requested_by,
                amount: $this->formatMills($totalDebitMills),
            );
            $this->approvals->assertPostingAuthorized($actor, $context, $postingApproval);

            $postedAt = now();
            foreach ($journalLines as $line) {
                EnterpriseFinanceLedgerEntry::query()->create([
                    'organization_id' => $scope->organizationId,
                    'company_id' => $scope->companyId,
                    'legal_entity_id' => $scope->legalEntityId,
                    'journal_id' => $journal->id,
                    'journal_line_id' => $line->id,
                    'fiscal_period_id' => $period->id,
                    'account_id' => $line->account_id,
                    'entry_date' => $entryDate,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'posted_at' => $postedAt,
                ]);
            }

            $before = $journal->toArray();
            $journal->forceFill([
                'status' => 'posted',
                'posted_at' => $postedAt,
                'posted_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.journal_posted',
                $actor,
                $journal,
                $this->auditSnapshot($before, $context),
                $this->auditSnapshot($journal->fresh()->toArray(), $context),
            );

            return $journal->refresh()->load('lines');
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

    /** @return Builder<EnterpriseFinanceJournal> */
    private function journalQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceJournal::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseFinanceLedgerEntry> */
    private function ledgerQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceLedgerEntry::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseFinanceAccount> */
    private function accountQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceAccount::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseFiscalPeriod> */
    private function fiscalPeriodQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFiscalPeriod::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedDraft(array $attributes): array
    {
        if (array_key_exists('idempotency_key', $attributes)) {
            $attributes['idempotency_key'] = trim((string) $attributes['idempotency_key']);
        }
        if (array_key_exists('description', $attributes) && is_string($attributes['description'])) {
            $attributes['description'] = trim($attributes['description']);
        }

        return Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:'.self::MAX_LINES],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.cost_center_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.profit_center_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.debit' => ['nullable', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'lines.*.credit' => ['nullable', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{account_id:int,cost_center_id:?int,profit_center_id:?int,debit_mills:int,credit_mills:int,memo:?string}>
     */
    private function normalizeLines(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $index => $line) {
            $debitMills = $this->toMills($line['debit'] ?? null, 'lines.'.($index + 1).'.debit');
            $creditMills = $this->toMills($line['credit'] ?? null, 'lines.'.($index + 1).'.credit');

            if (($debitMills > 0) === ($creditMills > 0)) {
                throw ValidationException::withMessages([
                    'lines.'.($index + 1) => ['Each journal line must contain exactly one positive debit or credit amount.'],
                ]);
            }

            $normalized[] = [
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => isset($line['cost_center_id']) && $line['cost_center_id'] !== ''
                    ? (int) $line['cost_center_id']
                    : null,
                'profit_center_id' => isset($line['profit_center_id']) && $line['profit_center_id'] !== ''
                    ? (int) $line['profit_center_id']
                    : null,
                'debit_mills' => $debitMills,
                'credit_mills' => $creditMills,
                'memo' => $this->trimNullable($line['memo'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{account_id:int,cost_center_id:?int,profit_center_id:?int,debit_mills:int,credit_mills:int,memo:?string}>  $lines
     */
    private function assertPostingAccounts(EnterpriseDataScope $scope, array $lines): void
    {
        $ids = array_values(array_unique(array_column($lines, 'account_id')));
        $accounts = $this->accountQuery($scope)
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->where('allow_posting', true)
            ->where('is_control', false)
            ->get(['id'])
            ->keyBy('id');

        foreach ($ids as $id) {
            if (! $accounts->has($id)) {
                throw ValidationException::withMessages([
                    'lines' => ['Every journal account must be an active posting account in the same legal entity.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{account_id:int,cost_center_id:?int,profit_center_id:?int,debit_mills:int,credit_mills:int,memo:?string}>  $lines
     */
    private function assertAccountingDimensions(EnterpriseDataScope $scope, array $lines): void
    {
        $costCenterIds = array_values(array_unique(array_filter(
            array_column($lines, 'cost_center_id'),
            static fn (mixed $id): bool => $id !== null,
        )));
        if ($costCenterIds !== []) {
            $visible = DB::table('enterprise_cost_centers')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('status', 'active')
                ->whereIn('id', $costCenterIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if (count($visible) !== count($costCenterIds)) {
                throw ValidationException::withMessages([
                    'lines' => ['Every cost center must be active in the same legal entity.'],
                ]);
            }
        }

        $profitCenterIds = array_values(array_unique(array_filter(
            array_column($lines, 'profit_center_id'),
            static fn (mixed $id): bool => $id !== null,
        )));
        if ($profitCenterIds !== []) {
            $visible = DB::table('enterprise_profit_centers')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('status', 'active')
                ->whereIn('id', $profitCenterIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if (count($visible) !== count($profitCenterIds)) {
                throw ValidationException::withMessages([
                    'lines' => ['Every profit center must be active in the same legal entity.'],
                ]);
            }
        }
    }

    private function openPeriodForDate(
        EnterpriseDataScope $scope,
        string $entryDate,
    ): EnterpriseFiscalPeriod {
        $period = $this->fiscalPeriodQuery($scope)
            ->where('status', 'open')
            ->whereDate('starts_on', '<=', $entryDate)
            ->whereDate('ends_on', '>=', $entryDate)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->first();

        if (! $period instanceof EnterpriseFiscalPeriod) {
            throw ValidationException::withMessages([
                'entry_date' => ['The journal entry date must belong to an open fiscal period.'],
            ]);
        }

        return $period;
    }

    /**
     * @param  array<int, array{account_id:int,cost_center_id:?int,profit_center_id:?int,debit_mills:int,credit_mills:int,memo:?string}>  $lines
     */
    private function requestHash(
        string $entryDate,
        ?string $description,
        array $lines,
    ): string {
        $normalizedLines = array_map(
            fn (array $line): array => [
                'account_id' => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'],
                'profit_center_id' => $line['profit_center_id'],
                'debit' => $this->formatMills($line['debit_mills']),
                'credit' => $this->formatMills($line['credit_mills']),
                'memo' => $line['memo'],
            ],
            $lines,
        );

        return hash('sha256', json_encode([
            'entry_date' => $entryDate,
            'description' => $description,
            'lines' => $normalizedLines,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertMatchingIdempotentRequest(
        EnterpriseFinanceJournal $journal,
        string $requestHash,
    ): void {
        if (! hash_equals((string) $journal->request_hash, $requestHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The idempotency key is already bound to a different journal request.'],
            ]);
        }
    }

    private function toMills(mixed $value, string $field): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $raw = trim((string) $value);
        if (! preg_match('/^(\d{1,15})(?:\.(\d{1,3}))?$/', $raw, $matches)) {
            throw ValidationException::withMessages([
                $field => ['Amounts must use at most three decimal places.'],
            ]);
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 3, '0');

        return ($whole * 1000) + (int) $fraction;
    }

    private function formatMills(int $mills): string
    {
        return intdiv($mills, 1000).'.'.str_pad((string) ($mills % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function safePrefix(string $term): string
    {
        return str_replace(['%', '_'], '', trim($term));
    }

    private function trimNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditSnapshot(array $values, ResolvedTenantContext $context): array
    {
        return [
            ...$values,
            'store_id' => $context->storeId,
        ];
    }
}
