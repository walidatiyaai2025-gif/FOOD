<?php

namespace App\Enterprise\Reporting\ReadModels;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class FinanceExposureReadModel
{
    private const REPORTS = [
        'finance.ar_exposure' => [
            'read_model' => 'finance_ar_exposure',
            'documents' => 'ar_documents',
            'allocations' => 'ar_allocations',
            'allocation_fk' => 'ar_document_id',
            'party_dimension' => 'customer_id',
        ],
        'finance.ap_exposure' => [
            'read_model' => 'finance_ap_exposure',
            'documents' => 'ap_documents',
            'allocations' => 'ap_allocations',
            'allocation_fk' => 'ap_document_id',
            'party_dimension' => 'supplier_reference',
        ],
    ];

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   as_of:string,
     *   page:int,
     *   per_page:int,
     *   has_more:bool
     * }
     */
    public function read(ReportQuery $query, EntitlementScope $scope): array
    {
        $definition = self::REPORTS[$query->report->code] ?? null;
        if ($definition === null || $query->report->readModel !== $definition['read_model']) {
            throw ValidationException::withMessages([
                'report' => ['Finance exposure read model received an unsupported report definition.'],
            ]);
        }

        if ($scope->type !== EntitlementScope::LEGAL_ENTITY || $scope->id === null) {
            throw ValidationException::withMessages([
                'scope' => ['Finance exposure analytics require an explicit legal-entity scope.'],
            ]);
        }

        $documents = $this->source($definition['documents']);
        $allocations = $this->source($definition['allocations']);
        $this->assertSources([$documents, $allocations]);

        $asOf = $this->asOf($query);
        $allocationForeignKey = $definition['allocation_fk'];

        $allocationTotals = DB::table($allocations)
            ->selectRaw($allocationForeignKey.', SUM(amount) as allocated_amount')
            ->where('status', 'posted')
            ->whereDate('allocation_date', '<=', $asOf->toDateString())
            ->groupBy($allocationForeignKey);

        $builder = DB::table($documents.' as document')
            ->leftJoinSub(
                $allocationTotals,
                'allocations',
                'allocations.'.$allocationForeignKey,
                '=',
                'document.id',
            )
            ->where('document.legal_entity_id', $scope->id)
            ->where('document.status', 'posted')
            ->whereDate('document.entry_date', '<=', $asOf->toDateString())
            ->whereRaw('(document.amount - COALESCE(allocations.allocated_amount, 0)) > 0');

        $this->applyFilters($builder, $query, $definition['party_dimension']);

        $aging = $this->agingExpression($asOf);
        foreach ($query->dimensions as $dimension) {
            [$expression, $bindings] = $this->dimensionExpression(
                $dimension,
                $definition['party_dimension'],
                $aging,
            );
            $builder->selectRaw($expression.' as '.$dimension, $bindings);
        }

        foreach ($query->measures as $measure) {
            $builder->selectRaw(match ($measure) {
                'documents' => 'COUNT(*) as documents',
                'outstanding_amount' => 'SUM(document.amount - COALESCE(allocations.allocated_amount, 0)) as outstanding_amount',
                default => throw ValidationException::withMessages([
                    'measures' => ["Unsupported finance exposure measure [{$measure}]."],
                ]),
            });
        }

        foreach ($query->groupBy as $dimension) {
            [$expression, $bindings] = $this->dimensionGroupExpression(
                $dimension,
                $definition['party_dimension'],
                $aging,
            );
            $builder->groupByRaw($expression, $bindings);
        }

        $this->applySort($builder, $query);

        $rows = $builder
            ->offset(($query->page - 1) * $query->perPage)
            ->limit($query->perPage + 1)
            ->get();
        $hasMore = $rows->count() > $query->perPage;

        return [
            'rows' => $rows
                ->take($query->perPage)
                ->map(fn (\stdClass $row): array => $this->mapRow(
                    $row,
                    $query,
                    $scope->id,
                    $documents,
                    $asOf,
                ))
                ->values()
                ->all(),
            'as_of' => $asOf->toDateString(),
            'page' => $query->page,
            'per_page' => $query->perPage,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param array{read_model:string,documents:string,allocations:string,allocation_fk:string,party_dimension:string} $definition
     */
    private function applyFilters(
        Builder $builder,
        ReportQuery $query,
        string $partyDimension,
    ): void {
        if (array_key_exists('store_id', $query->filters)) {
            $builder->where('document.store_id', $this->positiveId($query->filters['store_id'], 'store_id'));
        }

        if ($partyDimension === 'customer_id' && array_key_exists('customer_id', $query->filters)) {
            $builder->where(
                'document.customer_id',
                $this->positiveId($query->filters['customer_id'], 'customer_id'),
            );
        }

        if ($partyDimension === 'supplier_reference' && array_key_exists('supplier_reference', $query->filters)) {
            $supplier = trim((string) $query->filters['supplier_reference']);
            if ($supplier === '' || mb_strlen($supplier) > 128) {
                throw ValidationException::withMessages([
                    'supplier_reference' => ['Supplier reference must be a non-empty value of at most 128 characters.'],
                ]);
            }
            $builder->where('document.supplier_reference', $supplier);
        }

        if (array_key_exists('currency', $query->filters)) {
            $currency = strtoupper(trim((string) $query->filters['currency']));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw ValidationException::withMessages([
                    'currency' => ['Currency must be a three-letter code.'],
                ]);
            }
            $builder->where('document.currency_code', $currency);
        }
    }

    /**
     * @param array{sql:string,bindings:list<string>} $aging
     * @return array{0:string,1:list<string>}
     */
    private function dimensionExpression(
        string $dimension,
        string $partyDimension,
        array $aging,
    ): array {
        if ($dimension === 'aging_bucket') {
            return [$aging['sql'], $aging['bindings']];
        }

        if ($dimension === 'store_id') {
            return ['document.store_id', []];
        }

        if ($dimension === 'currency') {
            return ['document.currency_code', []];
        }

        if ($dimension === $partyDimension) {
            return ['document.'.$partyDimension, []];
        }

        throw ValidationException::withMessages([
            'dimensions' => ["Unsupported finance exposure dimension [{$dimension}]."],
        ]);
    }

    /**
     * @param array{sql:string,bindings:list<string>} $aging
     * @return array{0:string,1:list<string>}
     */
    private function dimensionGroupExpression(
        string $dimension,
        string $partyDimension,
        array $aging,
    ): array {
        return $this->dimensionExpression($dimension, $partyDimension, $aging);
    }

    private function applySort(Builder $builder, ReportQuery $query): void
    {
        $sorted = [];
        foreach ($query->sort as $sort) {
            $builder->orderBy($sort['field'], $sort['direction']);
            $sorted[$sort['field']] = true;
        }

        foreach ($query->dimensions as $dimension) {
            if (! isset($sorted[$dimension])) {
                $builder->orderBy($dimension);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function mapRow(
        \stdClass $row,
        ReportQuery $query,
        int $legalEntityId,
        string $source,
        CarbonImmutable $asOf,
    ): array {
        $result = [];

        foreach ($query->dimensions as $dimension) {
            $value = $row->{$dimension};
            $result[$dimension] = in_array($dimension, ['customer_id', 'store_id'], true)
                ? (int) $value
                : (string) $value;
        }

        foreach ($query->measures as $measure) {
            $result[$measure] = match ($measure) {
                'documents' => (int) $row->documents,
                'outstanding_amount' => self::money((float) $row->outstanding_amount),
                default => throw ValidationException::withMessages([
                    'measures' => ["Unsupported finance exposure measure [{$measure}]."],
                ]),
            };
        }

        $drillDown = [
            'source' => $source,
            'legal_entity_id' => $legalEntityId,
            'as_of' => $asOf->toDateString(),
        ];
        foreach (['customer_id', 'supplier_reference', 'store_id', 'currency', 'aging_bucket'] as $dimension) {
            if (array_key_exists($dimension, $result)) {
                $drillDown[$dimension] = $result[$dimension];
            }
        }
        $result['drill_down'] = $drillDown;

        return $result;
    }

    /** @return array{sql:string,bindings:list<string>} */
    private function agingExpression(CarbonImmutable $asOf): array
    {
        return [
            'sql' => "CASE
                WHEN document.due_date >= ? THEN 'current'
                WHEN document.due_date >= ? THEN '1_30'
                WHEN document.due_date >= ? THEN '31_60'
                WHEN document.due_date >= ? THEN '61_90'
                ELSE '91_plus'
            END",
            'bindings' => [
                $asOf->toDateString(),
                $asOf->subDays(30)->toDateString(),
                $asOf->subDays(60)->toDateString(),
                $asOf->subDays(90)->toDateString(),
            ],
        ];
    }

    private function asOf(ReportQuery $query): CarbonImmutable
    {
        if ($query->from !== null && $query->to !== null && ! $query->from->equalTo($query->to)) {
            throw ValidationException::withMessages([
                'date_range' => ['Exposure analytics use a single as-of date, not a multi-day range.'],
            ]);
        }

        return $query->to ?? CarbonImmutable::today();
    }

    /** @param list<string> $tables */
    private function assertSources(array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                throw ValidationException::withMessages([
                    'source' => ["Canonical source [{$table}] is unavailable."],
                ]);
            }
        }
    }

    private function source(string $key): string
    {
        $source = config('enterprise_reporting.sources.'.$key);
        if (! is_string($source) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $source) !== 1) {
            throw ValidationException::withMessages([
                'source' => ["Invalid configured analytics source [{$key}]."],
            ]);
        }

        return $source;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = (int) $value;
        if ($id < 1) {
            throw ValidationException::withMessages([
                $field => ['Identifier must be a positive integer.'],
            ]);
        }

        return $id;
    }

    private static function money(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
