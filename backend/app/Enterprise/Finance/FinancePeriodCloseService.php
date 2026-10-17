<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseFiscalPeriod;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinancePeriodCloseService
{
    private const CHECKS = [
        'draft_journals' => true,
        'draft_opening_balances' => true,
        'unposted_depreciation' => true,
        'budget_review' => false,
        'tax_review' => false,
    ];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array<string,mixed>> */
    public function evaluate(
        User $actor,
        ResolvedTenantContext $context,
        int $periodId,
    ): array {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.foundation.read');
        $period = $this->period($scope, $periodId);

        $counts = [
            'draft_journals' => DB::table('enterprise_finance_journals')
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('fiscal_period_id', $period->id)
                ->where('status', 'draft')
                ->count(),
            'draft_opening_balances' => DB::table('enterprise_finance_opening_balance_batches')
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('fiscal_period_id', $period->id)
                ->where('status', 'draft')
                ->count(),
            'unposted_depreciation' => DB::table('enterprise_fixed_asset_depreciation_runs as depreciation')
                ->join('enterprise_finance_journals as journal', 'journal.id', '=', 'depreciation.journal_id')
                ->where('depreciation.fiscal_period_id', $period->id)
                ->where('journal.status', '!=', 'posted')
                ->count(),
            'budget_review' => DB::table('enterprise_finance_budgets')
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('fiscal_year_id', $period->fiscal_year_id)
                ->whereIn('status', ['draft', 'submitted'])
                ->count(),
            'tax_review' => DB::table('enterprise_finance_tax_codes')
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('status', 'active')
                ->whereDate('effective_from', '<=', $period->ends_on)
                ->where(function ($query) use ($period): void {
                    $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->starts_on);
                })
                ->count() > 0
                    ? DB::table('enterprise_finance_tax_entries as tax_entry')
                        ->join('enterprise_finance_journal_lines as journal_line', 'journal_line.id', '=', 'tax_entry.journal_line_id')
                        ->join('enterprise_finance_journals as journal', 'journal.id', '=', 'journal_line.journal_id')
                        ->where('tax_entry.legal_entity_id', $scope->legalEntityId)
                        ->where('journal.fiscal_period_id', $period->id)
                        ->where('journal.status', '!=', 'posted')
                        ->count()
                    : 0,
        ];

        $rows = [];
        DB::transaction(function () use ($actor, $period, $counts, &$rows): void {
            foreach (self::CHECKS as $code => $blocking) {
                $count = (int) $counts[$code];
                $status = $count > 0 ? ($blocking ? 'block' : 'warn') : 'pass';
                DB::table('enterprise_finance_close_checks')->updateOrInsert(
                    ['fiscal_period_id' => $period->id, 'check_code' => $code],
                    [
                        'is_blocking' => $blocking,
                        'status' => $status,
                        'exception_count' => $count,
                        'details' => json_encode(['count' => $count], JSON_THROW_ON_ERROR),
                        'evaluated_at' => now(),
                        'evaluated_by' => $actor->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                $rows[] = [
                    'check_code' => $code,
                    'is_blocking' => $blocking,
                    'status' => $status,
                    'exception_count' => $count,
                ];
            }

            $this->audit->record(
                'enterprise.finance.period_close_evaluated',
                $actor,
                $period,
                null,
                ['period_id' => $period->id, 'checks' => $rows],
            );
        }, 3);

        return $rows;
    }

    public function assertReady(
        User $actor,
        ResolvedTenantContext $context,
        int $periodId,
    ): void {
        $checks = $this->evaluate($actor, $context, $periodId);
        $blocked = collect($checks)->filter(
            static fn (array $check): bool => $check['is_blocking'] && $check['status'] === 'block',
        );

        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'fiscal_period' => [
                    'Period close is blocked by: '.$blocked->pluck('check_code')->implode(', ').'.',
                ],
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

    private function period(EnterpriseDataScope $scope, int $periodId): EnterpriseFiscalPeriod
    {
        return EnterpriseFiscalPeriod::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->whereKey($periodId)
            ->firstOrFail();
    }
}
