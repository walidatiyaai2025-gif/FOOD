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
        'branch_id' => ['BranchID', 'BranchId'],
        'group_id' => ['GroupId', 'GroupID', 'AssetGroupId', 'AssetGroupID'],
        'sub_group_id' => ['SupGroupId', 'SupGroupID', 'SubGroupId', 'SubGroupID', 'AssetSubGroupId', 'AssetSubGroupID'],
        'asset_age' => ['AssetAge', 'Age', 'UsefulLife', 'Life'],
        'asset_doc_value' => ['AssetDocValue', 'DocumentValue', 'DocValue'],
        'dep_complex_value' => ['DepComplexValue', 'DepreciationComplexValue', 'AccumulatedDepreciationValue'],
        'expired_value' => ['ExpiredValue', 'ExpiryValue', 'ResidualValue'],
        'place_id' => ['PlaceId', 'PlaceID', 'LocationId', 'LocationID'],
        'custody_employee_id' => ['CustodyEmpId', 'CustodyEmpID', 'EmployeeId', 'EmployeeID'],
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

        $sourceRows = $this->legacySource()->table($qualified)->orderBy($resolved['id'] ?? $columns[0])->get();
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
        foreach (array_keys(self::FIELD_ALIASES) as $field) {
            $column = $resolved[$field] ?? null;
            $completeness[$field] = [
                'column' => $column,
                'non_null_rows' => $column === null
                    ? 0
                    : (int) $sourceRows->filter(static fn ($row): bool => $row->{$column} !== null)->count(),
            ];
        }

        $distributions = [];
        foreach ([
            'branch_id', 'group_id', 'sub_group_id', 'place_id', 'custody_employee_id',
            'category_id', 'depreciation_id', 'status',
        ] as $field) {
            $column = $resolved[$field] ?? null;
            $distributions[$field] = $this->distinctValues($qualified, $column, 50);
        }

        $numericStats = [];
        foreach (['asset_age', 'asset_doc_value', 'dep_complex_value', 'expired_value'] as $field) {
            $numericStats[$field] = $this->numericStats($sourceRows->all(), $resolved[$field] ?? null);
        }

        $dateStats = [
            'acquisition_date' => $this->dateStats($sourceRows->all(), $resolved['acquisition_date'] ?? null),
            'capitalization_date' => $this->dateStats($sourceRows->all(), $resolved['capitalization_date'] ?? null),
        ];

        $metadata = $this->sourceMetadata();
        $relatedTables = $this->relatedTables($metadata, $source['schema'], $source['table']);
        $relationTables = $this->assetRelationTables($metadata, $source['schema'], $source['table']);

        $lookupCoverage = [
            'branch_id' => $this->lookupCoverage($qualified, $resolved['branch_id'] ?? null, $metadata, ['branch']),
            'group_id' => $this->lookupCoverage($qualified, $resolved['group_id'] ?? null, $metadata, ['group']),
            'sub_group_id' => $this->lookupCoverage($qualified, $resolved['sub_group_id'] ?? null, $metadata, ['group']),
            'place_id' => $this->lookupCoverage($qualified, $resolved['place_id'] ?? null, $metadata, ['place', 'location']),
            'custody_employee_id' => $this->lookupCoverage($qualified, $resolved['custody_employee_id'] ?? null, $metadata, ['employee', 'custody', 'emp']),
            'depreciation_id' => $this->lookupCoverage($qualified, $resolved['depreciation_id'] ?? null, $metadata, ['depreci', 'deprec']),
        ];

        return [
            'source_table' => $qualified,
            'source_table_name' => $source['table'],
            'source_schema' => $source['schema'],
            'source_rows' => $sourceRows->count(),
            'columns' => $columns,
            'resolved_fields' => $resolved,
            'completeness' => $completeness,
            'distributions' => $distributions,
            'numeric_stats' => $numericStats,
            'date_stats' => $dateStats,
            'lookup_coverage' => $lookupCoverage,
            'related_tables' => $relatedTables,
            'asset_relation_tables' => $relationTables,
            'samples' => $samples,
            'native_requirements' => [
                'category' => 'requires source-backed grouping/depreciation semantics plus native account mappings',
                'acquisition' => 'AssetDate is source-backed; acquisition amount must be proven from AssetDocValue or another related source before native write',
                'useful_life' => 'AssetAge must be proven to represent months/years before native write',
                'accounts' => 'asset/accumulated depreciation/depreciation expense/acquisition offset accounts must be proven from related source tables or source-backed category rules',
                'journal_policy' => 'legacy asset master must not fabricate acquisition journals; acquisition_journal_id may stay null only for a historical imported register if all other native semantics are proven',
                'write_policy' => 'profile only until every required native field has an explicit source authority',
            ],
        ];
    }

    private function numericStats(array $rows, ?string $column): array
    {
        if ($column === null) {
            return [
                'column' => null,
                'numeric_rows' => 0,
                'zero_rows' => 0,
                'positive_rows' => 0,
                'negative_rows' => 0,
                'min' => null,
                'max' => null,
                'sum' => null,
                'avg' => null,
                'distinct_values' => 0,
            ];
        }

        $values = [];
        foreach ($rows as $row) {
            $value = $row->{$column} ?? null;
            if ($value === null || $value === '' || ! is_numeric($value)) {
                continue;
            }
            $values[] = (float) $value;
        }

        if ($values === []) {
            return [
                'column' => $column,
                'numeric_rows' => 0,
                'zero_rows' => 0,
                'positive_rows' => 0,
                'negative_rows' => 0,
                'min' => null,
                'max' => null,
                'sum' => null,
                'avg' => null,
                'distinct_values' => 0,
            ];
        }

        $sum = array_sum($values);

        return [
            'column' => $column,
            'numeric_rows' => count($values),
            'zero_rows' => count(array_filter($values, static fn (float $v): bool => abs($v) < 0.0000001)),
            'positive_rows' => count(array_filter($values, static fn (float $v): bool => $v > 0)),
            'negative_rows' => count(array_filter($values, static fn (float $v): bool => $v < 0)),
            'min' => min($values),
            'max' => max($values),
            'sum' => round($sum, 6),
            'avg' => round($sum / count($values), 6),
            'distinct_values' => count(array_unique(array_map(static fn (float $v): string => sprintf('%.6F', $v), $values))),
        ];
    }

    private function dateStats(array $rows, ?string $column): array
    {
        if ($column === null) {
            return [
                'column' => null,
                'non_null_rows' => 0,
                'yyyyMMdd_rows' => 0,
                'valid_date_rows' => 0,
                'invalid_samples' => [],
                'min_date' => null,
                'max_date' => null,
            ];
        }

        $nonNull = 0;
        $shape = 0;
        $valid = [];
        $invalid = [];

        foreach ($rows as $row) {
            $raw = trim((string) ($row->{$column} ?? ''));
            if ($raw === '') {
                continue;
            }
            $nonNull++;
            if (preg_match('/^\d{8}$/', $raw) === 1) {
                $shape++;
                $year = (int) substr($raw, 0, 4);
                $month = (int) substr($raw, 4, 2);
                $day = (int) substr($raw, 6, 2);
                if (checkdate($month, $day, $year)) {
                    $valid[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    continue;
                }
            }

            if (count($invalid) < 20) {
                $invalid[] = $raw;
            }
        }

        sort($valid);

        return [
            'column' => $column,
            'non_null_rows' => $nonNull,
            'yyyyMMdd_rows' => $shape,
            'valid_date_rows' => count($valid),
            'invalid_samples' => $invalid,
            'min_date' => $valid[0] ?? null,
            'max_date' => $valid === [] ? null : $valid[count($valid) - 1],
        ];
    }

    private function relatedTables(array $metadata, string $sourceSchema, string $sourceTable): array
    {
        $results = [];

        foreach ($metadata as $definition) {
            if ($definition['schema'] === $sourceSchema && $definition['table'] === $sourceTable) {
                continue;
            }

            $haystack = strtolower($definition['table'].' '.implode(' ', $definition['columns']));
            if (
                ! str_contains($haystack, 'asset')
                && ! str_contains($haystack, 'depreci')
                && ! str_contains($haystack, 'deprec')
                && ! str_contains($haystack, 'custody')
                && ! str_contains($haystack, 'place')
            ) {
                continue;
            }

            $qualified = $definition['schema'].'.'.$definition['table'];
            $rowCount = (int) $this->legacySource()->table($qualified)->count();
            if ($rowCount <= 0) {
                continue;
            }

            $results[] = [
                'table' => $qualified,
                'rows' => $rowCount,
                'columns' => $definition['columns'],
            ];

            if (count($results) >= 80) {
                break;
            }
        }

        return $results;
    }

    private function assetRelationTables(array $metadata, string $sourceSchema, string $sourceTable): array
    {
        $results = [];

        foreach ($metadata as $definition) {
            if ($definition['schema'] === $sourceSchema && $definition['table'] === $sourceTable) {
                continue;
            }

            $assetColumns = array_values(array_filter(
                $definition['columns'],
                fn (string $column): bool => in_array(
                    $this->normalize($column),
                    ['assetid', 'asset_id', 'fixedassetid'],
                    true,
                ) || str_contains($this->normalize($column), 'assetid')
            ));

            if ($assetColumns === []) {
                continue;
            }

            $accountColumns = array_values(array_filter(
                $definition['columns'],
                fn (string $column): bool => str_contains($this->normalize($column), 'accountid')
            ));
            $depreciationColumns = array_values(array_filter(
                $definition['columns'],
                fn (string $column): bool => str_contains($this->normalize($column), 'depreci')
                    || str_contains($this->normalize($column), 'deprec')
            ));
            $valueColumns = array_values(array_filter(
                $definition['columns'],
                fn (string $column): bool => str_contains($this->normalize($column), 'value')
                    || str_contains($this->normalize($column), 'amount')
                    || str_contains($this->normalize($column), 'cost')
            ));

            $qualified = $definition['schema'].'.'.$definition['table'];
            $rowCount = (int) $this->legacySource()->table($qualified)->count();
            if ($rowCount <= 0) {
                continue;
            }

            $sampleColumns = array_values(array_unique(array_merge(
                $assetColumns,
                $accountColumns,
                $depreciationColumns,
                $valueColumns,
            )));
            $sampleColumns = array_slice($sampleColumns, 0, 12);

            $samples = $sampleColumns === []
                ? []
                : $this->legacySource()->table($qualified)
                    ->select($sampleColumns)
                    ->limit(10)
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->all();

            $results[] = [
                'table' => $qualified,
                'rows' => $rowCount,
                'asset_columns' => $assetColumns,
                'account_columns' => $accountColumns,
                'depreciation_columns' => $depreciationColumns,
                'value_columns' => $valueColumns,
                'samples' => $samples,
            ];
        }

        return $results;
    }

    private function lookupCoverage(
        string $sourceTable,
        ?string $sourceColumn,
        array $metadata,
        array $keywords,
    ): array {
        if ($sourceColumn === null) {
            return [
                'source_column' => null,
                'distinct_nonzero_source_ids' => 0,
                'candidate_tables' => [],
            ];
        }

        $sourceIds = $this->legacySource()->table($sourceTable)
            ->whereNotNull($sourceColumn)
            ->pluck($sourceColumn)
            ->map(static fn ($value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();

        $candidates = [];

        foreach ($metadata as $definition) {
            $normalizedTable = $this->normalize($definition['table']);
            $keywordMatch = false;
            foreach ($keywords as $keyword) {
                if (str_contains($normalizedTable, $this->normalize($keyword))) {
                    $keywordMatch = true;
                    break;
                }
            }
            if (! $keywordMatch) {
                continue;
            }

            $idColumn = $this->matchColumn($definition['columns'], [
                'ID', 'Id',
                'GroupId', 'GroupID',
                'PlaceId', 'PlaceID',
                'EmployeeId', 'EmployeeID', 'EmpId', 'EmpID',
                'BranchId', 'BranchID',
                'DepreciationId', 'DepreciationID',
            ]);
            $labelColumn = $this->matchColumn($definition['columns'], [
                'Name_En', 'Name_EN', 'Name_Ar', 'Name_AR', 'Name',
                'Description', 'Title', 'Code',
            ]);

            if ($idColumn === null) {
                continue;
            }

            $qualified = $definition['schema'].'.'.$definition['table'];
            $rowCount = (int) $this->legacySource()->table($qualified)->count();
            if ($rowCount <= 0) {
                continue;
            }

            $matchedRows = $sourceIds === []
                ? collect()
                : $this->legacySource()->table($qualified)
                    ->whereIn($idColumn, $sourceIds)
                    ->select(array_values(array_filter([$idColumn, $labelColumn])))
                    ->limit(100)
                    ->get();

            $matchedIds = $matchedRows
                ->map(static fn ($row) => (int) ($row->{$idColumn} ?? 0))
                ->filter(static fn (int $value): bool => $value > 0)
                ->unique()
                ->values()
                ->all();

            $candidates[] = [
                'table' => $qualified,
                'rows' => $rowCount,
                'id_column' => $idColumn,
                'label_column' => $labelColumn,
                'matched_source_ids' => count($matchedIds),
                'source_id_coverage_percent' => $sourceIds === []
                    ? 0
                    : round((count($matchedIds) / count($sourceIds)) * 100, 2),
                'samples' => $matchedRows->take(20)->map(static fn ($row): array => (array) $row)->all(),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int =>
            $b['matched_source_ids'] <=> $a['matched_source_ids']
        );

        return [
            'source_column' => $sourceColumn,
            'distinct_nonzero_source_ids' => count($sourceIds),
            'source_ids' => array_slice($sourceIds, 0, 100),
            'candidate_tables' => array_slice($candidates, 0, 25),
        ];
    }

    private function sourceMetadata(): array
    {
        $rows = $this->legacySource()->select(
            "SELECT s.name AS schema_name, t.name AS table_name, c.name AS column_name, c.column_id
             FROM sys.tables t
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             INNER JOIN sys.columns c ON c.object_id = t.object_id
             WHERE t.is_ms_shipped = 0
             ORDER BY s.name, t.name, c.column_id"
        );

        $tables = [];
        foreach ($rows as $row) {
            $key = (string) $row->schema_name.'.'.(string) $row->table_name;
            if (! isset($tables[$key])) {
                $tables[$key] = [
                    'schema' => (string) $row->schema_name,
                    'table' => (string) $row->table_name,
                    'columns' => [],
                ];
            }
            $tables[$key]['columns'][] = (string) $row->column_name;
        }

        return array_values($tables);
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
            $needle = $this->normalize($alias);
            foreach ($rows as $row) {
                $actual = $this->normalize((string) $row->table_name);
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
            $map[$this->normalize((string) $column)] = (string) $column;
        }

        foreach ($aliases as $alias) {
            $key = $this->normalize($alias);
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

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }

    private function legacySource(): ConnectionInterface
    {
        return DB::connection('foodex_legacy');
    }
}
