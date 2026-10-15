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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FinanceJournalService
{
    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const POST_PERMISSION = 'finance.post';

    private const READ_CAPABILITY = 'finance.journal.read';

    private const MANAGE_CAPABILITY = 'finance.journal.manage';

    private const POST_CAPABILITY = 'finance.journal.post';

    private const STATUSES = ['draft', 'posted'];

    private const AMOUNT_SCALE = 10000;

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{
     *   idempotency_key:string,
     *   entry_date:string,
     *   reference?:string|null,
     *   memo?:string|null,
     *   lines:list<array{account_id:int,debit?:mixed,credit?:mixed,memo?:string|null}>
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
        $lines = $this->canonicalLines($data['lines']);
        $payloadHash = $this->payloadHash($entryDate, $data, $lines);

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $data,
            $entryDate,
            $lines,
            $payloadHash,
        ): EnterpriseFinanceJournal {
            /** @var EnterpriseFinanceJournal|null $existing */
            $existing = $this->journalQuery($scope)
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key is already bound to a different journal payload.'],
                    ]);
                }

                return $existing->load(['lines', 'ledgerEntries']);
            }

            $period = $this->openPeriodForDate($scope, $entryDate, true);
            $this->assertPostingAccounts($scope, $lines, true);

            $journal = EnterpriseFinanceJournal::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'fiscal_period_id' => $period->id,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $payloadHash,
                'entry_date' => $entryDate,
                'reference' => $data['reference'] ?? null,
                'memo' => $data['memo'] ?? null,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $index => $line) {
                EnterpriseFinanceJournalLine::query()->create([
                    'journal_id' => $journal->id,
                    'line_no' => $index + 1,
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'memo' => $line['memo'],
                ]);
            }

            $this->audit->record(
                'enterprise.finance.journal_draft_created',
                $actor,
                $journal,
                null,
                $this->auditSnapshot($journal->toArray(), $context),
            );

            return $journal->refresh()->load(['lines', 'ledgerEntries']);
        }, 3);
    }

    public function post(
        User $actor,
        ResolvedTenantContext $context,
        int $journalId,
    ): EnterpriseFinanceJournal {
        $scope = $this->authorizedScope($actor, $context, self::POST_PERMISSION, self::POST_CAPABILITY);

        return DB::transaction(function () use ($actor, $context, $scope, $journalId): EnterpriseFinanceJournal {
            /** @var EnterpriseFinanceJournal $journal */
            $journal = $this->journalQuery($scope)
                ->whereKey($journalId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($journal->status === 'posted') {
                return $journal->load(['lines', 'ledgerEntries']);
            }

            $entryDate = $journal->entry_date->toDateString();
            $period = $this->openPeriodForDate($scope, $entryDate, true);

            /** @var Collection<int, EnterpriseFinanceJournalLine> $journalLines */
            $journalLines = EnterpriseFinanceJournalLine::query()
                ->where('journal_id', $journal->id)
                ->orderBy('line_no')
                ->lockForUpdate()
                ->get();

            $lines = $journalLines
                ->map(fn (EnterpriseFinanceJournalLine $line): array => [
                    'account_id' => (int) $line->account_id,
                    'debit' => (string) $line->debit,
                    'credit' => (string) $line->credit,
                    'memo' => $line->memo,
                ])
                ->all();

            $this->assertBalanced($lines);
            $this->assertPostingAccounts($scope, $lines, true);

            if ((int) $journal->fiscal_period_id !== (int) $period->id) {
                $journal->forceFill(['fiscal_period_id' => $period->id])->save();
            }

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
                    'posted_by' => $actor->id,
                    'created_at' => now(),
                ]);
            }

            $before = $journal->toArray();
            $journal->forceFill([
                'status' => 'posted',
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $this->audit->record(
                'enterprise.finance.journal_posted',
                $actor,
                $journal,
                $this->auditSnapshot($before, $context),
                $this->auditSnapshot($journal->fresh()->toArray(), $context),
            );

            return $journal->refresh()->load(['lines', 'ledgerEntries']);
        }, 3);
    }

    /**
     * @param array{status?:string,from?:string,to?:string} $filters
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
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ])->validate();
        $perPage = max(10, min(100, $perPage));

        return $this->journalQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['from'] ?? null,
                fn (Builder $query, string $from): Builder => $query->whereDate('entry_date', '>=', $from),
            )
            ->when(
                $validated['to'] ?? null,
                fn (Builder $query, string $to): Builder => $query->whereDate('entry_date', '<=', $to),
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param array{account_id?:int,journal_id?:int,from?:string,to?:string} $filters
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
            'journal_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ])->validate();
        $perPage = max(10, min(100, $perPage));

        return $this->ledgerQuery($scope)
            ->when(
                $validated['account_id'] ?? null,
                fn (Builder $query, int $accountId): Builder => $query->where('account_id', $accountId),
            )
            ->when(
                $validated['journal_id'] ?? null,
                fn (Builder $query, int $journalId): Builder => $query->where('journal_id', $journalId),
            )
            ->when(
                $validated['from'] ?? null,
                fn (Builder $query, string $from): Builder => $query->whereDate('entry_date', '>=', $from),
            )
            ->when(
                $validated['to'] ?? null,
                fn (Builder $query, string $to): Builder => $query->whereDate('entry_date', '<=', $to),
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
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

    private function openPeriodForDate(
        EnterpriseDataScope $scope,
        string $entryDate,
        bool $lock,
    ): EnterpriseFiscalPeriod {
        $query = EnterpriseFiscalPeriod::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('status', 'open')
            ->whereDate('starts_on', '<=', $entryDate)
            ->whereDate('ends_on', '>=', $entryDate)
            ->whereHas('fiscalYear', static fn (Builder $year): Builder => $year->where('status', 'open'));

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var EnterpriseFiscalPeriod|null $period */
        $period = $query->orderBy('sequence')->first();
        if ($period === null) {
            throw ValidationException::withMessages([
                'entry_date' => ['Journal entry date must belong to an open fiscal period.'],
            ]);
        }

        return $period;
    }

    /**
     * @param list<array{account_id:int,debit:string,credit:string,memo:?string}> $lines
     */
    private function assertPostingAccounts(
        EnterpriseDataScope $scope,
        array $lines,
        bool $lock,
    ): void {
        $accountIds = collect($lines)
            ->pluck('account_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $query = EnterpriseFinanceAccount::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->whereIn('id', $accountIds->all());

        if ($lock) {
            $query->lockForUpdate();
        }

        $accounts = $query->get()->keyBy('id');
        foreach ($accountIds as $accountId) {
            /** @var EnterpriseFinanceAccount|null $account */
            $account = $accounts->get($accountId);
            if (
                $account === null
                || $account->status !== 'active'
                || $account->is_control
                || ! $account->allow_posting
            ) {
                throw ValidationException::withMessages([
                    'lines' => ['Every journal account must be an active posting account in the same legal entity.'],
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{
     *   idempotency_key:string,
     *   entry_date:string,
     *   reference?:string|null,
     *   memo?:string|null,
     *   lines:list<array{account_id:int,debit?:mixed,credit?:mixed,memo?:string|null}>
     * }
     */
    private function validatedDraft(array $attributes): array
    {
        if (array_key_exists('idempotency_key', $attributes)) {
            $attributes['idempotency_key'] = trim((string) $attributes['idempotency_key']);
        }
        if (array_key_exists('reference', $attributes) && is_string($attributes['reference'])) {
            $attributes['reference'] = trim($attributes['reference']);
        }
        if (array_key_exists('memo', $attributes) && is_string($attributes['memo'])) {
            $attributes['memo'] = trim($attributes['memo']);
        }

        /** @var array{
         *   idempotency_key:string,
         *   entry_date:string,
         *   reference?:string|null,
         *   memo?:string|null,
         *   lines:list<array{account_id:int,debit?:mixed,credit?:mixed,memo?:string|null}>
         * } $validated
         */
        $validated = Validator::make($attributes, [
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'entry_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return $validated;
    }

    /**
     * @param list<array{account_id:int,debit?:mixed,credit?:mixed,memo?:string|null}> $rawLines
     * @return list<array{account_id:int,debit:string,credit:string,memo:?string}>
     */
    private function canonicalLines(array $rawLines): array
    {
        $lines = [];
        foreach ($rawLines as $index => $line) {
            $debitUnits = $this->amountToUnits($line['debit'] ?? 0, "lines.$index.debit");
            $creditUnits = $this->amountToUnits($line['credit'] ?? 0, "lines.$index.credit");

            if (($debitUnits === 0) === ($creditUnits === 0)) {
                throw ValidationException::withMessages([
                    "lines.$index" => ['Each journal line must contain exactly one positive debit or credit amount.'],
                ]);
            }

            $memo = isset($line['memo']) ? trim((string) $line['memo']) : null;
            $lines[] = [
                'account_id' => (int) $line['account_id'],
                'debit' => $this->unitsToAmount($debitUnits),
                'credit' => $this->unitsToAmount($creditUnits),
                'memo' => $memo === '' ? null : $memo,
            ];
        }

        $this->assertBalanced($lines);

        return $lines;
    }

    /**
     * @param list<array{account_id:int,debit:string,credit:string,memo:?string}> $lines
     */
    private function assertBalanced(array $lines): void
    {
        $debits = 0;
        $credits = 0;

        foreach ($lines as $index => $line) {
            $debits += $this->amountToUnits($line['debit'], "lines.$index.debit");
            $credits += $this->amountToUnits($line['credit'], "lines.$index.credit");
        }

        if ($debits <= 0 || $debits !== $credits) {
            throw ValidationException::withMessages([
                'lines' => ['Journal debits and credits must be equal and greater than zero.'],
            ]);
        }
    }

    private function amountToUnits(mixed $value, string $field): int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw ValidationException::withMessages([$field => ['Amount must be a non-negative decimal.']]);
        }

        $raw = trim((string) $value);
        if (! preg_match('/^\d{1,14}(?:\.\d{1,4})?$/', $raw)) {
            throw ValidationException::withMessages([
                $field => ['Amount supports up to 14 whole digits and 4 decimal places.'],
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 4, '0');

        return ((int) $whole * self::AMOUNT_SCALE) + (int) $fraction;
    }

    private function unitsToAmount(int $units): string
    {
        $whole = intdiv($units, self::AMOUNT_SCALE);
        $fraction = $units % self::AMOUNT_SCALE;

        return $whole.'.'.str_pad((string) $fraction, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{account_id:int,debit:string,credit:string,memo:?string}> $lines
     */
    private function payloadHash(string $entryDate, array $data, array $lines): string
    {
        return hash('sha256', json_encode([
            'entry_date' => $entryDate,
            'reference' => $data['reference'] ?? null,
            'memo' => $data['memo'] ?? null,
            'lines' => $lines,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $values
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
