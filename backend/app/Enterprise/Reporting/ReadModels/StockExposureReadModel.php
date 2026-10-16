<?php

namespace App\Enterprise\Reporting\ReadModels;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class StockExposureReadModel
{
    private const INBOUND_MOVEMENT_TYPES = [
        'purchase_receipt',
        'warehouse_transfer_in',
        'cycle_count_adjustment',
        'adjustment',
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
        if ($query->report->code !== 'wms.stock_exposure'
            || $query->report->readModel !== 'wms_stock_exposure') {
            throw ValidationException::withMessages([
                'report' => ['Stock exposure read model requires the wms.stock_exposure report definition.'],
            ]);
        }

        if ($scope->type !== EntitlementScope::STORE || $scope->id === null) {
            throw ValidationException::withMessages([
                'scope' => ['Stock exposure analytics require an explicit store scope.'],
            ]);
        }

        $asOf = $this->asOf($query);
        if ($asOf->isSameDay(CarbonImmutable::today()) === false) {
            throw ValidationException::withMessages([
                'date_range' => ['Historical reserved-stock snapshots are not authoritative; stock exposure supports the current as-of date only.'],
            ]);
        }

        $inventories = $this->source('inventories');
        $warehouses = $this->source('warehouses');
        $valuation = $this->source('valuation_policies');
        $movements = $this->source('stock_movements');
        $this->assertSources([$inventories, $warehouses, $valuation, $movements]);

        $inbound = DB::table($movements.' as inbound_movement')
            ->join(
                $inventories.' as inbound_inventory',
                'inbound_inventory.id',
                '=',
                'inbound_movement.inventory_id',
            )
            ->where('inbound_movement.store_id', $scope->id)
            ->where('inbound_movement.quantity', '>', 0)
            ->whereIn('inbound_movement.type', self::INBOUND_MOVEMENT_TYPES)
            ->selectRaw('inbound_movement.inventory_id, MAX(inbound_movement.created_at) as last_inbound_at')
            ->groupBy('inbound_movement.inventory_id');

        $builder = DB::table($inventories.' as inventory')
            ->join($warehouses.' as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->leftJoin($valuation.' as valuation', function ($join): void {
                $join
                    ->on('valuation.warehouse_id', '=', 'inventory.warehouse_id')
                    ->on('valuation.product_id', '=', 'inventory.product_id')
                    ->where('valuation.is_active', true);
            })
            ->leftJoinSub($inbound, 'inbound', 'inbound.inventory_id', '=', 'inventory.id')
            ->where('warehouse.store_id', $scope->id);

        $aging = $this->agingExpression($asOf);
        $status = $this->statusExpression($asOf);
        $this->applyFilters($builder, $query, $status);

        foreach ($query->dimensions as $dimension) {
            [$expression, $bindings] = $this->dimensionExpression($dimension, $aging, $status);
            $builder->selectRaw($expression.' as '.$dimension, $bindings);
        }

        foreach ($query->measures as $measure) {
            $builder->selectRaw(match ($measure) {
                'inventory_rows' => 'COUNT(*) as inventory_rows',
                'on_hand' => 'COALESCE(SUM(inventory.quantity), 0) as on_hand',
                'reserved' => 'COALESCE(SUM(inventory.reserved_quantity), 0) as reserved',
                'available' => 'COALESCE(SUM(inventory.quantity - inventory.reserved_quantity), 0) as available',
                'stock_value' => 'COALESCE(SUM(CASE WHEN valuation.unit_cost IS NULL THEN 0 ELSE inventory.quantity * valuation.unit_cost END), 0) as stock_value',
                'unvalued_items' => 'SUM(CASE WHEN valuation.unit_cost IS NULL THEN 1 ELSE 0 END) as unvalued_items',
                default => throw ValidationException::withMessages([
                    'measures' => ["Unsupported stock exposure measure [{$measure}]."],
                ]),
            });
        }

        foreach ($query->groupBy as $dimension) {
            [$expression, $bindings] = $this->dimensionExpression($dimension, $aging, $status);
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
                    $inventories,
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
     * @param  array{sql:string,bindings:list<string>}  $status
     */
    private function applyFilters(Builder $builder, ReportQuery $query, array $status): void
    {
        if (array_key_exists('warehouse_id', $query->filters)) {
            $builder->where(
                'inventory.warehouse_id',
                $this->positiveId($query->filters['warehouse_id'], 'warehouse_id'),
            );
        }

        if (array_key_exists('product_id', $query->filters)) {
            $builder->where(
                'inventory.product_id',
                $this->positiveId($query->filters['product_id'], 'product_id'),
            );
        }

        if (array_key_exists('stock_status', $query->filters)) {
            $value = strtolower(trim((string) $query->filters['stock_status']));
            if (in_array($value, ['active', 'slow', 'dead'], true) === false) {
                throw ValidationException::withMessages([
                    'stock_status' => ['Stock status must be active, slow or dead.'],
                ]);
            }
            $builder->whereRaw(
                $status['sql'].' = ?',
                [...$status['bindings'], $value],
            );
        }
    }

    /**
     * @param  array{sql:string,bindings:list<string>}  $aging
     * @param  array{sql:string,bindings:list<string>}  $status
     * @return array{0:string,1:list<string>}
     */
    private function dimensionExpression(
        string $dimension,
        array $aging,
        array $status,
    ): array {
        return match ($dimension) {
            'warehouse_id' => ['inventory.warehouse_id', []],
            'product_id' => ['inventory.product_id', []],
            'aging_bucket' => [$aging['sql'], $aging['bindings']],
            'stock_status' => [$status['sql'], $status['bindings']],
            default => throw ValidationException::withMessages([
                'dimensions' => ["Unsupported stock exposure dimension [{$dimension}]."],
            ]),
        };
    }

    private function applySort(Builder $builder, ReportQuery $query): void
    {
        $sorted = [];
        foreach ($query->sort as $sort) {
            $builder->orderBy($sort['field'], $sort['direction']);
            $sorted[$sort['field']] = true;
        }

        foreach ($query->dimensions as $dimension) {
            if (isset($sorted[$dimension]) === false) {
                $builder->orderBy($dimension);
            }
        }
    }

    /** @return array<string,mixed> */
    private function mapRow(
        \stdClass $row,
        ReportQuery $query,
        int $storeId,
        string $source,
        CarbonImmutable $asOf,
    ): array {
        $result = [];

        foreach ($query->dimensions as $dimension) {
            $value = $row->{$dimension};
            $result[$dimension] = in_array($dimension, ['warehouse_id', 'product_id'], true)
                ? (int) $value
                : (string) $value;
        }

        foreach ($query->measures as $measure) {
            $result[$measure] = match ($measure) {
                'inventory_rows', 'unvalued_items' => (int) $row->{$measure},
                'on_hand', 'reserved', 'available', 'stock_value' => self::quantity((float) $row->{$measure}),
                default => throw ValidationException::withMessages([
                    'measures' => ["Unsupported stock exposure measure [{$measure}]."],
                ]),
            };
        }

        $drillDown = [
            'source' => $source,
            'store_id' => $storeId,
            'as_of' => $asOf->toDateString(),
        ];
        foreach (['warehouse_id', 'product_id', 'aging_bucket', 'stock_status'] as $dimension) {
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
        $source = 'COALESCE(inbound.last_inbound_at, inventory.created_at)';

        return [
            'sql' => "CASE
                WHEN {$source} >= ? THEN '0_30'
                WHEN {$source} >= ? THEN '31_60'
                WHEN {$source} >= ? THEN '61_90'
                ELSE '91_plus'
            END",
            'bindings' => [
                $asOf->subDays(30)->startOfDay()->toDateTimeString(),
                $asOf->subDays(60)->startOfDay()->toDateTimeString(),
                $asOf->subDays(90)->startOfDay()->toDateTimeString(),
            ],
        ];
    }

    /** @return array{sql:string,bindings:list<string>} */
    private function statusExpression(CarbonImmutable $asOf): array
    {
        $source = 'COALESCE(inbound.last_inbound_at, inventory.created_at)';

        return [
            'sql' => "CASE
                WHEN {$source} >= ? THEN 'active'
                WHEN {$source} >= ? THEN 'slow'
                ELSE 'dead'
            END",
            'bindings' => [
                $asOf->subDays(90)->startOfDay()->toDateTimeString(),
                $asOf->subDays(180)->startOfDay()->toDateTimeString(),
            ],
        ];
    }

    private function asOf(ReportQuery $query): CarbonImmutable
    {
        if ($query->from !== null && $query->to !== null && $query->from->equalTo($query->to) === false) {
            throw ValidationException::withMessages([
                'date_range' => ['Stock exposure uses a single as-of date, not a multi-day range.'],
            ]);
        }

        return $query->to ?? CarbonImmutable::today();
    }

    /** @param  list<string>  $tables */
    private function assertSources(array $tables): void
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table) === false) {
                throw ValidationException::withMessages([
                    'source' => ["Canonical source [{$table}] is unavailable."],
                ]);
            }
        }
    }

    private function source(string $key): string
    {
        $source = config('enterprise_reporting.sources.'.$key);
        if (is_string($source) === false || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $source) !== 1) {
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

    private static function quantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
