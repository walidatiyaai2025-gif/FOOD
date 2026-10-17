<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFiscalPeriod;
use App\Models\EnterpriseFixedAsset;
use App\Models\EnterpriseFixedAssetCategory;
use App\Models\EnterpriseFixedAssetDepreciationRun;
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

final class FinanceAssetService
{
    private const METHODS = ['straight_line', 'declining_balance', 'none'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly GeneralLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** @return LengthAwarePaginator<int, EnterpriseFixedAsset> */
    public function paginateAssets(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.foundation.read');
        $this->reconcileStatuses($scope);
        $validated = Validator::make($filters, [
            'status' => ['nullable', Rule::in(['active', 'disposal_pending', 'disposed'])],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $term = str_replace(['%', '_'], '', trim((string) ($validated['q'] ?? '')));

        return $this->assetQuery($scope)
            ->with('category')
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['category_id'] ?? null, fn ($query, mixed $id) => $query->where('category_id', (int) $id))
            ->when($term !== '', fn ($query) => $query->where(
                fn ($nested) => $nested->where('asset_code', 'like', $term.'%')->orWhere('name', 'like', $term.'%'),
            ))
            ->orderBy('asset_code')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    /** @return Collection<int, EnterpriseFixedAssetCategory> */
    public function categories(User $actor, ResolvedTenantContext $context)
    {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.foundation.read');

        return $this->categoryQuery($scope)->orderBy('code')->get();
    }

    /** @return array<string,mixed> */
    public function assetDetail(User $actor, ResolvedTenantContext $context, int $assetId): array
    {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.foundation.read');
        $this->reconcileStatuses($scope);
        $asset = $this->assetQuery($scope)->with('category')->whereKey($assetId)->firstOrFail();
        $depreciation = DB::table('enterprise_fixed_asset_depreciation_runs as run')
            ->join('enterprise_fiscal_periods as period', 'period.id', '=', 'run.fiscal_period_id')
            ->join('enterprise_finance_journals as journal', 'journal.id', '=', 'run.journal_id')
            ->where('run.asset_id', $asset->id)
            ->orderBy('period.starts_on')
            ->get([
                'run.id', 'run.amount', 'run.book_value_before', 'run.book_value_after', 'run.status',
                'run.journal_id', 'period.code as period_code', 'period.name as period_name',
                'journal.status as journal_status',
            ]);

        return [
            'asset' => $asset,
            'depreciation' => $depreciation,
            'posted_depreciation' => $this->postedDepreciation($asset->id),
            'book_value' => $this->bookValue($asset),
        ];
    }

    public function createCategory(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFixedAssetCategory {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $data = Validator::make($attributes, [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:190'],
            'depreciation_method' => ['required', Rule::in(self::METHODS)],
            'default_useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'default_declining_rate_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'asset_account_id' => ['required', 'integer', 'min:1'],
            'accumulated_depreciation_account_id' => ['required', 'integer', 'min:1'],
            'depreciation_expense_account_id' => ['required', 'integer', 'min:1'],
            'disposal_gain_account_id' => ['nullable', 'integer', 'min:1'],
            'disposal_loss_account_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        foreach ([
            'asset_account_id',
            'accumulated_depreciation_account_id',
            'depreciation_expense_account_id',
            'disposal_gain_account_id',
            'disposal_loss_account_id',
        ] as $key) {
            if (! empty($data[$key])) {
                $this->postingAccount($scope, (int) $data[$key]);
            }
        }
        if ($data['depreciation_method'] === 'declining_balance' && empty($data['default_declining_rate_percent'])) {
            throw ValidationException::withMessages([
                'default_declining_rate_percent' => ['Declining-balance categories require an annual depreciation rate.'],
            ]);
        }
        abort_if($this->categoryQuery($scope)->where('code', $data['code'])->exists(), 422, 'Asset category code already exists.');

        $category = EnterpriseFixedAssetCategory::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            ...$data,
            'status' => 'active',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->audit->record('enterprise.finance.asset_category_created', $actor, $category, null, $category->toArray());

        return $category->refresh();
    }

    public function createAsset(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFixedAsset {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $data = Validator::make($attributes, [
            'category_id' => ['required', 'integer', 'min:1'],
            'asset_code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:190'],
            'acquisition_date' => ['required', 'date'],
            'capitalization_date' => ['required', 'date', 'after_or_equal:acquisition_date'],
            'acquisition_amount' => ['required', 'numeric', 'gt:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'declining_rate_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'cost_center_id' => ['nullable', 'integer', 'min:1'],
            'profit_center_id' => ['nullable', 'integer', 'min:1'],
            'acquisition_offset_account_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ])->validate();
        $category = $this->categoryQuery($scope)->where('status', 'active')->whereKey((int) $data['category_id'])->firstOrFail();
        $offset = $this->postingAccount($scope, (int) $data['acquisition_offset_account_id']);
        $this->assertDimensions($scope, $data['cost_center_id'] ?? null, $data['profit_center_id'] ?? null);
        abort_if($this->assetQuery($scope)->where('asset_code', $data['asset_code'])->exists(), 422, 'Asset code already exists.');
        $amount = round((float) $data['acquisition_amount'], 3);
        $salvage = round((float) ($data['salvage_value'] ?? 0), 3);
        abort_if($salvage >= $amount, 422, 'Salvage value must be lower than acquisition amount.');
        $life = (int) ($data['useful_life_months'] ?? $category->default_useful_life_months);
        $rate = $data['declining_rate_percent'] ?? $category->default_declining_rate_percent;

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $data,
            $category,
            $offset,
            $amount,
            $salvage,
            $life,
            $rate,
        ): EnterpriseFixedAsset {
            $journal = $this->ledger->createDraft($actor, $context, [
                'idempotency_key' => $data['idempotency_key'],
                'entry_date' => $data['capitalization_date'],
                'description' => 'Asset capitalization · '.$data['asset_code'].' · '.$data['name'],
                'lines' => [
                    [
                        'account_id' => $category->asset_account_id,
                        'cost_center_id' => $data['cost_center_id'] ?? null,
                        'profit_center_id' => $data['profit_center_id'] ?? null,
                        'debit' => number_format($amount, 3, '.', ''),
                        'credit' => '0',
                        'memo' => 'Asset capitalization',
                    ],
                    [
                        'account_id' => $offset->id,
                        'cost_center_id' => $data['cost_center_id'] ?? null,
                        'profit_center_id' => $data['profit_center_id'] ?? null,
                        'debit' => '0',
                        'credit' => number_format($amount, 3, '.', ''),
                        'memo' => 'Asset acquisition offset',
                    ],
                ],
            ]);

            $asset = EnterpriseFixedAsset::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'category_id' => $category->id,
                'asset_code' => $data['asset_code'],
                'name' => $data['name'],
                'acquisition_date' => $data['acquisition_date'],
                'capitalization_date' => $data['capitalization_date'],
                'acquisition_amount' => number_format($amount, 3, '.', ''),
                'salvage_value' => number_format($salvage, 3, '.', ''),
                'useful_life_months' => $life,
                'depreciation_method' => $category->depreciation_method,
                'declining_rate_percent' => $rate,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'profit_center_id' => $data['profit_center_id'] ?? null,
                'acquisition_offset_account_id' => $offset->id,
                'acquisition_journal_id' => $journal->id,
                'status' => 'active',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->audit->record('enterprise.finance.asset_created', $actor, $asset, null, [
                ...$asset->toArray(), 'acquisition_journal_id' => $journal->id,
            ]);

            return $asset->refresh();
        }, 3);
    }

    public function createDepreciationJournal(
        User $actor,
        ResolvedTenantContext $context,
        int $assetId,
        int $periodId,
        string $idempotencyKey,
    ): EnterpriseFixedAssetDepreciationRun {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $asset = $this->assetQuery($scope)->with('category')->whereKey($assetId)->firstOrFail();
        abort_unless($asset->status === 'active', 409, 'Only an active asset can be depreciated.');
        abort_if($asset->depreciation_method === 'none', 422, 'This asset category is configured as non-depreciating.');
        $period = $this->period($scope, $periodId);
        abort_unless($period->status === 'open', 409, 'Depreciation can be prepared only for an open fiscal period.');
        abort_unless(
            DB::table('enterprise_finance_journals')->where('id', $asset->acquisition_journal_id)->where('status', 'posted')->exists(),
            409,
            'Post the acquisition journal before depreciation.',
        );
        $existing = EnterpriseFixedAssetDepreciationRun::query()
            ->where('asset_id', $asset->id)
            ->where('fiscal_period_id', $period->id)
            ->first();
        if ($existing instanceof EnterpriseFixedAssetDepreciationRun) {
            return $existing;
        }

        $bookBefore = $this->bookValue($asset);
        $depreciableRemaining = max(0.0, $bookBefore - (float) $asset->salvage_value);
        abort_if($depreciableRemaining <= 0.0005, 422, 'Asset is already fully depreciated to salvage value.');
        $amount = $asset->depreciation_method === 'straight_line'
            ? ((float) $asset->acquisition_amount - (float) $asset->salvage_value) / max(1, (int) $asset->useful_life_months)
            : $bookBefore * (((float) $asset->declining_rate_percent / 100) / 12);
        $amount = round(min($amount, $depreciableRemaining), 3);
        abort_if($amount <= 0, 422, 'Calculated depreciation amount is zero.');

        return DB::transaction(function () use ($actor, $context, $asset, $period, $idempotencyKey, $bookBefore, $amount): EnterpriseFixedAssetDepreciationRun {
            $journal = $this->ledger->createDraft($actor, $context, [
                'idempotency_key' => $idempotencyKey,
                'entry_date' => CarbonImmutable::parse((string) $period->ends_on)->toDateString(),
                'description' => 'Asset depreciation · '.$asset->asset_code.' · '.$period->code,
                'lines' => [
                    [
                        'account_id' => $asset->category->depreciation_expense_account_id,
                        'cost_center_id' => $asset->cost_center_id,
                        'profit_center_id' => $asset->profit_center_id,
                        'debit' => number_format($amount, 3, '.', ''),
                        'credit' => '0',
                        'memo' => 'Depreciation expense',
                    ],
                    [
                        'account_id' => $asset->category->accumulated_depreciation_account_id,
                        'cost_center_id' => $asset->cost_center_id,
                        'profit_center_id' => $asset->profit_center_id,
                        'debit' => '0',
                        'credit' => number_format($amount, 3, '.', ''),
                        'memo' => 'Accumulated depreciation',
                    ],
                ],
            ]);
            $run = EnterpriseFixedAssetDepreciationRun::query()->create([
                'asset_id' => $asset->id,
                'fiscal_period_id' => $period->id,
                'amount' => number_format($amount, 3, '.', ''),
                'book_value_before' => number_format($bookBefore, 3, '.', ''),
                'book_value_after' => number_format(max((float) $asset->salvage_value, $bookBefore - $amount), 3, '.', ''),
                'journal_id' => $journal->id,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
            $this->audit->record('enterprise.finance.asset_depreciation_prepared', $actor, $asset, null, [
                'run_id' => $run->id, 'period_id' => $period->id, 'journal_id' => $journal->id, 'amount' => $amount,
            ]);

            return $run->refresh();
        }, 3);
    }

    public function prepareDisposal(
        User $actor,
        ResolvedTenantContext $context,
        int $assetId,
        array $attributes,
    ): EnterpriseFixedAsset {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.foundation.manage');
        $asset = $this->assetQuery($scope)->with('category')->whereKey($assetId)->firstOrFail();
        abort_unless($asset->status === 'active', 409, 'Only an active asset can be disposed.');
        $data = Validator::make($attributes, [
            'disposal_date' => ['required', 'date'],
            'disposal_proceeds' => ['required', 'numeric', 'min:0'],
            'settlement_account_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ])->validate();
        $settlement = $this->postingAccount($scope, (int) $data['settlement_account_id']);
        $postedDepreciation = $this->postedDepreciation($asset->id);
        $book = round((float) $asset->acquisition_amount - $postedDepreciation, 3);
        $proceeds = round((float) $data['disposal_proceeds'], 3);
        $difference = round($proceeds - $book, 3);
        if ($difference > 0 && $asset->category->disposal_gain_account_id === null) {
            throw ValidationException::withMessages(['category' => ['Configure a disposal gain account before disposing this asset.']]);
        }
        if ($difference < 0 && $asset->category->disposal_loss_account_id === null) {
            throw ValidationException::withMessages(['category' => ['Configure a disposal loss account before disposing this asset.']]);
        }
        $lines = [
            [
                'account_id' => $settlement->id,
                'cost_center_id' => $asset->cost_center_id,
                'profit_center_id' => $asset->profit_center_id,
                'debit' => number_format($proceeds, 3, '.', ''),
                'credit' => '0',
                'memo' => 'Asset disposal proceeds',
            ],
        ];
        if ($postedDepreciation > 0) {
            $lines[] = [
                'account_id' => $asset->category->accumulated_depreciation_account_id,
                'cost_center_id' => $asset->cost_center_id,
                'profit_center_id' => $asset->profit_center_id,
                'debit' => number_format($postedDepreciation, 3, '.', ''),
                'credit' => '0',
                'memo' => 'Reverse accumulated depreciation',
            ];
        }
        if ($difference < 0) {
            $lines[] = [
                'account_id' => $asset->category->disposal_loss_account_id,
                'cost_center_id' => $asset->cost_center_id,
                'profit_center_id' => $asset->profit_center_id,
                'debit' => number_format(abs($difference), 3, '.', ''),
                'credit' => '0',
                'memo' => 'Asset disposal loss',
            ];
        }
        $lines[] = [
            'account_id' => $asset->category->asset_account_id,
            'cost_center_id' => $asset->cost_center_id,
            'profit_center_id' => $asset->profit_center_id,
            'debit' => '0',
            'credit' => number_format((float) $asset->acquisition_amount, 3, '.', ''),
            'memo' => 'Derecognize asset cost',
        ];
        if ($difference > 0) {
            $lines[] = [
                'account_id' => $asset->category->disposal_gain_account_id,
                'cost_center_id' => $asset->cost_center_id,
                'profit_center_id' => $asset->profit_center_id,
                'debit' => '0',
                'credit' => number_format($difference, 3, '.', ''),
                'memo' => 'Asset disposal gain',
            ];
        }

        return DB::transaction(function () use ($actor, $context, $asset, $data, $proceeds, $lines): EnterpriseFixedAsset {
            $journal = $this->ledger->createDraft($actor, $context, [
                'idempotency_key' => $data['idempotency_key'],
                'entry_date' => $data['disposal_date'],
                'description' => 'Asset disposal · '.$asset->asset_code,
                'lines' => $lines,
            ]);
            $before = $asset->toArray();
            $asset->forceFill([
                'status' => 'disposal_pending',
                'disposal_date' => $data['disposal_date'],
                'disposal_proceeds' => number_format($proceeds, 3, '.', ''),
                'disposal_journal_id' => $journal->id,
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->record('enterprise.finance.asset_disposal_prepared', $actor, $asset, $before, [
                ...$asset->fresh()->toArray(), 'disposal_journal_id' => $journal->id,
            ]);

            return $asset->refresh();
        }, 3);
    }

    private function reconcileStatuses(EnterpriseDataScope $scope): void
    {
        DB::table('enterprise_fixed_asset_depreciation_runs as run')
            ->join('enterprise_finance_journals as journal', 'journal.id', '=', 'run.journal_id')
            ->join('enterprise_fixed_assets as asset', 'asset.id', '=', 'run.asset_id')
            ->where('asset.legal_entity_id', $scope->legalEntityId)
            ->where('run.status', 'draft')
            ->where('journal.status', 'posted')
            ->update(['run.status' => 'posted', 'run.updated_at' => now()]);

        $pending = $this->assetQuery($scope)
            ->where('status', 'disposal_pending')
            ->whereNotNull('disposal_journal_id')
            ->get(['id', 'disposal_journal_id']);
        foreach ($pending as $asset) {
            if (DB::table('enterprise_finance_journals')->where('id', $asset->disposal_journal_id)->where('status', 'posted')->exists()) {
                EnterpriseFixedAsset::query()->whereKey($asset->id)->update(['status' => 'disposed', 'updated_at' => now()]);
            }
        }
    }

    private function postedDepreciation(int $assetId): float
    {
        return round((float) DB::table('enterprise_fixed_asset_depreciation_runs as run')
            ->join('enterprise_finance_journals as journal', 'journal.id', '=', 'run.journal_id')
            ->where('run.asset_id', $assetId)
            ->where('journal.status', 'posted')
            ->sum('run.amount'), 3);
    }

    private function bookValue(EnterpriseFixedAsset $asset): float
    {
        return round(max((float) $asset->salvage_value, (float) $asset->acquisition_amount - $this->postedDepreciation($asset->id)), 3);
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

    private function categoryQuery(EnterpriseDataScope $scope)
    {
        return EnterpriseFixedAssetCategory::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function assetQuery(EnterpriseDataScope $scope)
    {
        return EnterpriseFixedAsset::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function postingAccount(EnterpriseDataScope $scope, int $accountId): EnterpriseFinanceAccount
    {
        return EnterpriseFinanceAccount::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('status', 'active')
            ->where('allow_posting', true)
            ->where('is_control', false)
            ->whereKey($accountId)
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

    private function assertDimensions(EnterpriseDataScope $scope, mixed $costCenterId, mixed $profitCenterId): void
    {
        if ($costCenterId !== null && $costCenterId !== '') {
            abort_unless(
                DB::table('enterprise_cost_centers')
                    ->where('legal_entity_id', $scope->legalEntityId)
                    ->where('status', 'active')
                    ->where('id', (int) $costCenterId)
                    ->exists(),
                404,
            );
        }
        if ($profitCenterId !== null && $profitCenterId !== '') {
            abort_unless(
                DB::table('enterprise_profit_centers')
                    ->where('legal_entity_id', $scope->legalEntityId)
                    ->where('status', 'active')
                    ->where('id', (int) $profitCenterId)
                    ->exists(),
                404,
            );
        }
    }
}
