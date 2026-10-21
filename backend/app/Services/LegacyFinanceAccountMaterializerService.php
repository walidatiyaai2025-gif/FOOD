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

    private const PARENT_ALIASES = ['ParentID', 'ParentId', 'AccountParentID', 'AccountParentId', 'ParentAccountID', 'ParentAccountId', 'ParentAccID', 'ParentAccId', 'MainAccountID', 'MainAccountId'];

    private const TYPE_ALIASES = ['AccountFinalListType', 'FinalListType', 'FinancialStatementType', 'AccountNature', 'Nature', 'NatureName', 'AccountClass', 'Classification', 'AccountType', 'AccountTypeName', 'Type', 'TypeName'];

    private const NORMAL_BALANCE_ALIASES = ['NormalBalance', 'BalanceNature', 'BalanceType', 'DebitCredit', 'DrCr', 'DC', 'DebitOrCredit'];

    private const STATUS_ALIASES = ['Status', 'AccountStatus', 'IsActive', 'Active', 'Inactive', 'IsInactive'];

    public function profile(): array
    {
        $sourceTable = $this->resolveSourceTable();
        $columns = $this->sourceColumns($sourceTable['schema'], $sourceTable['table']);

        $idColumn = $this->matchColumn($columns, self::ID_ALIASES);
        $codeColumn = $this->matchColumn($columns, self::CODE_ALIASES);
        $nameColumn = $this->matchColumn($columns, self::NAME_ALIASES);
        $nameArColumn = $this->matchColumn($columns, ['Name_AR', 'Name_Ar', 'ArabicName', 'NameArabic']);
        $parentColumn = $this->matchColumn($columns, self::PARENT_ALIASES);
        $typeColumn = $this->matchColumn($columns, self::TYPE_ALIASES);
        $normalBalanceColumn = $this->matchColumn($columns, self::NORMAL_BALANCE_ALIASES);
        $statusColumn = $this->matchColumn($columns, self::STATUS_ALIASES);

        $qualified = $sourceTable['schema'].'.'.$sourceTable['table'];
        $sourceRows = (int) $this->legacySource()->table($qualified)->count();

        $typeLookup = $this->typeLookup();
        $typeValues = $this->distinctValues($qualified, $typeColumn, 50);
        $normalBalanceValues = $this->distinctValues($qualified, $normalBalanceColumn, 50);
        $functionalTypeColumn = $this->matchColumn($columns, ['AccountType', 'AccountTypeName']);
        $finalListColumn = $this->matchColumn($columns, ['AccountFinalListType', 'FinalListType']);
        $hasAccountsColumn = $this->matchColumn($columns, ['HasAccounts']);
        $hasTransColumn = $this->matchColumn($columns, ['HasTrans']);
        $functionalTypeValues = $this->distinctValues($qualified, $functionalTypeColumn, 50);
        $finalListValues = $this->distinctValues($qualified, $finalListColumn, 50);
        $hasAccountsValues = $this->distinctValues($qualified, $hasAccountsColumn, 20);
        $hasTransValues = $this->distinctValues($qualified, $hasTransColumn, 20);
        $rootAccounts = $this->rootAccountProfile(
            $qualified,
            $idColumn,
            $codeColumn,
            $nameColumn,
            $nameArColumn,
            $parentColumn,
            $functionalTypeColumn,
            $finalListColumn,
            $hasAccountsColumn,
            $hasTransColumn,
            $statusColumn,
        );

        $requiredResolved = $idColumn !== null
            && $codeColumn !== null
            && $nameColumn !== null
            && $parentColumn !== null;

        $classification = $requiredResolved
            ? $this->classifyRows(
                $qualified,
                $idColumn,
                $codeColumn,
                $nameColumn,
                $nameArColumn,
                $parentColumn,
                $typeColumn,
                $normalBalanceColumn,
                $statusColumn,
                $hasTransColumn,
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
            'source_table_name' => $sourceTable['table'],
            'source_schema' => $sourceTable['schema'],
            'source_rows' => $sourceRows,
            'columns' => $columns,
            'resolved_columns' => [
                'id' => $idColumn,
                'code' => $codeColumn,
                'name' => $nameColumn,
                'name_ar' => $nameArColumn,
                'parent' => $parentColumn,
                'type' => $typeColumn,
                'normal_balance' => $normalBalanceColumn,
                'status' => $statusColumn,
                'has_trans' => $hasTransColumn,
            ],
            'type_lookup' => $typeLookup['meta'],
            'type_lookup_values' => collect($typeLookup['values'])
                ->map(static fn (string $label, string $id): array => [
                    'id' => $id,
                    'label' => $label,
                ])
                ->values()
                ->all(),
            'type_values' => $typeValues,
            'normal_balance_values' => $normalBalanceValues,
            'functional_type_column' => $functionalTypeColumn,
            'functional_type_values' => $functionalTypeValues,
            'final_list_column' => $finalListColumn,
            'final_list_values' => $finalListValues,
            'has_accounts_column' => $hasAccountsColumn,
            'has_accounts_values' => $hasAccountsValues,
            'has_trans_column' => $hasTransColumn,
            'has_trans_values' => $hasTransValues,
            'root_accounts' => $rootAccounts,
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
                    'source_table' => $profile['source_table_name'],
                    'resolved_columns' => $profile['resolved_columns'],
                    'type_lookup' => $profile['type_lookup'],
                    'type_lookup_values' => $profile['type_lookup_values'],
                    'source_columns' => $profile['columns'],
                    'type_values' => $profile['type_values'],
                    'normal_balance_values' => $profile['normal_balance_values'],
                    'functional_type_column' => $profile['functional_type_column'],
                    'functional_type_values' => $profile['functional_type_values'],
                    'final_list_column' => $profile['final_list_column'],
                    'final_list_values' => $profile['final_list_values'],
                    'has_accounts_values' => $profile['has_accounts_values'],
                    'has_trans_values' => $profile['has_trans_values'],
                    'root_accounts' => $profile['root_accounts'],
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
            $columns['name_ar'],
            $columns['parent'],
            $columns['type'],
            $columns['normal_balance'],
            $columns['status'],
            $columns['has_trans'],
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
                    ->where('source_table', $profile['source_table_name'])
                    ->where('source_key', $sourceKey)
                    ->where('target_table', 'enterprise_finance_accounts')
                    ->value('target_id');
                $targetId = $mappedId === null ? null : (int) $mappedId;

                $desiredCode = mb_substr((string) $row['source_code'], 0, 64);
                $conflictQuery = DB::table('enterprise_finance_accounts')
                    ->where('legal_entity_id', $legalEntityId)
                    ->where('code', $desiredCode);

                if ($targetId !== null) {
                    $conflictQuery->where('id', '<>', $targetId);
                }

                if ($conflictQuery->exists()) {
                    $desiredCode = mb_substr('FDX-GL-'.$row['source_id'].'-'.$row['source_code'], 0, 64);
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
                    'allow_posting' => (bool) $row['allow_posting'],
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
                        'source_table' => $profile['source_table_name'],
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
                            'source_root_id' => $row['source_root_id'],
                            'source_root_name' => $row['source_root_name'],
                            'source_root_name_ar' => $row['source_root_name_ar'],
                            'resolved_type' => $row['type'],
                            'resolved_normal_balance' => $row['normal_balance'],
                            'source_has_trans' => $row['source_has_trans'],
                            'allow_posting' => $row['allow_posting'],
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
                ->where('source_table', $profile['source_table_name'])
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
                    ->where('source_table', $profile['source_table_name'])
                    ->delete();
            }

            if (Schema::hasTable('legacy_business_table_manifest')) {
                DB::table('legacy_business_table_manifest')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', $profile['source_table_name'])
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

        $columns = $profile['resolved_columns'];
        $classification = $this->classifyRows(
            $profile['source_table'],
            (string) $columns['id'],
            (string) $columns['code'],
            (string) $columns['name'],
            $columns['name_ar'],
            $columns['parent'],
            $columns['type'],
            $columns['normal_balance'],
            $columns['status'],
            $columns['has_trans'],
            false,
        );

        $expectedRows = collect($classification['rows'])->keyBy(
            static fn (array $row): int => (int) $row['source_id']
        );

        $mappings = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', $profile['source_table_name'])
            ->where('target_table', 'enterprise_finance_accounts')
            ->get(['source_key', 'target_id']);

        $targetBySource = [];
        foreach ($mappings as $mapping) {
            $targetBySource[(int) $mapping->source_key] = (int) $mapping->target_id;
        }

        $targetIds = array_values($targetBySource);
        $accountsById = $targetIds === []
            ? collect()
            : DB::table('enterprise_finance_accounts')
                ->whereIn('id', $targetIds)
                ->get([
                    'id',
                    'parent_id',
                    'type',
                    'normal_balance',
                    'is_control',
                    'allow_posting',
                ])
                ->keyBy('id');

        $semanticMismatches = 0;
        $parentMismatches = 0;
        $controlMismatches = 0;
        $postingMismatches = 0;
        $semanticSamples = [];

        foreach ($expectedRows as $sourceId => $expected) {
            $targetId = $targetBySource[(int) $sourceId] ?? null;
            $account = $targetId === null ? null : $accountsById->get($targetId);

            if ($account === null) {
                continue;
            }

            $expectedParentTargetId = $expected['source_parent_id'] === null
                ? null
                : ($targetBySource[(int) $expected['source_parent_id']] ?? null);

            $semanticBad = (string) $account->type !== (string) $expected['type']
                || (string) $account->normal_balance !== (string) $expected['normal_balance'];

            if ($semanticBad) {
                $semanticMismatches++;
            }

            if (
                ($account->parent_id === null ? null : (int) $account->parent_id)
                !== ($expectedParentTargetId === null ? null : (int) $expectedParentTargetId)
            ) {
                $parentMismatches++;
            }

            if ((bool) $account->is_control !== (bool) $expected['is_control']) {
                $controlMismatches++;
            }

            if ((bool) $account->allow_posting !== (bool) $expected['allow_posting']) {
                $postingMismatches++;
            }

            if (
                ($semanticBad
                    || (($account->parent_id === null ? null : (int) $account->parent_id)
                        !== ($expectedParentTargetId === null ? null : (int) $expectedParentTargetId))
                    || (bool) $account->is_control !== (bool) $expected['is_control']
                    || (bool) $account->allow_posting !== (bool) $expected['allow_posting'])
                && count($semanticSamples) < 25
            ) {
                $semanticSamples[] = [
                    'source_id' => (int) $sourceId,
                    'target_id' => (int) $targetId,
                    'expected_type' => $expected['type'],
                    'actual_type' => (string) $account->type,
                    'expected_normal_balance' => $expected['normal_balance'],
                    'actual_normal_balance' => (string) $account->normal_balance,
                    'expected_parent_id' => $expectedParentTargetId,
                    'actual_parent_id' => $account->parent_id === null ? null : (int) $account->parent_id,
                    'expected_is_control' => (bool) $expected['is_control'],
                    'actual_is_control' => (bool) $account->is_control,
                    'expected_allow_posting' => (bool) $expected['allow_posting'],
                    'actual_allow_posting' => (bool) $account->allow_posting,
                ];
            }
        }

        $orphanMappings = (int) DB::table('legacy_import_mappings as mapping')
            ->leftJoin('enterprise_finance_accounts as account', 'account.id', '=', 'mapping.target_id')
            ->where('mapping.source_system', self::SOURCE_SYSTEM)
            ->where('mapping.source_table', $profile['source_table_name'])
            ->where('mapping.target_table', 'enterprise_finance_accounts')
            ->whereNull('account.id')
            ->count();

        $duplicateTargetIds = (int) DB::query()
            ->fromSub(
                DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', $profile['source_table_name'])
                    ->where('target_table', 'enterprise_finance_accounts')
                    ->select('target_id')
                    ->groupBy('target_id')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_targets',
            )
            ->count();

        $expectedTypeCounts = $expectedRows
            ->groupBy(static fn (array $row): string => (string) $row['type'])
            ->map(static fn ($rows): int => $rows->count())
            ->all();

        $actualTypeCounts = $accountsById
            ->groupBy(static fn ($row): string => (string) $row->type)
            ->map(static fn ($rows): int => $rows->count())
            ->all();

        ksort($expectedTypeCounts);
        ksort($actualTypeCounts);

        $healthy = $profile['ready']
            && $mappingCount === $sourceRows
            && $orphanMappings === 0
            && $duplicateTargetIds === 0
            && $semanticMismatches === 0
            && $parentMismatches === 0
            && $controlMismatches === 0
            && $postingMismatches === 0
            && $expectedTypeCounts === $actualTypeCounts;

        return [
            'healthy' => $healthy,
            'profile' => $profile,
            'target' => [
                'account_mappings' => $mappingCount,
                'orphan_mappings' => $orphanMappings,
                'duplicate_target_ids' => $duplicateTargetIds,
                'semantic_mismatches' => $semanticMismatches,
                'parent_mismatches' => $parentMismatches,
                'control_flag_mismatches' => $controlMismatches,
                'posting_flag_mismatches' => $postingMismatches,
                'expected_type_counts' => $expectedTypeCounts,
                'actual_type_counts' => $actualTypeCounts,
                'mismatch_samples' => $semanticSamples,
            ],
            'rule' => [
                'type_values' => ['asset', 'liability', 'equity', 'revenue', 'expense'],
                'type_authority' => 'AccountParentID ancestry to explicit named root accounts',
                'normal_balance' => 'explicit source value when recognized; otherwise derived from resolved root account type',
                'control_account' => 'derived structurally from presence of source children',
                'allow_posting' => 'structural leaf-only posting; source HasTrans retained as provenance only',
                'functional_account_type' => 'preserved as provenance only; never used as financial statement classification',
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
        ?string $nameArColumn,
        ?string $parentColumn,
        ?string $typeColumn,
        ?string $normalBalanceColumn,
        ?string $statusColumn,
        ?string $hasTransColumn,
        bool $includeRaw,
    ): array {
        $select = array_values(array_unique(array_filter([
            $idColumn,
            $codeColumn,
            $nameColumn,
            $nameArColumn,
            $parentColumn,
            $typeColumn,
            $normalBalanceColumn,
            $statusColumn,
            $hasTransColumn,
        ])));

        $sourceRows = $this->legacySource()->table($qualified)
            ->select($select)
            ->orderBy($idColumn)
            ->get();

        $sourceIds = [];
        $sourceById = [];
        foreach ($sourceRows as $row) {
            $sourceId = (int) ($row->{$idColumn} ?? 0);
            if ($sourceId > 0) {
                $sourceIds[$sourceId] = ($sourceIds[$sourceId] ?? 0) + 1;
                $sourceById[$sourceId] = $row;
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

        $classificationCache = [];
        $resolveClassification = function (int $sourceId, array $stack = []) use (
            &$resolveClassification,
            &$classificationCache,
            $sourceById,
            $parentColumn,
            $nameColumn,
            $nameArColumn,
            $codeColumn,
        ): ?array {
            if (isset($classificationCache[$sourceId])) {
                return $classificationCache[$sourceId];
            }

            if (in_array($sourceId, $stack, true)) {
                return null;
            }

            $row = $sourceById[$sourceId] ?? null;
            if ($row === null) {
                return null;
            }

            $parentId = $parentColumn === null ? 0 : (int) ($row->{$parentColumn} ?? 0);
            $nameEn = trim((string) ($row->{$nameColumn} ?? ''));
            $nameAr = $nameArColumn === null ? '' : trim((string) ($row->{$nameArColumn} ?? ''));
            $code = trim((string) ($row->{$codeColumn} ?? ''));

            if ($parentId <= 0) {
                $type = $this->normalizeAccountType($nameEn)
                    ?? $this->normalizeAccountType($nameAr);

                if ($type === null) {
                    return null;
                }

                return $classificationCache[$sourceId] = [
                    'type' => $type,
                    'root_id' => $sourceId,
                    'root_code' => $code,
                    'root_name' => $nameEn,
                    'root_name_ar' => $nameAr,
                ];
            }

            if (! isset($sourceById[$parentId])) {
                return null;
            }

            $stack[] = $sourceId;
            $resolved = $resolveClassification($parentId, $stack);
            if ($resolved === null) {
                return null;
            }

            return $classificationCache[$sourceId] = $resolved;
        };

        $rows = [];
        $unclassified = 0;
        $unclassifiedSamples = [];
        $missingCode = 0;
        $missingName = 0;

        foreach ($sourceRows as $sourceRow) {
            $sourceId = (int) ($sourceRow->{$idColumn} ?? 0);
            $sourceCode = trim((string) ($sourceRow->{$codeColumn} ?? ''));
            $name = trim((string) ($sourceRow->{$nameColumn} ?? ''));
            $nameAr = $nameArColumn === null ? null : trim((string) ($sourceRow->{$nameArColumn} ?? ''));
            $sourceParentId = $parentColumn === null ? null : (int) ($sourceRow->{$parentColumn} ?? 0);
            $sourceParentId = $sourceParentId !== null && $sourceParentId > 0 ? $sourceParentId : null;
            $rawType = $typeColumn === null ? null : ($sourceRow->{$typeColumn} ?? null);

            if ($sourceCode === '') {
                $missingCode++;
            }
            if ($name === '') {
                $missingName++;
            }

            $resolved = $sourceId > 0 ? $resolveClassification($sourceId) : null;
            $type = $resolved['type'] ?? null;

            if ($type === null) {
                $unclassified++;
                if (count($unclassifiedSamples) < 30) {
                    $unclassifiedSamples[] = [
                        'source_id' => $sourceId,
                        'code' => $sourceCode,
                        'name' => $name,
                        'name_ar' => $nameAr,
                        'parent_id' => $sourceParentId,
                        'raw_type' => $rawType,
                        'reason' => 'No resolvable classified root ancestor.',
                    ];
                }
            }

            $normalRaw = $normalBalanceColumn === null ? null : ($sourceRow->{$normalBalanceColumn} ?? null);
            $normalBalance = $this->normalizeNormalBalance($normalRaw);
            if ($normalBalance === null && $type !== null) {
                $normalBalance = in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
            }

            $sourceHasTrans = $hasTransColumn === null ? null : ($sourceRow->{$hasTransColumn} ?? null);
            $allowPosting = ! isset($childParents[$sourceId]);

            $rows[] = [
                'source_id' => $sourceId,
                'source_code' => $sourceCode,
                'name' => $name,
                'name_ar' => $nameAr,
                'source_parent_id' => $sourceParentId,
                'source_type_raw' => $rawType,
                'source_root_id' => $resolved['root_id'] ?? null,
                'source_root_code' => $resolved['root_code'] ?? null,
                'source_root_name' => $resolved['root_name'] ?? null,
                'source_root_name_ar' => $resolved['root_name_ar'] ?? null,
                'type' => $type,
                'normal_balance' => $normalBalance,
                'is_control' => isset($childParents[$sourceId]),
                'source_has_trans' => $sourceHasTrans,
                'allow_posting' => $allowPosting,
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
            'SELECT s.name AS schema_name, t.name AS table_name
             FROM sys.tables t
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE t.is_ms_shipped = 0
             ORDER BY s.name, t.name'
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

    /** @return list<array<string,mixed>> */
    private function rootAccountProfile(
        string $qualified,
        ?string $idColumn,
        ?string $codeColumn,
        ?string $nameColumn,
        ?string $nameArColumn,
        ?string $parentColumn,
        ?string $functionalTypeColumn,
        ?string $finalListColumn,
        ?string $hasAccountsColumn,
        ?string $hasTransColumn,
        ?string $statusColumn,
    ): array {
        if ($idColumn === null || $codeColumn === null || $nameColumn === null || $parentColumn === null) {
            return [];
        }

        $select = array_values(array_unique(array_filter([
            $idColumn,
            $codeColumn,
            $nameColumn,
            $nameArColumn,
            $parentColumn,
            $functionalTypeColumn,
            $finalListColumn,
            $hasAccountsColumn,
            $hasTransColumn,
            $statusColumn,
        ])));

        return $this->legacySource()->table($qualified)
            ->select($select)
            ->where(function ($query) use ($parentColumn): void {
                $query->whereNull($parentColumn)->orWhere($parentColumn, '<=', 0);
            })
            ->orderBy($codeColumn)
            ->limit(100)
            ->get()
            ->map(function ($row) use (
                $idColumn,
                $codeColumn,
                $nameColumn,
                $nameArColumn,
                $parentColumn,
                $functionalTypeColumn,
                $finalListColumn,
                $hasAccountsColumn,
                $hasTransColumn,
                $statusColumn,
            ): array {
                return [
                    'id' => (int) ($row->{$idColumn} ?? 0),
                    'code' => trim((string) ($row->{$codeColumn} ?? '')),
                    'name' => trim((string) ($row->{$nameColumn} ?? '')),
                    'name_ar' => $nameArColumn === null ? null : trim((string) ($row->{$nameArColumn} ?? '')),
                    'parent_id' => $row->{$parentColumn} ?? null,
                    'account_type' => $functionalTypeColumn === null ? null : ($row->{$functionalTypeColumn} ?? null),
                    'final_list_type' => $finalListColumn === null ? null : ($row->{$finalListColumn} ?? null),
                    'has_accounts' => $hasAccountsColumn === null ? null : ($row->{$hasAccountsColumn} ?? null),
                    'has_trans' => $hasTransColumn === null ? null : ($row->{$hasTransColumn} ?? null),
                    'status' => $statusColumn === null ? null : ($row->{$statusColumn} ?? null),
                ];
            })
            ->all();
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
            'SELECT s.name AS schema_name, t.name AS table_name
             FROM sys.tables t
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE t.is_ms_shipped = 0'
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
                'SELECT c.name AS column_name
                 FROM sys.tables t
                 INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
                 INNER JOIN sys.columns c ON c.object_id = t.object_id
                 WHERE s.name = ? AND t.name = ?
                 ORDER BY c.column_id',
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
            ->where('source_table', $profile['source_table_name'])
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
