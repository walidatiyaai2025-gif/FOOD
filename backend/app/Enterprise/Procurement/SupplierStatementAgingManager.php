<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterprisePurchaseInvoice;
use App\Models\EnterpriseSupplier;
use App\Models\User;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SupplierStatementAgingManager
{
    private const VIEW_PERMISSION = 'procurement.supplier_statements.view';

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @param  array{from?:string,to?:string,currency_code?:string}  $filters
     * @return LengthAwarePaginator<int, EnterprisePurchaseInvoice>
     */
    public function statement(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context);
        $this->findVisibleSupplier($scope, $supplierId);

        $validated = Validator::make($filters, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ])->validate();
        $window = $this->normalizedDateWindow(
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        $perPage = max(10, min(100, $perPage));
        $currency = isset($validated['currency_code'])
            ? strtoupper((string) $validated['currency_code'])
            : null;

        return $this->visibleInvoiceQuery($scope)
            ->where('supplier_id', $supplierId)
            ->where('status', 'handed_off')
            ->where('invoice_date', '>=', $window['from'])
            ->where(
                'invoice_date',
                '<',
                CarbonImmutable::parse($window['to'])->addDay()->toDateString(),
            )
            ->when(
                $currency,
                fn (Builder $query, string $code): Builder => $query->where('currency_code', $code),
            )
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{as_of:string,currency_code:string}  $filters
     * @return array{
     *     supplier:EnterpriseSupplier,
     *     as_of:string,
     *     currency_code:string,
     *     invoice_count:int,
     *     gross_exposure:string,
     *     buckets:array{
     *         not_due:string,
     *         due_0_30:string,
     *         due_31_60:string,
     *         due_61_90:string,
     *         due_over_90:string,
     *         no_due_date:string
     *     }
     * }
     */
    public function aging(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $filters,
    ): array {
        $scope = $this->authorizedScope($actor, $context);
        $supplier = $this->findVisibleSupplier($scope, $supplierId);
        $validated = Validator::make($filters, [
            'as_of' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ])->validate();

        $asOf = CarbonImmutable::parse((string) $validated['as_of'])->startOfDay();
        $currency = strtoupper((string) $validated['currency_code']);

        $base = $this->visibleInvoiceQuery($scope)
            ->where('supplier_id', $supplierId)
            ->where('status', 'handed_off')
            ->where('currency_code', $currency)
            ->where('invoice_date', '<', $asOf->addDay()->toDateString());

        $nextDay = $asOf->addDay()->toDateString();
        $due30 = $asOf->subDays(30)->toDateString();
        $due60 = $asOf->subDays(60)->toDateString();
        $due90 = $asOf->subDays(90)->toDateString();

        return [
            'supplier' => $supplier,
            'as_of' => $asOf->toDateString(),
            'currency_code' => $currency,
            'invoice_count' => (clone $base)->count(),
            'gross_exposure' => $this->amount((clone $base)->sum('total_amount')),
            'buckets' => [
                'not_due' => $this->amount(
                    (clone $base)
                        ->whereNotNull('due_date')
                        ->where('due_date', '>=', $nextDay)
                        ->sum('total_amount'),
                ),
                'due_0_30' => $this->amount(
                    (clone $base)
                        ->whereNotNull('due_date')
                        ->whereDate('due_date', '>=', $due30)
                        ->where('due_date', '<', $nextDay)
                        ->sum('total_amount'),
                ),
                'due_31_60' => $this->amount(
                    (clone $base)
                        ->whereNotNull('due_date')
                        ->whereDate('due_date', '>=', $due60)
                        ->where('due_date', '<', $due30)
                        ->sum('total_amount'),
                ),
                'due_61_90' => $this->amount(
                    (clone $base)
                        ->whereNotNull('due_date')
                        ->whereDate('due_date', '>=', $due90)
                        ->where('due_date', '<', $due60)
                        ->sum('total_amount'),
                ),
                'due_over_90' => $this->amount(
                    (clone $base)
                        ->whereNotNull('due_date')
                        ->whereDate('due_date', '<', $due90)
                        ->sum('total_amount'),
                ),
                'no_due_date' => $this->amount(
                    (clone $base)
                        ->whereNull('due_date')
                        ->sum('total_amount'),
                ),
            ],
        ];
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
    ): EnterpriseDataScope {
        abort_unless(
            $this->modules->isEnabled('procurement'),
            404,
            'Procurement module is disabled.',
        );
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            'company',
            $scope->companyId,
        );

        return $scope;
    }

    /** @return Builder<EnterprisePurchaseInvoice> */
    private function visibleInvoiceQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseInvoice::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    /** @return Builder<EnterpriseSupplier> */
    private function visibleSupplierQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseSupplier::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where(function (Builder $query) use ($scope): void {
                $query
                    ->where('scope_type', 'company')
                    ->orWhere(function (Builder $store) use ($scope): void {
                        $store
                            ->where('scope_type', 'store')
                            ->where('store_id', $scope->storeId);
                    });
            });
    }

    private function findVisibleSupplier(
        EnterpriseDataScope $scope,
        int $supplierId,
    ): EnterpriseSupplier {
        return $this->visibleSupplierQuery($scope)->whereKey($supplierId)->firstOrFail();
    }

    /** @return array{from:string,to:string} */
    private function normalizedDateWindow(mixed $from, mixed $to): array
    {
        $start = $from === null
            ? null
            : CarbonImmutable::parse((string) $from)->startOfDay();
        $end = $to === null
            ? null
            : CarbonImmutable::parse((string) $to)->startOfDay();

        if ($start === null && $end === null) {
            $end = CarbonImmutable::today();
            $start = $end->subDays(366);
        } elseif ($start === null) {
            $start = $end->subDays(366);
        } elseif ($end === null) {
            $end = $start->addDays(366);
        }

        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages([
                'to' => ['Supplier statement date window cannot exceed 366 days.'],
            ]);
        }

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ];
    }

    private function amount(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
