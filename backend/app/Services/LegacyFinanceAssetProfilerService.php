<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LegacyFinanceAssetProfilerService
{
    private const SOURCE_ALIASES = ['gl_Assets', 'gl_Asset', 'Assets', 'FixedAssets'];

    private const FIELD_ALIASES = [
        'id' => ['ID', 'Id', 'AssetID', 'AssetId'],
        'code' => ['Code', 'AssetCode', 'AssetNo', 'AssetNumber', 'No'],
        'name' => ['Name_En', 'Name_EN', 'Name', 'AssetName', 'Description'],
        'name_ar' => ['Name_AR', 'Name_Ar', 'ArabicName', 'NameArabic'],
        'category_id' => ['AssetCategoryID', 'AssetCategoryId', 'CategoryID', 'CategoryId', 'AssetTypeID', 'AssetTypeId', 'TypeID', 'TypeId'],
        'depreciation_id' => ['Depreciationid', 'DepreciationID', 'DepreciationId', 'DepMethodID', 'DepMethodId'],
        'acquisition_date' => ['AcquisitionDate', 'PurchaseDate', 'BuyDate', 'AssetDate', 'StartDate'],
        'capitalization_date' => ['CapitalizationDate', 'DepreciationStartDate', 'StartDepreciationDate', 'ServiceDate'],
        'acquisition_amount' => ['AcquisitionAmount', 'PurchaseValue', 'PurchaseAmount', 'OriginalCost', 'AssetValue', 'Cost', 'Amount'],
        'salvage_value' => ['SalvageValue', 'ResidualValue', 'ScrapValue'],
        'useful_life_months' => ['UsefulLifeMonths', 'LifeMonths', 'DepreciationMonths'],
        'useful_life_years' => ['UsefulLifeYears', 'LifeYears', 'DepreciationYears'],
        'depreciation_rate' => ['DepreciationRate', 'AnnualRate', 'Rate', 'DepRate'],
        'asset_account_id' => ['AssetAccountID', 'AssetAccountId', 'AccountID', 'AccountId'],
        'accumulated_depreciation_account_id' => ['AccumulatedDepreciationAccountID', 'AccumulatedDepreciationAccountId', 'AccumDepAccountID', 'AccumDepAccountId'],
        'depreciation_expense_account_id' => ['DepreciationExpenseAccountID', 'DepreciationExpenseAccountId', 'DepExpenseAccountID', 'DepExpenseAccountId'],
        'offset_account_id' => ['OffsetAccountID', 'OffsetAccountId', 'AcquisitionOffsetAccountID', 'AcquisitionOffsetAccountId', 'SupplierAccountID', 'SupplierAccountId'],
        'cost_center_id' => ['CostCenterID', 'CostCenterId', 'CenterID', 'CenterId'],
        'status' => ['Status', 'AssetStatus', 'IsActive', 'Active', 'Inactive', 'IsInactive'],
    ];

    public function profile(): array
    {
        $source = $this->resolveSourceTable();
        $columns = $this->sourceColumns($source['schema'], $source['table']);
        $qualified = $source['schema'].'.'.$source['table'];
        $resolved = [];

        foreach (self::FIELD_ALIASES as $field => $aliases) {
            $resolved[$field] = $this->matchColumn($columns, $aliases);
        }

        $select = array_values(array_unique(array_filter($resolved)));
        $samples = $select === []
            ? []
            : $this->legacySource()->table($qualified)
                ->select($select)
                ->orderBy($resolved['id'] ?? $select[0])
                ->limit(20)
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();

        $completeness = [];
        foreach ([
            'id', 'code', 'name', 'category_id', 'depreciation_id',
            'acquisition_date', 'capitalization_date', 'acquisition_amount',
            'salvage_value', 'useful_life_months', 'useful_life_years',
            'depreciation_rate', 'asset_account_id',
            'accumulated_depreciation_account_id',
            'depreciation_expense_account_id', 'offset_account_id',
            'cost_center_id', 'status',
        ] as $field) {
            $column = $resolved[$field] ?? null;
            $completeness[$field] = [
                'column' => $column,
                'non_null_rows' => $column === null
                    ? 0
                    : (int) $this->legacySource()->table($qualified)->whereNotNull($column)->count(),
            ];
        }

        $distributions = [];
        foreach (['category_id', 'depreciation_id', 'status'] as $field) {
            $column = $resolved[$field] ?? null;
            $distributions[$field] = $this->distinctValues($qualified, $column, 30);
        }

        return [
            'source_table' => $qualified,
            'source_table_name' => $source['table'],
            'source_schema' => $source['schema'],
            'source_rows' => (int) $this->legacySource()->table($qualified)->count(),
            'columns' => $columns,
            'resolved_fields' => $resolved,
            'completeness' => $completeness,
            'distributions' => $distributions,
            'samples' => $samples,
            'native_requirements' => [
                'category' => 'requires explicit source category/depreciation semantics and mapped finance accounts',
                'acquisition' => 'requires date + positive amount',
                'useful_life' => 'requires useful-life months/years or an explicit category default',
                'accounts' => 'requires asset, accumulated depreciation, depreciation expense, and acquisition offset account semantics',
                'journal_policy' => 'legacy asset master must not fabricate acquisition journals; historical journal linkage must be proven separately',
            ],
        ];
    }

    private function resolveSourceTable(): array
    {
        $rows = $this->legacySource()->select(
            "SELECT s.name AS schema_name, t.name AS table_name
             FROM sys.tables t
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE t.is_ms_shipped = 0"
        );

        foreach (self::SOURCE_ALIASES as $alias) {
            $needle = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $alias));
            foreach ($rows as $row) {
                $actual = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $row->table_name));
                if ($actual !== $needle) {
                    continue;
                }

                $qualified = (string) $row->schema_name.'.'.(string) $row->table_name;
                if ((int) $this->legacySource()->table($qualified)->count() > 0) {
                    return [
                        'schema' => (string) $row->schema_name,
                        'table' => (string) $row->table_name,
                    ];
                }
            }
        }

        throw new RuntimeException('No non-empty legacy fixed-asset master table could be resolved.');
    }

    private function sourceColumns(string $schema, string $table): array
    {
        return array_map(
            static fn ($row): string => (string) $row->column_name,
            $this->legacySource()->select(
                "SELECT c.name AS column_name
                 FROM sys.tables t
                 INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
                 INNER JOIN sys.columns c ON c.object_id = t.object_id
                 WHERE s.name = ? AND t.name = ?
                 ORDER BY c.column_id",
                [$schema, $table],
            ),
        );
    }

    private function matchColumn(array $columns, array $aliases): ?string
    {
        $map = [];
        foreach ($columns as $column) {
            $map[strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $column))] = (string) $column;
        }

        foreach ($aliases as $alias) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $alias));
            if (isset($map[$key])) {
                return $map[$key];
            }
        }

        return null;
    }

    private function distinctValues(string $qualified, ?string $column, int $limit): array
    {
        if ($column === null) {
            return [];
        }

        return $this->legacySource()->table($qualified)
            ->selectRaw("{$column} as value, COUNT(*) as row_count")
            ->groupBy($column)
            ->orderByDesc('row_count')
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => [
                'value' => $row->value,
                'count' => (int) $row->row_count,
            ])
            ->all();
    }

    private function legacySource(): ConnectionInterface
    {
        return DB::connection('foodex_legacy');
    }
}
