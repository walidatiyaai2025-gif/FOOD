<?php

namespace App\Services;

use App\Enterprise\Organization\OrganizationHierarchyService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyFinanceCostCenterMaterializerService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';
    private const SOURCE_ALIASES = ['gl_CostCenters', 'gl_CostCenter', 'CostCenters', 'CostCenter'];
    private const ID_ALIASES = ['ID', 'Id', 'CostCenterID', 'CostCenterId', 'CenterID', 'CenterId'];
    private const CODE_ALIASES = ['Code', 'CostCenterCode', 'CenterCode', 'No', 'Number'];
    private const NAME_ALIASES = ['Name_En', 'Name_EN', 'Name', 'CostCenterName', 'CenterName', 'Description'];
    private const NAME_AR_ALIASES = ['Name_AR', 'Name_Ar', 'ArabicName', 'NameArabic'];
    private const PARENT_ALIASES = ['ParentID', 'ParentId', 'CostCenterParentID', 'CostCenterParentId', 'ParentCostCenterID', 'ParentCostCenterId'];
    private const STATUS_ALIASES = ['Status', 'CostCenterStatus', 'IsActive', 'Active', 'Inactive', 'IsInactive'];

    public function profile(): array
    {
        $source = $this->resolveSourceTable();
        $columns = $this->sourceColumns($source['schema'], $source['table']);
        $qualified = $source['schema'].'.'.$source['table'];

        $id = $this->matchColumn($columns, self::ID_ALIASES);
        $code = $this->matchColumn($columns, self::CODE_ALIASES);
        $name = $this->matchColumn($columns, self::NAME_ALIASES);
        $nameAr = $this->matchColumn($columns, self::NAME_AR_ALIASES);
        $parent = $this->matchColumn($columns, self::PARENT_ALIASES);
        $status = $this->matchColumn($columns, self::STATUS_ALIASES);

        $rowCount = (int) $this->legacySource()->table($qualified)->count();
        $analysis = ($id !== null && $code !== null && $name !== null)
            ? $this->analyze($qualified, $id, $code, $name, $nameAr, $parent, $status)
            : [
                'rows' => [],
                'missing_id_rows' => $rowCount,
                'duplicate_ids' => 0,
                'missing_code_rows' => 0,
                'missing_name_rows' => 0,
                'missing_parent_rows' => 0,
                'parent_cycle_rows' => 0,
            ];

        return [
            'source_table' => $qualified,
            'source_table_name' => $source['table'],
            'source_schema' => $source['schema'],
            'source_rows' => $rowCount,
            'columns' => $columns,
            'resolved_columns' => [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'name_ar' => $nameAr,
                'parent' => $parent,
                'status' => $status,
            ],
            'status_values' => $this->distinctValues($qualified, $status),
            'classification' => [
                'missing_id_rows' => $analysis['missing_id_rows'],
                'duplicate_ids' => $analysis['duplicate_ids'],
                'missing_code_rows' => $analysis['missing_code_rows'],
                'missing_name_rows' => $analysis['missing_name_rows'],
                'missing_parent_rows' => $analysis['missing_parent_rows'],
                'parent_cycle_rows' => $analysis['parent_cycle_rows'],
            ],
            'ready' => $id !== null
                && $code !== null
                && $name !== null
                && $rowCount > 0
                && $analysis['missing_id_rows'] === 0
                && $analysis['duplicate_ids'] === 0
                && $analysis['missing_code_rows'] === 0
                && $analysis['missing_name_rows'] === 0
                && $analysis['missing_parent_rows'] === 0
                && $analysis['parent_cycle_rows'] === 0,
        ];
    }

    public function materialize(bool $apply): array
    {
        $this->assertTargets();
        $profile = $this->profile();

        if (! $apply) {
            return [
                'mode' => 'dry-run',
                'profile' => $profile,
                'existing_mappings' => $this->mappingCount($profile['source_table_name']),
            ];
        }

        if (! $profile['ready']) {
            throw new RuntimeException(
                'Cost center apply refused: source semantics are not fully resolved. '.
                json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        [$organizationId, $companyId, $legalEntityId] = $this->scope();
        $columns = $profile['resolved_columns'];
        $analysis = $this->analyze(
            $profile['source_table'],
            (string) $columns['id'],
            (string) $columns['code'],
            (string) $columns['name'],
            $columns['name_ar'],
            $columns['parent'],
            $columns['status'],
        );

        DB::transaction(function () use ($analysis, $profile, $organizationId, $companyId, $legalEntityId): void {
            $targetBySource = [];
            $activeKeys = [];

            foreach ($analysis['rows'] as $row) {
                $sourceKey = (string) $row['source_id'];
                $activeKeys[] = $sourceKey;

                $targetId = DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', $profile['source_table_name'])
                    ->where('source_key', $sourceKey)
                    ->where('target_table', 'enterprise_cost_centers')
                    ->value('target_id');
                $targetId = $targetId === null ? null : (int) $targetId;

                $desiredCode = mb_substr((string) $row['source_code'], 0, 64);
                $conflict = DB::table('enterprise_cost_centers')
                    ->where('legal_entity_id', $legalEntityId)
                    ->where('code', $desiredCode)
                    ->when($targetId !== null, fn ($q) => $q->where('id', '<>', $targetId))
                    ->exists();

                if ($conflict) {
                    $desiredCode = mb_substr('FDX-CC-'.$row['source_id'].'-'.$row['source_code'], 0, 64);
                }

                $payload = [
                    'organization_id' => $organizationId,
                    'company_id' => $companyId,
                    'legal_entity_id' => $legalEntityId,
                    'parent_id' => null,
                    'code' => $desiredCode,
                    'name' => mb_substr((string) $row['name'], 0, 190),
                    'status' => (string) $row['status'],
                    'created_by' => null,
                    'updated_by' => null,
                    'updated_at' => now(),
                ];

                if ($targetId !== null && DB::table('enterprise_cost_centers')->where('id', $targetId)->exists()) {
                    DB::table('enterprise_cost_centers')->where('id', $targetId)->update($payload);
                } else {
                    $payload['created_at'] = now();
                    $targetId = (int) DB::table('enterprise_cost_centers')->insertGetId($payload);
                }

                $targetBySource[(int) $row['source_id']] = $targetId;

                DB::table('legacy_import_mappings')->updateOrInsert(
                    [
                        'source_system' => self::SOURCE_SYSTEM,
                        'source_table' => $profile['source_table_name'],
                        'source_key' => $sourceKey,
                        'target_table' => 'enterprise_cost_centers',
                    ],
                    [
                        'target_id' => $targetId,
                        'source_hash' => hash('sha256', json_encode($row['raw'], JSON_THROW_ON_ERROR)),
                        'metadata' => json_encode([
                            'authority' => 'legacy-cost-center-master',
                            'source_code' => $row['source_code'],
                            'source_name_ar' => $row['name_ar'],
                            'source_parent_id' => $row['source_parent_id'],
                            'resolved_status' => $row['status'],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'last_run_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            foreach ($analysis['rows'] as $row) {
                $targetId = $targetBySource[(int) $row['source_id']];
                $parentId = $row['source_parent_id'] === null
                    ? null
                    : ($targetBySource[(int) $row['source_parent_id']] ?? null);

                if ($row['source_parent_id'] !== null && $parentId === null) {
                    throw new RuntimeException(
                        'Missing native parent cost center for source child '.
                        $row['source_id'].' -> '.$row['source_parent_id']
                    );
                }

                DB::table('enterprise_cost_centers')
                    ->where('id', $targetId)
                    ->update(['parent_id' => $parentId, 'updated_at' => now()]);
            }

            $stale = DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->where('source_table', $profile['source_table_name'])
                ->where('target_table', 'enterprise_cost_centers')
                ->when($activeKeys !== [], fn ($q) => $q->whereNotIn('source_key', $activeKeys))
                ->get(['id', 'target_id']);

            foreach ($stale as $mapping) {
                DB::table('enterprise_cost_centers')
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

        return ['mode' => 'apply', ...$this->verify()];
    }

    public function verify(): array
    {
        $profile = $this->profile();
        $columns = $profile['resolved_columns'];
        $analysis = $profile['ready']
            ? $this->analyze(
                $profile['source_table'],
                (string) $columns['id'],
                (string) $columns['code'],
                (string) $columns['name'],
                $columns['name_ar'],
                $columns['parent'],
                $columns['status'],
            )
            : ['rows' => []];

        $mappings = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', $profile['source_table_name'])
            ->where('target_table', 'enterprise_cost_centers')
            ->get(['source_key', 'target_id']);

        $targetBySource = [];
        foreach ($mappings as $mapping) {
            $targetBySource[(int) $mapping->source_key] = (int) $mapping->target_id;
        }

        $targets = $targetBySource === []
            ? collect()
            : DB::table('enterprise_cost_centers')
                ->whereIn('id', array_values($targetBySource))
                ->get(['id', 'parent_id', 'code', 'name', 'status'])
                ->keyBy('id');

        $orphanMappings = 0;
        $semanticMismatches = 0;
        $parentMismatches = 0;
        $samples = [];

        foreach ($analysis['rows'] as $row) {
            $targetId = $targetBySource[(int) $row['source_id']] ?? null;
            $target = $targetId === null ? null : $targets->get($targetId);

            if ($target === null) {
                $orphanMappings++;
                continue;
            }

            $expectedParent = $row['source_parent_id'] === null
                ? null
                : ($targetBySource[(int) $row['source_parent_id']] ?? null);

            $semanticBad = (string) $target->name !== (string) $row['name']
                || (string) $target->status !== (string) $row['status'];
            $parentBad = ($target->parent_id === null ? null : (int) $target->parent_id)
                !== ($expectedParent === null ? null : (int) $expectedParent);

            if ($semanticBad) {
                $semanticMismatches++;
            }
            if ($parentBad) {
                $parentMismatches++;
            }

            if (($semanticBad || $parentBad) && count($samples) < 25) {
                $samples[] = [
                    'source_id' => $row['source_id'],
                    'target_id' => $targetId,
                    'source_code' => $row['source_code'],
                    'expected_name' => $row['name'],
                    'actual_name' => $target->name,
                    'expected_status' => $row['status'],
                    'actual_status' => $target->status,
                    'expected_parent_id' => $expectedParent,
                    'actual_parent_id' => $target->parent_id,
                ];
            }
        }

        $duplicateTargets = (int) DB::query()
            ->fromSub(
                DB::table('legacy_import_mappings')
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->where('source_table', $profile['source_table_name'])
                    ->where('target_table', 'enterprise_cost_centers')
                    ->select('target_id')
                    ->groupBy('target_id')
                    ->havingRaw('COUNT(*) > 1'),
                'cc_duplicate_targets',
            )
            ->count();

        $healthy = $profile['ready']
            && count($targetBySource) === (int) $profile['source_rows']
            && $orphanMappings === 0
            && $duplicateTargets === 0
            && $semanticMismatches === 0
            && $parentMismatches === 0;

        return [
            'healthy' => $healthy,
            'profile' => $profile,
            'target' => [
                'cost_center_mappings' => count($targetBySource),
                'orphan_mappings' => $orphanMappings,
                'duplicate_target_ids' => $duplicateTargets,
                'semantic_mismatches' => $semanticMismatches,
                'parent_mismatches' => $parentMismatches,
                'mismatch_samples' => $samples,
            ],
        ];
    }

    private function analyze(
        string $qualified,
        string $idColumn,
        string $codeColumn,
        string $nameColumn,
        ?string $nameArColumn,
        ?string $parentColumn,
        ?string $statusColumn,
    ): array {
        $select = array_values(array_unique(array_filter([
            $idColumn, $codeColumn, $nameColumn, $nameArColumn, $parentColumn, $statusColumn,
        ])));

        $sourceRows = $this->legacySource()->table($qualified)
            ->select($select)
            ->orderBy($idColumn)
            ->get();

        $idCounts = [];
        $byId = [];
        foreach ($sourceRows as $row) {
            $id = (int) ($row->{$idColumn} ?? 0);
            if ($id > 0) {
                $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
                $byId[$id] = $row;
            }
        }

        $missingId = 0;
        $missingCode = 0;
        $missingName = 0;
        $missingParent = 0;
        $cycleRows = 0;
        $rows = [];

        foreach ($sourceRows as $sourceRow) {
            $id = (int) ($sourceRow->{$idColumn} ?? 0);
            $code = trim((string) ($sourceRow->{$codeColumn} ?? ''));
            $name = trim((string) ($sourceRow->{$nameColumn} ?? ''));
            $nameAr = $nameArColumn === null ? null : trim((string) ($sourceRow->{$nameArColumn} ?? ''));
            $parent = $parentColumn === null ? null : (int) ($sourceRow->{$parentColumn} ?? 0);
            $parent = $parent !== null && $parent > 0 ? $parent : null;

            if ($id <= 0) {
                $missingId++;
            }
            if ($code === '') {
                $missingCode++;
            }
            if ($name === '') {
                $missingName++;
            }
            if ($parent !== null && ! isset($byId[$parent])) {
                $missingParent++;
            }

            $seen = [];
            $cursor = $id;
            while ($cursor > 0 && isset($byId[$cursor])) {
                if (isset($seen[$cursor])) {
                    $cycleRows++;
                    break;
                }
                $seen[$cursor] = true;
                $next = $parentColumn === null ? 0 : (int) ($byId[$cursor]->{$parentColumn} ?? 0);
                if ($next <= 0) {
                    break;
                }
                $cursor = $next;
            }

            $rows[] = [
                'source_id' => $id,
                'source_code' => $code,
                'name' => $name,
                'name_ar' => $nameAr,
                'source_parent_id' => $parent,
                'status' => $this->normalizeStatus($statusColumn, $statusColumn === null ? null : ($sourceRow->{$statusColumn} ?? null)),
                'raw' => (array) $sourceRow,
            ];
        }

        return [
            'rows' => $rows,
            'missing_id_rows' => $missingId,
            'duplicate_ids' => count(array_filter($idCounts, static fn (int $count): bool => $count > 1)),
            'missing_code_rows' => $missingCode,
            'missing_name_rows' => $missingName,
            'missing_parent_rows' => $missingParent,
            'parent_cycle_rows' => $cycleRows,
        ];
    }

    private function normalizeStatus(?string $column, mixed $value): string
    {
        if ($column === null) {
            return 'active';
        }

        $columnKey = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $column));
        if (in_array($columnKey, ['inactive', 'isinactive'], true)) {
            return (int) $value === 1 ? 'inactive' : 'active';
        }
        if (in_array($columnKey, ['isactive', 'active'], true)) {
            return (int) $value === 0 ? 'inactive' : 'active';
        }
        if (is_numeric($value)) {
            return (int) $value === 0 ? 'inactive' : 'active';
        }

        $text = strtolower(trim((string) $value));

        return in_array($text, ['inactive', 'disabled', 'closed', '0'], true) ? 'inactive' : 'active';
    }

    private function mappingCount(string $sourceTable): int
    {
        return (int) DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', $sourceTable)
            ->where('target_table', 'enterprise_cost_centers')
            ->count();
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
                if ($actual === $needle) {
                    $qualified = (string) $row->schema_name.'.'.(string) $row->table_name;
                    if ((int) $this->legacySource()->table($qualified)->count() > 0) {
                        return ['schema' => (string) $row->schema_name, 'table' => (string) $row->table_name];
                    }
                }
            }
        }

        throw new RuntimeException('No non-empty legacy cost-center master table could be resolved.');
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

    private function distinctValues(string $qualified, ?string $column): array
    {
        if ($column === null) {
            return [];
        }

        return $this->legacySource()->table($qualified)
            ->selectRaw("{$column} as value, COUNT(*) as row_count")
            ->groupBy($column)
            ->orderByDesc('row_count')
            ->limit(30)
            ->get()
            ->map(static fn ($row): array => ['value' => $row->value, 'count' => (int) $row->row_count])
            ->all();
    }

    private function scope(): array
    {
        $organizationId = (int) DB::table('enterprise_organizations')
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->value('id');
        $companyId = (int) DB::table('enterprise_companies')
            ->where('organization_id', $organizationId)
            ->where('code', OrganizationHierarchyService::DEFAULT_COMPANY)->value('id');
        $legalEntityId = (int) DB::table('enterprise_legal_entities')
            ->where('company_id', $companyId)
            ->where('code', OrganizationHierarchyService::DEFAULT_LEGAL_ENTITY)->value('id');

        if ($organizationId <= 0 || $companyId <= 0 || $legalEntityId <= 0) {
            throw new RuntimeException('Default enterprise finance scope is missing.');
        }

        return [$organizationId, $companyId, $legalEntityId];
    }

    private function legacySource(): ConnectionInterface
    {
        return DB::connection('foodex_legacy');
    }

    private function assertTargets(): void
    {
        foreach ([
            'enterprise_organizations',
            'enterprise_companies',
            'enterprise_legal_entities',
            'enterprise_cost_centers',
            'legacy_import_mappings',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}; run migrations first.");
            }
        }
    }
}
