<?php

namespace App\Enterprise\Treasury;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TreasuryPlanningService
{
    public const MODULE = 'treasury';

    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const READ_CAPABILITY = 'treasury.payments.read';

    private const MANAGE_CAPABILITY = 'treasury.payments.manage';

    private const APPROVE_CAPABILITY = 'treasury.payments.approve';

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /** @return LengthAwarePaginator<object> */
    public function paginateFacilities(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 20,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $data = Validator::make($filters, [
            'status' => ['nullable', Rule::in(['active', 'closed', 'cancelled'])],
            'q' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $term = $this->safePrefix((string) ($data['q'] ?? ''));
        $drawn = DB::table('enterprise_treasury_drawdowns')
            ->select('facility_id')
            ->selectRaw('SUM(principal_amount) as drawn_principal')
            ->where('status', '!=', 'cancelled')
            ->groupBy('facility_id');

        return DB::table('enterprise_treasury_facilities as facility')
            ->leftJoinSub($drawn, 'drawn', 'drawn.facility_id', '=', 'facility.id')
            ->where('facility.organization_id', $scope->organizationId)
            ->where('facility.company_id', $scope->companyId)
            ->where('facility.legal_entity_id', $scope->legalEntityId)
            ->where('facility.store_id', $scope->storeId)
            ->when(
                $data['status'] ?? null,
                fn ($query, string $status) => $query->where('facility.status', $status),
            )
            ->when(
                $term !== '',
                fn ($query) => $query->where(function ($nested) use ($term): void {
                    $nested->where('facility.code', 'like', $term.'%')
                        ->orWhere('facility.lender_name', 'like', $term.'%');
                }),
            )
            ->select([
                'facility.id',
                'facility.code',
                'facility.lender_name',
                'facility.currency_code',
                'facility.facility_limit',
                'facility.annual_rate',
                'facility.margin_rate',
                'facility.start_date',
                'facility.maturity_date',
                'facility.status',
                'facility.finance_journal_id',
                DB::raw('COALESCE(drawn.drawn_principal, 0) as drawn_principal'),
            ])
            ->orderBy('facility.maturity_date')
            ->orderBy('facility.id')
            ->paginate(max(10, min(50, $perPage)), ['*'], 'facility_page')
            ->withQueryString();
    }

    /** @return LengthAwarePaginator<object> */
    public function paginateRepayments(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 20,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $data = Validator::make($filters, [
            'status' => ['nullable', Rule::in(['scheduled', 'paid', 'cancelled'])],
            'facility_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ])->validate();

        return DB::table('enterprise_treasury_repayments as repayment')
            ->join('enterprise_treasury_drawdowns as drawdown', 'drawdown.id', '=', 'repayment.drawdown_id')
            ->join('enterprise_treasury_facilities as facility', 'facility.id', '=', 'drawdown.facility_id')
            ->where('facility.organization_id', $scope->organizationId)
            ->where('facility.company_id', $scope->companyId)
            ->where('facility.legal_entity_id', $scope->legalEntityId)
            ->where('facility.store_id', $scope->storeId)
            ->when(
                $data['status'] ?? null,
                fn ($query, string $status) => $query->where('repayment.status', $status),
            )
            ->when(
                $data['facility_id'] ?? null,
                fn ($query, int $facilityId) => $query->where('facility.id', $facilityId),
            )
            ->when(
                $data['from'] ?? null,
                fn ($query, string $date) => $query->whereDate('repayment.due_date', '>=', $date),
            )
            ->when(
                $data['to'] ?? null,
                fn ($query, string $date) => $query->whereDate('repayment.due_date', '<=', $date),
            )
            ->select([
                'repayment.id',
                'repayment.installment_number',
                'repayment.due_date',
                'repayment.principal_amount',
                'repayment.interest_amount',
                'repayment.status',
                'repayment.finance_journal_id',
                'repayment.paid_at',
                'drawdown.reference as drawdown_reference',
                'facility.id as facility_id',
                'facility.code as facility_code',
                'facility.lender_name',
                'facility.currency_code',
            ])
            ->orderBy('repayment.due_date')
            ->orderBy('repayment.id')
            ->paginate(max(10, min(50, $perPage)), ['*'], 'repayment_page')
            ->withQueryString();
    }

    /** @return LengthAwarePaginator<object> */
    public function paginateInvestments(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 20,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $data = Validator::make($filters, [
            'status' => ['nullable', Rule::in(['active', 'matured', 'settled', 'cancelled'])],
            'q' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $term = $this->safePrefix((string) ($data['q'] ?? ''));

        return DB::table('enterprise_treasury_investments')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId)
            ->when(
                $data['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->when(
                $term !== '',
                fn ($query) => $query->where(function ($nested) use ($term): void {
                    $nested->where('code', 'like', $term.'%')
                        ->orWhere('institution', 'like', $term.'%')
                        ->orWhere('instrument_type', 'like', $term.'%');
                }),
            )
            ->orderBy('maturity_date')
            ->orderBy('id')
            ->paginate(max(10, min(50, $perPage)), ['*'], 'investment_page')
            ->withQueryString();
    }

    /** @return Collection<int,\stdClass> */
    public function facilityOptions(User $actor, ResolvedTenantContext $context): Collection
    {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);

        return DB::table('enterprise_treasury_facilities')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId)
            ->where('status', 'active')
            ->orderBy('code')
            ->limit(150)
            ->get(['id', 'code', 'lender_name', 'currency_code', 'facility_limit', 'maturity_date']);
    }

    /** @return Collection<int,\stdClass> */
    public function postedJournalOptions(User $actor, ResolvedTenantContext $context): Collection
    {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);

        return DB::table('enterprise_finance_journals')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('status', 'posted')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'journal_number', 'entry_date', 'description']);
    }

    public function createFacility(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): int {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = Validator::make($attributes, [
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'code' => ['required', 'string', 'max:64'],
            'lender_name' => ['required', 'string', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'facility_limit' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'annual_rate' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,6})?$/'],
            'margin_rate' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,6})?$/'],
            'start_date' => ['required', 'date'],
            'maturity_date' => ['required', 'date', 'after_or_equal:start_date'],
            'finance_journal_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        if ($this->toMills($data['facility_limit']) < 1) {
            throw ValidationException::withMessages(['facility_limit' => ['Facility limit must be greater than zero.']]);
        }

        $normalized = [
            'code' => strtoupper(trim((string) $data['code'])),
            'lender_name' => trim((string) $data['lender_name']),
            'currency_code' => strtoupper((string) $data['currency_code']),
            'facility_limit' => $this->formatMills($this->toMills($data['facility_limit'])),
            'annual_rate' => $this->rate((string) $data['annual_rate']),
            'margin_rate' => $this->rate((string) $data['margin_rate']),
            'start_date' => CarbonImmutable::parse((string) $data['start_date'])->toDateString(),
            'maturity_date' => CarbonImmutable::parse((string) $data['maturity_date'])->toDateString(),
            'finance_journal_id' => $this->journalId($scope, $data['finance_journal_id'] ?? null),
        ];
        $idempotencyKey = trim((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $scope, $normalized, $idempotencyKey, $hash): int {
            $existing = DB::table('enterprise_treasury_facilities')
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertHash((string) $existing->request_hash, $hash);

                return (int) $existing->id;
            }

            $id = (int) DB::table('enterprise_treasury_facilities')->insertGetId([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
                ...$normalized,
                'status' => 'active',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record('enterprise.treasury.facility_created', $actor, null, null, [
                'store_id' => $scope->storeId,
                'facility_id' => $id,
                'code' => $normalized['code'],
                'currency_code' => $normalized['currency_code'],
                'facility_limit' => $normalized['facility_limit'],
                'finance_journal_id' => $normalized['finance_journal_id'],
            ]);

            return $id;
        }, 3);
    }

    public function createDrawdown(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): int {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = Validator::make($attributes, [
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'facility_id' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:80'],
            'principal_amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'scheduled_interest_total' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'drawdown_date' => ['required', 'date'],
            'maturity_date' => ['required', 'date', 'after:drawdown_date'],
            'installment_count' => ['required', 'integer', 'between:1,120'],
            'finance_journal_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $principalMills = $this->toMills($data['principal_amount']);
        $interestMills = $this->toMills($data['scheduled_interest_total']);
        if ($principalMills < 1) {
            throw ValidationException::withMessages(['principal_amount' => ['Drawdown principal must be greater than zero.']]);
        }

        return DB::transaction(function () use (
            $actor,
            $scope,
            $data,
            $principalMills,
            $interestMills,
        ): int {
            $facility = $this->facilityForScope($scope, (int) $data['facility_id'], true);
            if ((string) $facility->status !== 'active') {
                throw ValidationException::withMessages(['facility_id' => ['Only active facilities accept drawdowns.']]);
            }

            $drawdownDate = CarbonImmutable::parse((string) $data['drawdown_date']);
            $maturityDate = CarbonImmutable::parse((string) $data['maturity_date']);
            $facilityStart = CarbonImmutable::parse((string) $facility->start_date);
            $facilityMaturity = CarbonImmutable::parse((string) $facility->maturity_date);
            if ($drawdownDate->lessThan($facilityStart) || $maturityDate->greaterThan($facilityMaturity)) {
                throw ValidationException::withMessages([
                    'maturity_date' => ['Drawdown dates must remain inside the facility effective window.'],
                ]);
            }

            $installmentCount = (int) $data['installment_count'];
            if ($drawdownDate->diffInDays($maturityDate) < $installmentCount) {
                throw ValidationException::withMessages([
                    'installment_count' => ['Repayment installments require at least one day per installment.'],
                ]);
            }

            $idempotencyKey = trim((string) $data['idempotency_key']);
            $journalId = $this->journalId($scope, $data['finance_journal_id'] ?? null);
            $normalized = [
                'reference' => strtoupper(trim((string) $data['reference'])),
                'principal_amount' => $this->formatMills($principalMills),
                'scheduled_interest_total' => $this->formatMills($interestMills),
                'drawdown_date' => $drawdownDate->toDateString(),
                'maturity_date' => $maturityDate->toDateString(),
                'installment_count' => $installmentCount,
                'finance_journal_id' => $journalId,
            ];
            $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
            $existing = DB::table('enterprise_treasury_drawdowns')
                ->where('facility_id', $facility->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertHash((string) $existing->request_hash, $hash);

                return (int) $existing->id;
            }

            $alreadyDrawnMills = $this->toMills((string) DB::table('enterprise_treasury_drawdowns')
                ->where('facility_id', $facility->id)
                ->where('status', '!=', 'cancelled')
                ->sum('principal_amount'));
            if ($alreadyDrawnMills + $principalMills > $this->toMills((string) $facility->facility_limit)) {
                throw ValidationException::withMessages([
                    'principal_amount' => ['Drawdown exceeds the available facility limit.'],
                ]);
            }

            $id = (int) DB::table('enterprise_treasury_drawdowns')->insertGetId([
                'facility_id' => $facility->id,
                ...$normalized,
                'status' => 'active',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $principalParts = $this->splitMills($principalMills, $installmentCount);
            $interestParts = $this->splitMills($interestMills, $installmentCount);
            $days = $drawdownDate->diffInDays($maturityDate);
            for ($index = 1; $index <= $installmentCount; $index++) {
                $due = $index === $installmentCount
                    ? $maturityDate
                    : $drawdownDate->addDays((int) floor(($days * $index) / $installmentCount));

                DB::table('enterprise_treasury_repayments')->insert([
                    'drawdown_id' => $id,
                    'installment_number' => $index,
                    'due_date' => $due->toDateString(),
                    'principal_amount' => $this->formatMills($principalParts[$index - 1]),
                    'interest_amount' => $this->formatMills($interestParts[$index - 1]),
                    'status' => 'scheduled',
                    'finance_journal_id' => null,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record('enterprise.treasury.drawdown_created', $actor, null, null, [
                'store_id' => $scope->storeId,
                'facility_id' => (int) $facility->id,
                'drawdown_id' => $id,
                'principal_amount' => $normalized['principal_amount'],
                'scheduled_interest_total' => $normalized['scheduled_interest_total'],
                'installment_count' => $installmentCount,
                'finance_journal_id' => $journalId,
            ]);

            return $id;
        }, 3);
    }

    public function markRepaymentPaid(
        User $actor,
        ResolvedTenantContext $context,
        int $repaymentId,
        int $financeJournalId,
    ): void {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::APPROVE_CAPABILITY);

        DB::transaction(function () use ($actor, $scope, $repaymentId, $financeJournalId): void {
            $repayment = DB::table('enterprise_treasury_repayments as repayment')
                ->join('enterprise_treasury_drawdowns as drawdown', 'drawdown.id', '=', 'repayment.drawdown_id')
                ->join('enterprise_treasury_facilities as facility', 'facility.id', '=', 'drawdown.facility_id')
                ->where('repayment.id', $repaymentId)
                ->where('facility.organization_id', $scope->organizationId)
                ->where('facility.company_id', $scope->companyId)
                ->where('facility.legal_entity_id', $scope->legalEntityId)
                ->where('facility.store_id', $scope->storeId)
                ->select([
                    'repayment.id',
                    'repayment.status',
                    'repayment.created_by',
                    'repayment.principal_amount',
                    'repayment.interest_amount',
                    'facility.id as facility_id',
                ])
                ->lockForUpdate()
                ->first();

            if ($repayment === null) {
                abort(404);
            }
            if ((string) $repayment->status === 'paid') {
                return;
            }
            if ((string) $repayment->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => ['Only scheduled repayments can be marked paid.']]);
            }
            if ((int) $repayment->created_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'approver' => ['The drawdown maker cannot confirm the same repayment.'],
                ]);
            }

            $journalId = $this->journalId($scope, $financeJournalId);
            if ($journalId === null) {
                throw ValidationException::withMessages([
                    'finance_journal_id' => ['A posted Finance journal is required to confirm repayment.'],
                ]);
            }

            DB::table('enterprise_treasury_repayments')->where('id', $repaymentId)->update([
                'status' => 'paid',
                'finance_journal_id' => $journalId,
                'paid_at' => now(),
                'paid_by' => $actor->id,
                'updated_at' => now(),
            ]);

            $this->audit->record('enterprise.treasury.repayment_paid', $actor, null, null, [
                'store_id' => $scope->storeId,
                'facility_id' => (int) $repayment->facility_id,
                'repayment_id' => $repaymentId,
                'principal_amount' => (string) $repayment->principal_amount,
                'interest_amount' => (string) $repayment->interest_amount,
                'finance_journal_id' => $journalId,
            ]);
        }, 3);
    }

    public function createInvestment(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): int {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = Validator::make($attributes, [
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'code' => ['required', 'string', 'max:64'],
            'institution' => ['required', 'string', 'max:190'],
            'instrument_type' => ['required', 'string', 'max:100'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'principal_amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'annual_yield_rate' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,6})?$/'],
            'expected_return_amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'placement_date' => ['required', 'date'],
            'maturity_date' => ['required', 'date', 'after:placement_date'],
            'finance_journal_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $principalMills = $this->toMills($data['principal_amount']);
        if ($principalMills < 1) {
            throw ValidationException::withMessages(['principal_amount' => ['Investment principal must be greater than zero.']]);
        }

        $normalized = [
            'code' => strtoupper(trim((string) $data['code'])),
            'institution' => trim((string) $data['institution']),
            'instrument_type' => trim((string) $data['instrument_type']),
            'currency_code' => strtoupper((string) $data['currency_code']),
            'principal_amount' => $this->formatMills($principalMills),
            'annual_yield_rate' => $this->rate((string) $data['annual_yield_rate']),
            'expected_return_amount' => $this->formatMills($this->toMills($data['expected_return_amount'])),
            'placement_date' => CarbonImmutable::parse((string) $data['placement_date'])->toDateString(),
            'maturity_date' => CarbonImmutable::parse((string) $data['maturity_date'])->toDateString(),
            'finance_journal_id' => $this->journalId($scope, $data['finance_journal_id'] ?? null),
        ];
        $idempotencyKey = trim((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $scope, $normalized, $idempotencyKey, $hash): int {
            $existing = DB::table('enterprise_treasury_investments')
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertHash((string) $existing->request_hash, $hash);

                return (int) $existing->id;
            }

            $id = (int) DB::table('enterprise_treasury_investments')->insertGetId([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
                ...$normalized,
                'status' => 'active',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record('enterprise.treasury.investment_created', $actor, null, null, [
                'store_id' => $scope->storeId,
                'investment_id' => $id,
                'code' => $normalized['code'],
                'principal_amount' => $normalized['principal_amount'],
                'expected_return_amount' => $normalized['expected_return_amount'],
                'currency_code' => $normalized['currency_code'],
                'finance_journal_id' => $normalized['finance_journal_id'],
            ]);

            return $id;
        }, 3);
    }

    public function settleInvestment(
        User $actor,
        ResolvedTenantContext $context,
        int $investmentId,
        string $settlementReference,
        int $financeJournalId,
    ): void {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::APPROVE_CAPABILITY);
        $settlementReference = trim($settlementReference);
        if ($settlementReference === '' || mb_strlen($settlementReference) > 120) {
            throw ValidationException::withMessages([
                'settlement_reference' => ['Settlement reference is required and must not exceed 120 characters.'],
            ]);
        }

        DB::transaction(function () use (
            $actor,
            $scope,
            $investmentId,
            $settlementReference,
            $financeJournalId,
        ): void {
            $investment = DB::table('enterprise_treasury_investments')
                ->where('id', $investmentId)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('store_id', $scope->storeId)
                ->lockForUpdate()
                ->first();
            if ($investment === null) {
                abort(404);
            }
            if ((string) $investment->status === 'settled') {
                return;
            }
            if ((string) $investment->status !== 'active') {
                throw ValidationException::withMessages(['status' => ['Only active investments can be settled.']]);
            }
            if ((int) $investment->created_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'approver' => ['The investment maker cannot settle the same investment.'],
                ]);
            }

            $journalId = $this->journalId($scope, $financeJournalId);
            if ($journalId === null) {
                throw ValidationException::withMessages([
                    'finance_journal_id' => ['A posted Finance journal is required to settle an investment.'],
                ]);
            }

            DB::table('enterprise_treasury_investments')->where('id', $investmentId)->update([
                'status' => 'settled',
                'settlement_reference' => $settlementReference,
                'finance_journal_id' => $journalId,
                'settled_at' => now(),
                'settled_by' => $actor->id,
                'updated_at' => now(),
            ]);

            $this->audit->record('enterprise.treasury.investment_settled', $actor, null, null, [
                'store_id' => $scope->storeId,
                'investment_id' => $investmentId,
                'settlement_reference' => $settlementReference,
                'finance_journal_id' => $journalId,
            ]);
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

    private function facilityForScope(EnterpriseDataScope $scope, int $facilityId, bool $lock): object
    {
        $query = DB::table('enterprise_treasury_facilities')
            ->where('id', $facilityId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $facility = $query->first();
        if ($facility === null) {
            abort(404);
        }

        return $facility;
    }

    private function journalId(EnterpriseDataScope $scope, mixed $journalId): ?int
    {
        if ($journalId === null || $journalId === '') {
            return null;
        }

        $id = (int) $journalId;
        $exists = DB::table('enterprise_finance_journals')
            ->where('id', $id)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('status', 'posted')
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'finance_journal_id' => ['Finance journal must be posted and inside the active legal-entity scope.'],
            ]);
        }

        return $id;
    }

    /** @return list<int> */
    private function splitMills(int $total, int $parts): array
    {
        $base = intdiv($total, $parts);
        $remainder = $total % $parts;

        return array_map(
            static fn (int $index): int => $base + ($index < $remainder ? 1 : 0),
            range(0, $parts - 1),
        );
    }

    private function toMills(mixed $value): int
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private function formatMills(int $mills): string
    {
        return intdiv($mills, 1000).'.'.str_pad((string) ($mills % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function rate(string $value): string
    {
        $rate = (float) $value;
        if ($rate < 0 || $rate > 100) {
            throw ValidationException::withMessages(['rate' => ['Rate must be between 0 and 100 percent.']]);
        }

        return number_format($rate, 6, '.', '');
    }

    private function safePrefix(string $value): string
    {
        return preg_replace('/[%_\\\\]+/', '', trim($value)) ?? '';
    }

    private function assertHash(string $existing, string $expected): void
    {
        if (! hash_equals($existing, $expected)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency key was already used for a different Treasury planning request.'],
            ]);
        }
    }
}
