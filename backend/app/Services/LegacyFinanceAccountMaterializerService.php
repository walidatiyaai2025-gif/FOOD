<?php

namespace App\Services;

use App\Enterprise\Organization\OrganizationHierarchyService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyFinanceAccountMaterializerService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    private const SOURCE_TABLE_ALIASES = [
        'gl_Accounts',
        'gl_Account',
        'GLAccounts',
        'Accounts',
    ];

    private const ID_ALIASES = ['ID', 'Id', 'AccountID', 'AccountId', 'AccID', 'AccId'];
    private const CODE_ALIASES = ['Code', 'AccountCode', 'AccCode', 'AccountNo', 'AccountNumber', 'No'];
    private const NAME_ALIASES = ['Name', 'AccountName', 'Name_En', 'Name_Ar', 'Caption', 'Description'];
    private const PARENT_ALIASES = ['ParentID', 'ParentId', 'ParentAccountID', 'ParentAccountId', 'ParentAccID', 'ParentAccId', 'MainAccountID', 'MainAccountId'];
    private const TYPE_ALIASES = ['AccountType', 'AccountTypeName', 'Type', 'TypeName', 'AccountNature', 'Nature', 'NatureName', 'AccountClass', 'Classification'];
    private const NORMAL_BALANCE_ALIASES = ['NormalBalance', 'BalanceNature', 'BalanceType', 'DebitCredit', 'DrCr', 'DC', 'DebitOrCredit'];
    private const STATUS_ALIASES = ['Status', 'AccountStatus', 'IsActive', 'Active', 'Inactive', 'IsInactive'];

    public function profile(): array
    {
        $sourceTable = $this->resolveSourceTable();
        $columns = $this->sourceColumns($sourceTable['schema'], $sourceTable['table']);

        $idColumn = $this->matchColumn($columns, self::ID_ALIASES);
        $codeColumn = $this->matchColumn($columns, self::CODE_ALIASES);
        $nameColumn = $this->matchColumn($columns, self::NAME_ALIASES);
        $parentColumn = $this->matchColumn($columns, self::PARENT_ALIASES);
        $typeColumn = $this->matchColumn($columns, self::TYPE_ALIASES);
        $normalBalanceColumn = $this->matchColumn($columns, self::NORMAL_BALANCE_ALIASES);
        $statusColumn = $this->matchColumn($columns, self::STATUS_ALIASES);

        $qualified = $sourceTable['schema'].'.'.$sourceTable['table'];
        $sourceRows = (int) $this->legacySource()->table($qualified)->count();

        $typeLookup = $this->typeLookup();
        $typeValues = $this->distinctValues($qualified, $typeColumn, 50);
        $normalBalanceValues = $this->distinctValues($qualified, $normalBalanceColumn, 50);

        $requiredResolved = $idColumn !== null
            && $codeColumn !== null
            && $nameColumn !== null
            && $typeColumn !== null;

        $classification = $requiredResolved
            ? $this->classifyRows(
                $qualified,
                $idColumn,
                $codeColumn,
                $nameColumn,
                $parentColumn,
                $typeColumn,
                $normalBalanceColumn,
                $statusColumn,
                $typeLookup,
                false,
            )
            : [
                'rows' => [],
                'unclassified_type_rows' => $sourceRows,
                'unclassified_type_samples' => [],
                'missing_code_rows' => 0,
                'missing_name_rows' => 0,
                'duplicate_source_ids' => 0,
            ];

        return [
            'source_table' => $qualified,
            'source_rows' => $sourceRows,
            'columns' => $columns,
            'resolved_columns' => [
                'id' => $idColumn,
                'code' => $codeColumn,
                'name' => $nameColumn,
                'parent' => $parentColumn,
                'type' => $typeColumn,
                'normal_balance' => $normalBalanceColumn,
                'status' => $statusColumn,
            ],
            'type_lookup' => $typeLookup['meta'],
            'type_values' => $typeValues,
            'normal_balance_values' => $normalBalanceValues,
            'classification' => [
                'unclassified_type_rows' => $classification['unclassified_type_rows'],
                'unclassified_type_samples' => $classification['unclassified_type_samples'],
                'missing_code_rows' => $classification['missing_code_rows'],
                'missing_name_rows' => $classification['missing_name_rows'],
                'duplicate_source_ids' => $classification['duplicate_source_ids'],
            ],
            'ready' => $requiredResolved
                && $sourceRows > 0
                && $classification['unclassified_type_rows'] === 0
                && $classification['missing_code_rows'] === 0
                && $classification['missing_name_rows'] === 0
                && $classification['duplicate_source_ids'] === 0,
        ];
    }

    public function materialize(bool $apply): array
    {
        $this->assertTargetTables();
        $profile = $this->profile();

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'profile' => $profile,
                'existing_mappings' => $this->mappingCount(),
            ];
        }

        if (! $profile['ready']) {
            throw new RuntimeException(
                'Finance account apply refused: source chart-of-accounts semantics are not fully resolved. '.
                json_encode([
                    'source_table' => $profile['source_table'],
                    'resolved_columns' => $profile['resolved_columns'],
                    'type_lookup' => $profile['type_lookup'],
                    'type_values' => $profile['type_values'],
                    'normal_balance_values' => $profile['normal_balance_values'],
                    'classification' => $profile['classification'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        [$organizationId, $companyId, $legalEntityId] = $this->scope();

        $columns = $profile['resolved_columns'];
        $typeLookup = $this->typeLookup();
        $classification = $this->classifyRows(
            $profile['source_table'],
            (string) $columns['id'],
            (string) $columns['code'],
            (string) $columns['name'],
            $columns['parent'],
            (string) $columns['type'],
            $columns['normal_balance'],
            $columns['status'],
            $typeLookup,
            true,
        );
        $rows = $classification['rows'];

        DB::transaction(function () use (
            $rows,
            $organizationId,
            $companyId,
            $legalEntityId,
            $profile,
        ): void {
            $targetBySource = [];
            $activeSourceKeys = [];

            foreach ($rows as $row) {
                $sourceKey = (string) $row['source_id'];
                $activeSourceKeys[] = $sourceKey;

                $mappedId = DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', $profile['source_table'])
                    ->where('source_key', $sourceKey)
                    ->where('target_table', 'enterprise_finance_accounts')
                    ->value('target_id');
                $targetId = $mappedId === null ? null : (int) $mappedId;

                $desiredCode = mb_substr((string) $row['source_code'], 0, 64);
                if ($targetId === null || ! DB::table('enterprise_finance_accounts')->where('id', $targetId)->exists()) {
                    $conflict = DB::table('enterprise_finance_accounts')
                        ->where('legal_entity_id', $legalEntityId)
                        ->where('code', $desiredCode)
                        ->value('id');

                    if ($conflict !== null) {
                        $desiredCode = mb_substr('FDX-GL-'.$row['source_id'].'-'.$row['source_code'], 0, 64);
                    }
                }

                $payload = [
                    'organization_id' => $organizationId,
                    'company_id' => $companyId,
                    'legal_entity_id' => $legalEntityId,
                    'parent_id' => null,
                    'code' => $desiredCode,
                    'name' => mb_substr((string) $row['name'], 0, 190),
                    'type' => (string) $row['type'],
                    'normal_balance' => (string) $row['normal_balance'],
                    'is_control' => (bool) $row['is_control'],
                    'allow_posting' => ! (bool) $row['is_control'],
                    'status' => (string) $row['status'],
                    'created_by' => null,
                    'updated_by' => null,
                    'updated_at' => now(),
                ];

                if ($targetId !== null && DB::table('enterprise_finance_accounts')->where('id', $targetId)->exists()) {
                    DB::table('enterprise_finance_accounts')->where('id', $targetId)->update($payload);
                } else {
                    $payload['created_at'] = now();
                    $targetId = (int) DB::table('enterprise_finance_accounts')->insertGetId($payload);
                }

                $targetBySource[(int) $row['source_id']] = $targetId;

                DB::table('legacy_import_mappings')->updateOrInsert(
                    [
                        'source_system' => self::SOURCE_SYSTEM,
                        'source_table' => $profile['source_table'],
                        'source_key' => $sourceKey,
                        'target_table' => 'enterprise_finance_accounts',
                    ],
                    [
                        'target_id' => $targetId,
                        'source_hash' => hash('sha256', json_encode($row['raw'], JSON_THROW_ON_ERROR)),
                        'metadata' => json_encode([
                            'authority' => 'legacy-chart-of-accounts',
                            'source_code' => $row['source_code'],
                            'source_parent_id' => $row['source_parent_id'],
                            'source_type_raw' => $row['source_type_raw'],
                            'resolved_type' => $row['type'],
                            'resolved_normal_balance' => $row['normal_balance'],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'last_run_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            foreach ($rows as $row) {
                $targetId = $targetBySource[(int) $row['source_id']];
                $sourceParentId = $row['source_parent_id'];
                $parentTargetId = $sourceParentId === null
                    ? null
                    : ($targetBySource[(int) $sourceParentId] ?? null);

                if ($sourceParentId !== null && $parentTargetId === null) {
                    throw new RuntimeException(
                        'Finance account parent is missing from source materialization: child='.
                        $row['source_id'].' parent='.$sourceParentId
                    );
                }

                DB::table('enterprise_finance_accounts')
                    ->where('id', $targetId)
                    ->update([
                        'parent_id' => $parentTargetId,
                        'updated_at' => now(),
                    ]);
            }

            $staleMappings = DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', $profile['source_table'])
                ->where('target_table', 'enterprise_finance_accounts')
                ->when(
                    $activeSourceKeys !== [],
                    fn ($query) => $query->whereNotIn('source_key', $activeSourceKeys),
                )
                ->get(['id', 'target_id']);

            foreach ($staleMappings as $mapping) {
                DB::table('enterprise_finance_accounts')
                    ->where('id', (int) $mapping->target_id)
                    ->update(['status' => 'inactive', 'updated_at' => now()]);
                DB::table('legacy_import_mappings')->where('id', $mapping->id)->delete();
            }

            if (Schema::hasTable('legacy_business_records')) {
                DB::table('legacy_business_records')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', $profile['source_table'])
                    ->delete();
            }

            if (Schema::hasTable('legacy_business_table_manifest')) {
                DB::table('legacy_business_table_manifest')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', $profile['source_table'])
                    ->delete();
            }
        }, 3);

        return [
            'mode' => 'apply',
            ...$this->verify(),
        ];
    }

    public function verify(): array
    {
        $profile = $this->profile();
        $sourceRows = (int) $profile['source_rows'];
        $mappingCount = $this->mappingCount();

        $orphanMappings = (int) DB::table('legacy_import_mappings as mapping')
            ->leftJoin('enterprise_finance_accounts as account', 'account.id', '=', 'mapping.target_id')
            ->where('mapping.source_system', self::SOURCE_SYSTEM)
            ->where('mapping.source_table', $profile['source_table'])
            ->where('mapping.target_table', 'enterprise_finance_accounts')
            ->whereNull('account.id')
            ->count();

        $duplicateTargetIds = (int) DB::query()
            ->fromSub(
                DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', $profile['source_table'])
                    ->where('target_table', 'enterprise_finance_accounts')
                    ->select('target_id')
                    ->groupBy('target_id')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_targets',
            )
            ->count();

        $unmappedParents = (int) DB::table('legacy_import_mappings as child_map')
            ->join('enterprise_finance_accounts as child', 'child.id', '=', 'child_map.target_id')
            ->where('child_map.source_system', self::SOURCE_SYSTEM)
            ->where('child_map.source_table', $profile['source_table'])
            ->where('child_map.target_table', 'enterprise_finance_accounts')
            ->whereNotNull('child.parent_id')
            ->whereNotExists(function ($query) use ($profile): void {
                $query->selectRaw('1')
                    ->from('legacy_import_mappings as parent_map')
                    ->whereColumn('parent_map.target_id', 'child.parent_id')
                    ->where('parent_map.source_system', self::SOURCE_SYSTEM)
                    ->where('parent_map.source_table', $profile['source_table'])
                    ->where('parent_map.target_table', 'enterprise_finance_accounts');
            })
            ->count();

        $healthy = $profile['ready']
            && $mappingCount === $sourceRows
            && $orphanMappings === 0
            && $duplicateTargetIds === 0
            && $unmappedParents === 0;

        return [
            'healthy' => $healthy,
            'profile' => $profile,
            'target' => [
                'account_mappings' => $mappingCount,
                'orphan_mappings' => $orphanMappings,
                'duplicate_target_ids' => $duplicateTargetIds,
                'unmapped_parent_accounts' => $unmappedParents,
            ],
            'rule' => [
                'type_values' => ['asset', 'liability', 'equity', 'revenue', 'expense'],
                'normal_balance' => 'explicit source value when recognized; otherwise derived only from resolved account type',
                'control_account' => 'derived structurally from presence of source children',
                'unresolved_account_type_policy' => 'fail closed',
            ],
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,unclassified_type_rows:int,unclassified_type_samples:list<array<string,mixed>>,missing_code_rows:int,missing_name_rows:int,duplicate_source_ids:int} */
    private function classifyRows(
        string $qualified,
        string $idColumn,
        string $codeColumn,
        string $nameColumn,
        ?string $parentColumn,
        string $typeColumn,
        ?string $normalBalanceColumn,
        ?string $statusColumn,
        array $typeLookup,
        bool $includeRaw,
    ): array {
        $select = array_values(array_unique(array_filter([
            $idColumn,
            $codeColumn,
            $nameColumn,
            $parentColumn,
            $typeColumn,
            $normalBalanceColumn,
            $statusColumn,
        ])));

        $sourceRows = $this->legacySource()->table($qualified)
            ->select($select)
            ->orderBy($idColumn)
            ->get();

        $sourceIds = [];
        foreach ($sourceRows as $row) {
            $sourceId = (int) ($row->{$idColumn} ?? 0);
            if ($sourceId > 0) {
                $sourceIds[$sourceId] = ($sourceIds[$sourceId] ?? 0) + 1;
            }
        }

        $childParents = [];
        if ($parentColumn !== null) {
            foreach ($sourceRows as $row) {
                $parentId = (int) ($row->{$parentColumn} ?? 0);
                if ($parentId > 0) {
                    $childParents[$parentId] = true;
                }
            }
        }

        $rows = [];
        $unclassified = 0;
        $unclassifiedSamples = [];
        $missingCode = 0;
        $missingName = 0;

        foreach ($sourceRows as $sourceRow) {
            $sourceId = (int) ($sourceRow->{$idColumn} ?? 0);
            $sourceCode = trim((string) ($sourceRow->{$codeColumn} ?? ''));
            $name = trim((string) ($sourceRow->{$nameColumn} ?? ''));
            $sourceParentId = $parentColumn === null ? null : (int) ($sourceRow->{$parentColumn} ?? 0);
            $sourceParentId = $sourceParentId !== null && $sourceParentId > 0 ? $sourceParentId : null;
            $rawType = $sourceRow->{$typeColumn} ?? null;

            if ($sourceCode === '') {
                $missingCode++;
            }
            if ($name === '') {
                $missingName++;
            }

            $typeLabel = $this->lookupTypeLabel($rawType, $typeLookup['values']);
            $type = $this->normalizeAccountType($typeLabel ?? $rawType);

            if ($type === null) {
                $unclassified++;
                if (count($unclassifiedSamples) < 30) {
                    $unclassifiedSamples[] = [
                        'source_id' => $sourceId,
                        'code' => $sourceCode,
                        'raw_type' => $rawType,
                        'lookup_label' => $typeLabel,
                    ];
                }
            }

            $normalRaw = $normalBalanceColumn === null ? null : ($sourceRow->{$normalBalanceColumn} ?? null);
            $normalBalance = $this->normalizeNormalBalance($normalRaw);
            if ($normalBalance === null && $type !== null) {
                $normalBalance = in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
            }

            $rows[] = [
                'source_id' => $sourceId,
                'source_code' => $sourceCode,
                'name' => $name,
                'source_parent_id' => $sourceParentId,
                'source_type_raw' => $rawType,
                'type' => $type,
                'normal_balance' => $normalBalance,
                'is_control' => isset($childParents[$sourceId]),
                'status' => $this->normalizeStatus(
                    $statusColumn,
                    $statusColumn === null ? null : ($sourceRow->{$statusColumn} ?? null),
                ),
                'raw' => $includeRaw ? (array) $sourceRow : [],
            ];
        }

        return [
            'rows' => $rows,
            'unclassified_type_rows' => $unclassified,
            'unclassified_type_samples' => $unclassifiedSamples,
            'missing_code_rows' => $missingCode,
            'missing_name_rows' => $missingName,
            'duplicate_source_ids' => count(array_filter($sourceIds, static fn (int $count): bool => $count > 1)),
        ];
    }

    private function normalizeAccountType(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = mb_strtolower(trim((string) $value));
        $compact = preg_replace('/[\s_\-]+/u', '', $text) ?? $text;

        $map = [
            'asset' => ['asset', 'assets', 'أصل', 'اصول', 'أصول'],
            'liability' => ['liability', 'liabilities', 'خصوم', 'التزام', 'التزامات'],
            'equity' => ['equity', 'capital', 'حقوقالملكية', 'حقوق ملكية', 'رأسالمال', 'راسالمال'],
            'revenue' => ['revenue', 'income', 'sales', 'إيراد', 'ايراد', 'إيرادات', 'ايرادات'],
            'expense' => ['expense', 'expenses', 'cost', 'costs', 'مصروف', 'مصروفات', 'تكلفة', 'تكاليف'],
        ];

        foreach ($map as $normalized => $aliases) {
            foreach ($aliases as $alias) {
                $aliasCompact = preg_replace('/[\s_\-]+/u', '', mb_strtolower($alias)) ?? mb_strtolower($alias);
                if ($compact === $aliasCompact || str_contains($compact, $aliasCompact)) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function normalizeNormalBalance(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = mb_strtolower(trim((string) $value));
        if (in_array($text, ['d', 'dr', 'debit', 'مدين'], true)) {
            return 'debit';
        }
        if (in_array($text, ['c', 'cr', 'credit', 'دائن'], true)) {
            return 'credit';
        }

        return null;
    }

    private function normalizeStatus(?string $column, mixed $value): string
    {
        if ($column === null) {
            return 'active';
        }

        $columnNormalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $column));

        if (in_array($columnNormalized, ['inactive', 'isinactive'], true)) {
            return (int) $value === 1 ? 'inactive' : 'active';
        }

        if (in_array($columnNormalized, ['isactive', 'active'], true)) {
            return (int) $value === 0 ? 'inactive' : 'active';
        }

        if (is_numeric($value)) {
            return (int) $value === 0 ? 'inactive' : 'active';
        }

        $text = strtolower(trim((string) $value));

        return in_array($text, ['inactive', 'disabled', 'closed', '0'], true)
            ? 'inactive'
            : 'active';
    }

    /** @return array{values:array<string,string>,meta:array<string,mixed>} */
    private function typeLookup(): array
    {
        $tables = $this->legacySource()->select(
            "SELECT s.name AS schema_name, t.name AS table_name
             FROM sys.tables t
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE t.is_ms_shipped = 0
             ORDER BY s.name, t.name"
        );

        foreach ($tables as $table) {
            $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $table->table_name));
            if (! str_contains($normalized, 'accounttype') && ! str_contains($normalized, 'accttype')) {
                continue;
            }

            $columns = $this->sourceColumns((string) $table->schema_name, (string) $table->table_name);
            $idColumn = $this->matchColumn($columns, ['ID', 'Id', 'TypeID', 'TypeId', 'AccountTypeID', 'AccountTypeId']);
            $nameColumn = $this->matchColumn($columns, ['Name', 'Name_En', 'Name_Ar', 'Caption', 'Description', 'TypeName']);

            if ($idColumn === null || $nameColumn === null) {
                continue;
            }

            $qualified = (string) $table->schema_name.'.'.(string) $table->table_name;
            $values = [];

            foreach ($this->legacySource()->table($qualified)->get([$idColumn, $nameColumn]) as $row) {
                $id = (string) ($row->{$idColumn} ?? '');
                $name = trim((string) ($row->{$nameColumn} ?? ''));
                if ($id !== '' && $name !== '') {
                    $values[$id] = $name;
                }
            }

            if ($values !== []) {
                return [
                    'values' => $values,
                    'meta' => [
                        'table' => $qualified,
                        'id_column' => $idColumn,
                        'name_column' => $nameColumn,
                        'rows' => count($values),
                    ],
                ];
            }
        }

        return [
            'values' => [],
            'meta' => [
                'table' => null,
                'id_column' => null,
                'name_column' => null,
                'rows' => 0,
            ],
        ];
    }

    private function lookupTypeLabel(mixed $value, array $lookup): ?string
    {
        if ($value === null) {
            return null;
        }

        $key = (string) $value;

        return isset($lookup[$key]) ? (string) $lookup[$key] : null;
    }

    /** @return list<array{value:mixed,count:int}> */
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

    /** @return array{schema:string,table:string} */
    private function resolveSourceTable(): array
    {
        $rows = $this->legacySource()->select(
            "SELECT s.name AS schema_name, t.name AS table_name
             FROM sys.tables t
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE t.is_ms_shipped = 0"
        );

        $byNormalized = [];
        foreach ($rows as $row) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $row->table_name));
            $byNormalized[$key][] = [
                'schema' => (string) $row->schema_name,
                'table' => (string) $row->table_name,
            ];
        }

        foreach (self::SOURCE_TABLE_ALIASES as $alias) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $alias));
            foreach ($byNormalized[$key] ?? [] as $candidate) {
                if ((int) $this->legacySource()->table($candidate['schema'].'.'.$candidate['table'])->count() > 0) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException('No non-empty legacy GL account master table could be resolved.');
    }

    /** @return list<string> */
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
        $normalizedColumns = [];
        foreach ($columns as $column) {
            $normalizedColumns[strtolower((string) preg_replace('/[^a-z0-9]/i', '', $column))] = (string) $column;
        }

        foreach ($aliases as $alias) {
            $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $alias));
            if (isset($normalizedColumns[$normalized])) {
                return $normalizedColumns[$normalized];
            }
        }

        return null;
    }

    /** @return array{0:int,1:int,2:int} */
    private function scope(): array
    {
        $organizationId = (int) DB::table('enterprise_organizations')
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)
            ->value('id');
        $companyId = (int) DB::table('enterprise_companies')
            ->where('organization_id', $organizationId)
            ->where('code', OrganizationHierarchyService::DEFAULT_COMPANY)
            ->value('id');
        $legalEntityId = (int) DB::table('enterprise_legal_entities')
            ->where('company_id', $companyId)
            ->where('code', OrganizationHierarchyService::DEFAULT_LEGAL_ENTITY)
            ->value('id');

        if ($organizationId <= 0 || $companyId <= 0 || $legalEntityId <= 0) {
            throw new RuntimeException('Default enterprise finance scope is missing.');
        }

        return [$organizationId, $companyId, $legalEntityId];
    }

    private function mappingCount(): int
    {
        try {
            $profile = $this->profile();
        } catch (RuntimeException) {
            return 0;
        }

        return (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', $profile['source_table'])
            ->where('target_table', 'enterprise_finance_accounts')
            ->count();
    }

    private function legacySource(): ConnectionInterface
    {
        return DB::connection('foodex_legacy');
    }

    private function assertTargetTables(): void
    {
        foreach ([
            'enterprise_organizations',
            'enterprise_companies',
            'enterprise_legal_entities',
            'enterprise_finance_accounts',
            'legacy_import_mappings',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}; run migrations and enterprise bootstrap first.");
            }
        }
    }
}
