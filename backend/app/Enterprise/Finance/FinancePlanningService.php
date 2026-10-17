<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFinanceAllocationRule;
use App\Models\EnterpriseFinanceBudget;
use App\Models\EnterpriseFinanceBudgetLine;
use App\Models\EnterpriseFinanceTaxCode;
use App\Models\EnterpriseFiscalPeriod;
use App\Models\EnterpriseFiscalYear;
use App\Models\EnterpriseProfitCenter;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FinancePlanningService
{
    private const CASH_FLOW_CLASSES = ['operating', 'investing', 'financing'];

    private const TAX_DIRECTIONS = ['input', 'output', 'both'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
        private readonly GeneralLedgerService $ledger,
    ) {}

    /** @return LengthAwarePaginator<int, EnterpriseProfitCenter> */
    public function paginateProfitCenters(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.foundation.read');
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ])->validate();
        $term = str_replace(['%', '_'], '', trim((string) ($validated['q'] ?? '')));

        return $this->profitCenterQuery($scope)
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($term !== '', function ($query) use ($term): void {
                $query->where(fn ($nested) => $nested
                    ->where('code', 'like', $term.'%')
                    ->orWhere('name', 'like', $term.'%'));
            })
            ->orderBy('code')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    public function createProfitCenter(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseProfitCenter {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $data = Validator::make($attributes, [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:190'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        abort_if(
            $this->profitCenterQuery($scope)->where('code', $data['code'])->exists(),
            422,
            'Profit center code already exists in this legal entity.',
        );

        if (! empty($data['parent_id'])) {
            $parent = $this->profitCenterQuery($scope)
                ->where('status', 'active')
                ->whereKey((int) $data['parent_id'])
                ->firstOrFail();
        } else {
            $parent = null;
        }

        $center = EnterpriseProfitCenter::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'parent_id' => $parent?->id,
            'code' => $data['code'],
            'name' => trim((string) $data['name']),
            'status' => 'active',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->audit->record('enterprise.finance.profit_center_created', $actor, $center, null, $center->toArray());

        return $center->refresh();
    }

    public function setProfitCenterStatus(
        User $actor,
        ResolvedTenantContext $context,
        int $profitCenterId,
        string $status,
    ): EnterpriseProfitCenter {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        Validator::make(['status' => $status], ['status' => ['required', Rule::in(['active', 'inactive'])]])->validate();

        return DB::transaction(function () use ($actor, $scope, $profitCenterId, $status): EnterpriseProfitCenter {
            $center = $this->profitCenterQuery($scope)->whereKey($profitCenterId)->lockForUpdate()->firstOrFail();
            if ($status === 'inactive' && $this->profitCenterQuery($scope)->where('parent_id', $center->id)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['status' => ['A profit center with active children cannot be deactivated.']]);
            }
            $before = $center->toArray();
            $center->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
            $this->audit->record('enterprise.finance.profit_center_status_changed', $actor, $center, $before, $center->fresh()->toArray());

            return $center->refresh();
        }, 3);
    }

    public function setCashFlowClassification(
        User $actor,
        ResolvedTenantContext $context,
        int $accountId,
        ?string $classification,
    ): EnterpriseFinanceAccount {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $data = Validator::make(
            ['classification' => $classification],
            ['classification' => ['nullable', Rule::in(self::CASH_FLOW_CLASSES)]],
        )->validate();
        $account = $this->account($scope, $accountId);
        $before = $account->toArray();
        $account->forceFill([
            'cash_flow_classification' => $data['classification'] ?? null,
            'updated_by' => $actor->id,
        ])->save();
        $this->audit->record('enterprise.finance.account_cashflow_classified', $actor, $account, $before, $account->fresh()->toArray());

        return $account->refresh();
    }

    /** @return LengthAwarePaginator<int, EnterpriseFinanceBudget> */
    public function paginateBudgets(
        User $actor,
        ResolvedTenantContext $context,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.foundation.read');

        return EnterpriseFinanceBudget::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->withCount('lines')
            ->orderByDesc('fiscal_year_id')
            ->orderByDesc('version')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    /** @return array{budget:EnterpriseFinanceBudget,lines:Collection<int,\stdClass>} */
    public function budgetDetail(
        User $actor,
        ResolvedTenantContext $context,
        int $budgetId,
    ): array {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.foundation.read');
        $budget = $this->budget($scope, $budgetId);
        $lines = DB::table('enterprise_finance_budget_lines as line')
            ->join('enterprise_fiscal_periods as period', 'period.id', '=', 'line.fiscal_period_id')
            ->join('enterprise_finance_accounts as account', 'account.id', '=', 'line.account_id')
            ->leftJoin('enterprise_cost_centers as cost_center', 'cost_center.id', '=', 'line.cost_center_id')
            ->leftJoin('enterprise_profit_centers as profit_center', 'profit_center.id', '=', 'line.profit_center_id')
            ->where('line.budget_id', $budget->id)
            ->orderBy('period.sequence')
            ->orderBy('account.code')
            ->get([
                'line.id', 'line.amount', 'line.notes', 'line.cost_center_id', 'line.profit_center_id',
                'period.id as fiscal_period_id', 'period.code as period_code',
                'account.id as account_id', 'account.code as account_code', 'account.name as account_name',
                'cost_center.code as cost_center_code', 'profit_center.code as profit_center_code',
            ]);

        return ['budget' => $budget, 'lines' => $lines];
    }

    public function createBudget(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFinanceBudget {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $data = Validator::make($attributes, [
            'fiscal_year_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'revision_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $year = $this->fiscalYear($scope, (int) $data['fiscal_year_id']);
        $version = (int) EnterpriseFinanceBudget::query()
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('fiscal_year_id', $year->id)
            ->max('version') + 1;

        $budget = EnterpriseFinanceBudget::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'fiscal_year_id' => $year->id,
            'name' => $data['name'],
            'version' => $version,
            'currency_code' => strtoupper((string) $data['currency_code']),
            'status' => 'draft',
            'revision_note' => $data['revision_note'] ?? null,
            'created_by' => $actor->id,
        ]);
        $this->audit->record('enterprise.finance.budget_created', $actor, $budget, null, $budget->toArray());

        return $budget->refresh();
    }

    public function reviseBudget(
        User $actor,
        ResolvedTenantContext $context,
        int $budgetId,
        string $revisionNote,
    ): EnterpriseFinanceBudget {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $source = $this->budget($scope, $budgetId);
        if (! in_array($source->status, ['approved', 'locked'], true)) {
            throw ValidationException::withMessages(['budget' => ['Only an approved or locked budget can start a new revision.']]);
        }

        return DB::transaction(function () use ($actor, $context, $source, $revisionNote): EnterpriseFinanceBudget {
            $copy = $this->createBudget($actor, $context, [
                'fiscal_year_id' => $source->fiscal_year_id,
                'name' => $source->name,
                'currency_code' => $source->currency_code,
                'revision_note' => $revisionNote,
            ]);
            foreach ($source->lines()->get() as $line) {
                EnterpriseFinanceBudgetLine::query()->create([
                    'budget_id' => $copy->id,
                    'fiscal_period_id' => $line->fiscal_period_id,
                    'account_id' => $line->account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'profit_center_id' => $line->profit_center_id,
                    'amount' => $line->amount,
                    'notes' => $line->notes,
                ]);
            }

            return $copy->refresh();
        }, 3);
    }

    public function saveBudgetLine(
        User $actor,
        ResolvedTenantContext $context,
        int $budgetId,
        array $attributes,
    ): EnterpriseFinanceBudgetLine {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $budget = $this->budget($scope, $budgetId);
        if ($budget->status !== 'draft') {
            throw ValidationException::withMessages(['budget' => ['Budget lines can change only while the budget is draft.']]);
        }
        $data = Validator::make($attributes, [
            'fiscal_period_id' => ['required', 'integer', 'min:1'],
            'account_id' => ['required', 'integer', 'min:1'],
            'cost_center_id' => ['nullable', 'integer', 'min:1'],
            'profit_center_id' => ['nullable', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $period = $this->period($scope, (int) $data['fiscal_period_id']);
        abort_unless((int) $period->fiscal_year_id === (int) $budget->fiscal_year_id, 422, 'Budget period must belong to the selected fiscal year.');
        $this->account($scope, (int) $data['account_id']);
        $this->assertDimensions($scope, $data['cost_center_id'] ?? null, $data['profit_center_id'] ?? null);

        $line = EnterpriseFinanceBudgetLine::query()->updateOrCreate(
            [
                'budget_id' => $budget->id,
                'fiscal_period_id' => $period->id,
                'account_id' => (int) $data['account_id'],
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'profit_center_id' => $data['profit_center_id'] ?? null,
            ],
            [
                'amount' => number_format((float) $data['amount'], 3, '.', ''),
                'notes' => $data['notes'] ?? null,
            ],
        );
        $this->audit->record('enterprise.finance.budget_line_saved', $actor, $budget, null, ['budget_line_id' => $line->id, ...$line->toArray()]);

        return $line->refresh();
    }

    public function transitionBudget(
        User $actor,
        ResolvedTenantContext $context,
        int $budgetId,
        string $action,
    ): EnterpriseFinanceBudget {
        $manage = in_array($action, ['submit'], true);
        $scope = $this->authorizedScope(
            $actor,
            $context,
            $manage ? 'finance.manage' : 'finance.approve',
            $manage ? 'finance.foundation.manage' : 'finance.approvals.approve',
        );

        return DB::transaction(function () use ($actor, $scope, $budgetId, $action): EnterpriseFinanceBudget {
            $budget = $this->budgetQuery($scope)->whereKey($budgetId)->lockForUpdate()->firstOrFail();
            $before = $budget->toArray();

            if ($action === 'submit') {
                abort_unless($budget->status === 'draft', 409);
                abort_if($budget->lines()->count() < 1, 422, 'A budget needs at least one line before submission.');
                $budget->forceFill(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()])->save();
            } elseif ($action === 'approve') {
                abort_unless($budget->status === 'submitted', 409);
                if ((int) $budget->created_by === (int) $actor->id) {
                    throw ValidationException::withMessages(['budget' => ['Budget maker and approver must be different users.']]);
                }
                $budget->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()])->save();
            } elseif ($action === 'lock') {
                abort_unless($budget->status === 'approved', 409);
                $budget->forceFill(['status' => 'locked', 'locked_by' => $actor->id, 'locked_at' => now()])->save();
            } else {
                abort(404);
            }

            $this->audit->record('enterprise.finance.budget_'.$action, $actor, $budget, $before, $budget->fresh()->toArray());

            return $budget->refresh();
        }, 3);
    }

    /** @return LengthAwarePaginator<int, EnterpriseFinanceTaxCode> */
    public function paginateTaxCodes(User $actor, ResolvedTenantContext $context, int $perPage = 25): LengthAwarePaginator
    {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.foundation.read');

        return $this->taxCodeQuery($scope)->orderBy('code')->paginate(max(10, min(100, $perPage)))->withQueryString();
    }

    public function createTaxCode(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFinanceTaxCode {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $data = Validator::make($attributes, [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:190'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'direction' => ['required', Rule::in(self::TAX_DIRECTIONS)],
            'recoverable_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'input_account_id' => ['nullable', 'integer', 'min:1'],
            'output_account_id' => ['nullable', 'integer', 'min:1'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ])->validate();
        foreach (['input_account_id', 'output_account_id'] as $key) {
            if (! empty($data[$key])) {
                $this->account($scope, (int) $data[$key]);
            }
        }

        $code = EnterpriseFinanceTaxCode::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'code' => $data['code'],
            'name' => $data['name'],
            'rate_percent' => number_format((float) $data['rate_percent'], 3, '.', ''),
            'direction' => $data['direction'],
            'recoverable_percent' => number_format((float) ($data['recoverable_percent'] ?? 100), 3, '.', ''),
            'input_account_id' => $data['input_account_id'] ?? null,
            'output_account_id' => $data['output_account_id'] ?? null,
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'status' => 'active',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->audit->record('enterprise.finance.tax_code_created', $actor, $code, null, $code->toArray());

        return $code->refresh();
    }

    public function registerTaxEntry(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): void {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $data = Validator::make($attributes, [
            'tax_code_id' => ['required', 'integer', 'min:1'],
            'journal_line_id' => ['required', 'integer', 'min:1'],
            'direction' => ['required', Rule::in(['input', 'output'])],
            'taxable_amount' => ['required', 'numeric', 'min:0'],
            'source_reference' => ['nullable', 'string', 'max:128'],
        ])->validate();
        $taxCode = $this->taxCodeQuery($scope)->where('status', 'active')->whereKey((int) $data['tax_code_id'])->firstOrFail();
        if ($taxCode->direction !== 'both' && $taxCode->direction !== $data['direction']) {
            throw ValidationException::withMessages(['direction' => ['Tax code direction does not allow this tax entry.']]);
        }
        $line = DB::table('enterprise_finance_journal_lines as line')
            ->join('enterprise_finance_journals as journal', 'journal.id', '=', 'line.journal_id')
            ->where('journal.organization_id', $scope->organizationId)
            ->where('journal.company_id', $scope->companyId)
            ->where('journal.legal_entity_id', $scope->legalEntityId)
            ->where('journal.status', 'posted')
            ->where('line.id', (int) $data['journal_line_id'])
            ->first(['line.id', 'journal.entry_date']);
        abort_if($line === null, 404);
        abort_if(
            (string) $line->entry_date < $taxCode->effective_from->toDateString()
            || ($taxCode->effective_to !== null && (string) $line->entry_date > $taxCode->effective_to->toDateString()),
            422,
            'Tax code is not effective on the journal date.',
        );
        $taxable = round((float) $data['taxable_amount'], 3);
        $taxAmount = round($taxable * ((float) $taxCode->rate_percent / 100), 3);

        DB::table('enterprise_finance_tax_entries')->updateOrInsert(
            ['journal_line_id' => (int) $line->id],
            [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'tax_code_id' => $taxCode->id,
                'direction' => $data['direction'],
                'taxable_amount' => number_format($taxable, 3, '.', ''),
                'tax_amount' => number_format($taxAmount, 3, '.', ''),
                'source_reference' => $data['source_reference'] ?? null,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $this->audit->record('enterprise.finance.tax_entry_registered', $actor, $taxCode, null, [
            'journal_line_id' => (int) $line->id,
            'direction' => $data['direction'],
            'taxable_amount' => $taxable,
            'tax_amount' => $taxAmount,
        ]);
    }

    /** @return LengthAwarePaginator<int, EnterpriseFinanceAllocationRule> */
    public function paginateAllocationRules(User $actor, ResolvedTenantContext $context, int $perPage = 25): LengthAwarePaginator
    {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.foundation.read');

        return $this->allocationRuleQuery($scope)->orderBy('code')->paginate(max(10, min(100, $perPage)))->withQueryString();
    }

    public function createAllocationRule(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFinanceAllocationRule {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $data = Validator::make($attributes, [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:190'],
            'account_id' => ['required', 'integer', 'min:1'],
            'source_cost_center_id' => ['nullable', 'integer', 'min:1'],
            'source_profit_center_id' => ['nullable', 'integer', 'min:1'],
            'target_cost_center_id' => ['nullable', 'integer', 'min:1'],
            'target_profit_center_id' => ['nullable', 'integer', 'min:1'],
            'allocation_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'basis_description' => ['required', 'string', 'max:2000'],
        ])->validate();
        $this->account($scope, (int) $data['account_id']);
        $this->assertDimensions($scope, $data['source_cost_center_id'] ?? null, $data['source_profit_center_id'] ?? null);
        $this->assertDimensions($scope, $data['target_cost_center_id'] ?? null, $data['target_profit_center_id'] ?? null);
        abort_if(
            $this->allocationRuleQuery($scope)->where('code', $data['code'])->exists(),
            422,
            'Allocation rule code already exists.',
        );

        $rule = EnterpriseFinanceAllocationRule::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            ...$data,
            'basis' => 'manual_amount',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $this->audit->record('enterprise.finance.allocation_rule_created', $actor, $rule, null, $rule->toArray());

        return $rule->refresh();
    }

    public function applyAllocationRule(
        User $actor,
        ResolvedTenantContext $context,
        int $ruleId,
        array $attributes,
    ): int {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $rule = $this->allocationRuleQuery($scope)->where('status', 'active')->whereKey($ruleId)->firstOrFail();
        $data = Validator::make($attributes, [
            'entry_date' => ['required', 'date'],
            'base_amount' => ['required', 'numeric', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ])->validate();
        $amount = round((float) $data['base_amount'] * ((float) $rule->allocation_percent / 100), 3);
        $journal = $this->ledger->createDraft($actor, $context, [
            'idempotency_key' => $data['idempotency_key'],
            'entry_date' => $data['entry_date'],
            'description' => 'Allocation '.$rule->code.' · '.$rule->basis_description,
            'lines' => [
                [
                    'account_id' => $rule->account_id,
                    'cost_center_id' => $rule->target_cost_center_id,
                    'profit_center_id' => $rule->target_profit_center_id,
                    'debit' => number_format($amount, 3, '.', ''),
                    'credit' => '0',
                    'memo' => 'Allocation target '.$rule->code,
                ],
                [
                    'account_id' => $rule->account_id,
                    'cost_center_id' => $rule->source_cost_center_id,
                    'profit_center_id' => $rule->source_profit_center_id,
                    'debit' => '0',
                    'credit' => number_format($amount, 3, '.', ''),
                    'memo' => 'Allocation source '.$rule->code,
                ],
            ],
        ]);
        $this->audit->record('enterprise.finance.allocation_journal_created', $actor, $rule, null, [
            'journal_id' => $journal->id, 'amount' => $amount,
        ]);

        return (int) $journal->id;
    }

    public function createElimination(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): int {
        $scope = $this->authorizedScope($actor, $context, 'finance.approve', 'finance.approvals.approve');
        $data = Validator::make($attributes, [
            'fiscal_year_code' => ['required', 'string', 'max:64'],
            'period_code' => ['required', 'string', 'max:64'],
            'account_type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'debit' => ['required', 'numeric', 'min:0'],
            'credit' => ['required', 'numeric', 'min:0'],
            'description' => ['required', 'string', 'max:255'],
        ])->validate();
        abort_if(((float) $data['debit'] > 0) === ((float) $data['credit'] > 0), 422, 'Elimination entry must contain exactly one debit or credit.');

        $id = DB::table('enterprise_finance_consolidation_eliminations')->insertGetId([
            'company_id' => $scope->companyId,
            ...$data,
            'is_active' => true,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->record('enterprise.finance.consolidation_elimination_created', $actor, null, null, [
            'elimination_id' => $id, 'company_id' => $scope->companyId, ...$data,
        ]);

        return $id;
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
        string $capability,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');
        $scope = $context->enterpriseScope;
        $this->authorizer->authorize($actor, $context, $permission, 'legal_entity', $scope->legalEntityId);
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

    private function account(EnterpriseDataScope $scope, int $accountId): EnterpriseFinanceAccount
    {
        return EnterpriseFinanceAccount::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->whereKey($accountId)
            ->firstOrFail();
    }

    private function fiscalYear(EnterpriseDataScope $scope, int $yearId): EnterpriseFiscalYear
    {
        return EnterpriseFiscalYear::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->whereKey($yearId)
            ->firstOrFail();
    }

    private function period(EnterpriseDataScope $scope, int $periodId): EnterpriseFiscalPeriod
    {
        return EnterpriseFiscalPeriod::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->whereKey($periodId)
            ->firstOrFail();
    }

    private function budget(EnterpriseDataScope $scope, int $budgetId): EnterpriseFinanceBudget
    {
        return $this->budgetQuery($scope)->whereKey($budgetId)->firstOrFail();
    }

    private function budgetQuery(EnterpriseDataScope $scope)
    {
        return EnterpriseFinanceBudget::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function profitCenterQuery(EnterpriseDataScope $scope)
    {
        return EnterpriseProfitCenter::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function taxCodeQuery(EnterpriseDataScope $scope)
    {
        return EnterpriseFinanceTaxCode::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function allocationRuleQuery(EnterpriseDataScope $scope)
    {
        return EnterpriseFinanceAllocationRule::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function assertDimensions(EnterpriseDataScope $scope, mixed $costCenterId, mixed $profitCenterId): void
    {
        if ($costCenterId !== null && $costCenterId !== '') {
            abort_unless(
                DB::table('enterprise_cost_centers')
                    ->where('organization_id', $scope->organizationId)
                    ->where('company_id', $scope->companyId)
                    ->where('legal_entity_id', $scope->legalEntityId)
                    ->where('status', 'active')
                    ->where('id', (int) $costCenterId)
                    ->exists(),
                404,
            );
        }
        if ($profitCenterId !== null && $profitCenterId !== '') {
            abort_unless(
                $this->profitCenterQuery($scope)
                    ->where('status', 'active')
                    ->whereKey((int) $profitCenterId)
                    ->exists(),
                404,
            );
        }
    }
}
