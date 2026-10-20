<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LegacyFinanceAssetMaterializerService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    private const SOURCE_TABLE = 'gl_Assets';

    private const TARGET_TABLE = 'legacy_fixed_asset_records';

    public function preview(): array
    {
        $this->assertReady();
        $rows = $this->sourceRows();
        $existing = DB::table(self::TARGET_TABLE)
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_table', self::SOURCE_TABLE)
            ->get(['source_key', 'source_hash'])->keyBy('source_key');
        $planned = ['insert' => 0, 'update' => 0, 'unchanged' => 0];

        foreach ($rows as $row) {
            $old = $existing->get($row['source_key']);
            $planned[$old === null ? 'insert' : (hash_equals((string) $old->source_hash, $row['source_hash']) ? 'unchanged' : 'update')]++;
        }

        return [
            'mode' => 'dry-run',
            'source_table' => 'dbo.'.self::SOURCE_TABLE,
            'source_rows' => count($rows),
            'materialized_rows' => $existing->count(),
            'planned' => $planned,
            'native_preflight' => $this->nativePromotionPreflight($rows),
        ];
    }

    public function materialize(bool $apply): array
    {
        if (! $apply) {
            return $this->preview();
        }

        $this->assertReady();
        $rows = $this->sourceRows();
        $preview = $this->preview();
        $runId = (string) Str::uuid();
        DB::table('legacy_import_runs')->insert([
            'id' => $runId, 'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'finance-assets-materialization', 'mode' => 'apply', 'status' => 'running',
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($rows, $runId): void {
                $now = now();
                $upserts = array_map(static fn (array $row): array => [
                    ...$row, 'last_run_id' => $runId, 'created_at' => $now, 'updated_at' => $now,
                ], $rows);

                if ($upserts !== []) {
                    DB::table(self::TARGET_TABLE)->upsert(
                        $upserts,
                        ['source_system', 'source_table', 'source_key'],
                        [
                            'source_hash', 'asset_code', 'name_ar', 'name_en', 'acquisition_date', 'branch_id',
                            'group_id', 'sub_group_id', 'place_id', 'custody_employee_id', 'asset_age_raw',
                            'native_useful_life_months_candidate', 'gross_value', 'accumulated_depreciation',
                            'expired_flag', 'status_raw', 'group_code', 'group_name_ar', 'group_name_en',
                            'sub_group_code', 'sub_group_name_ar', 'sub_group_name_en', 'depreciation_rate_percent',
                            'native_blockers', 'source_payload', 'last_run_id', 'updated_at',
                        ],
                    );
                }

                $targets = DB::table(self::TARGET_TABLE)
                    ->where('source_system', self::SOURCE_SYSTEM)->where('source_table', self::SOURCE_TABLE)
                    ->get(['id', 'source_key', 'source_hash'])->keyBy('source_key');
                $mappings = [];
                foreach ($rows as $row) {
                    $target = $targets->get($row['source_key']);
                    if ($target === null) {
                        throw new RuntimeException('Missing materialized target for source asset '.$row['source_key']);
                    }
                    $mappings[] = [
                        'source_system' => self::SOURCE_SYSTEM, 'source_table' => self::SOURCE_TABLE,
                        'source_key' => $row['source_key'], 'target_table' => self::TARGET_TABLE,
                        'target_id' => (int) $target->id, 'source_hash' => $row['source_hash'],
                        'metadata' => $this->json(['asset_code' => $row['asset_code'], 'sub_group_id' => $row['sub_group_id']]),
                        'last_run_id' => $runId, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                if ($mappings !== []) {
                    DB::table('legacy_import_mappings')->upsert(
                        $mappings,
                        ['source_system', 'source_table', 'source_key', 'target_table'],
                        ['target_id', 'source_hash', 'metadata', 'last_run_id', 'updated_at'],
                    );
                }
            }, 3);

            $verification = $this->verify();
            if (! $verification['healthy']) {
                throw new RuntimeException('Fixed-asset verification failed: '.$this->json($verification));
            }
            $summary = [
                'source_rows' => count($rows), 'planned' => $preview['planned'], 'verification' => $verification,
                'native_preflight' => $this->nativePromotionPreflight($rows),
            ];
            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'completed', 'summary' => $this->json($summary),
                'finished_at' => now(), 'updated_at' => now(),
            ]);

            return ['mode' => 'apply', 'status' => 'completed', 'run_id' => $runId, ...$summary];
        } catch (Throwable $e) {
            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 60000),
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            throw $e;
        }
    }

    public function verify(): array
    {
        $this->assertReady();
        $source = collect($this->sourceRows())->keyBy('source_key');
        $target = DB::table(self::TARGET_TABLE)
            ->where('source_system', self::SOURCE_SYSTEM)->where('source_table', self::SOURCE_TABLE)
            ->get(['id', 'source_key', 'source_hash'])->keyBy('source_key');
        $maps = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)->where('source_table', self::SOURCE_TABLE)
            ->where('target_table', self::TARGET_TABLE)->get(['source_key', 'target_id', 'source_hash'])->keyBy('source_key');

        $missing = $source->keys()->reject(fn ($k): bool => $target->has($k))->values()->all();
        $extra = $target->keys()->reject(fn ($k): bool => $source->has($k))->values()->all();
        $hashMismatch = [];
        $orphanMaps = 0;
        foreach ($source as $key => $row) {
            $t = $target->get($key);
            if ($t !== null && ! hash_equals($row['source_hash'], (string) $t->source_hash)) {
                $hashMismatch[] = (string) $key;
            }
            $m = $maps->get($key);
            if ($m === null || $t === null || (int) $m->target_id !== (int) $t->id
                || ! hash_equals((string) ($m->source_hash ?? ''), $row['source_hash'])) {
                $orphanMaps++;
            }
        }

        $healthy = $source->count() === $target->count() && $source->count() === $maps->count()
            && $missing === [] && $extra === [] && $hashMismatch === [] && $orphanMaps === 0;

        return [
            'healthy' => $healthy, 'source_rows' => $source->count(), 'materialized_rows' => $target->count(),
            'mapping_rows' => $maps->count(), 'missing_source_keys' => $missing, 'extra_source_keys' => $extra,
            'hash_mismatch_source_keys' => $hashMismatch, 'mapping_mismatches' => $orphanMaps,
        ];
    }

    public function nativePromotionPreflight(?array $rows = null): array
    {
        $this->assertReady();
        $rows ??= DB::table(self::TARGET_TABLE)
            ->where('source_system', self::SOURCE_SYSTEM)->where('source_table', self::SOURCE_TABLE)
            ->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
        $accountMaps = $this->mapping('gl_Accounts', 'enterprise_finance_accounts');
        $offsetMaps = $this->mapping('gl_AssetsSupGroups:AcquisitionOffsetAccount', 'enterprise_finance_accounts');
        $categoryMaps = $this->mapping('gl_AssetsSupGroups', 'enterprise_fixed_asset_categories');
        $counts = [];
        $samples = [];
        $ready = 0;

        foreach ($rows as $row) {
            $blockers = $this->nativeBlockers($row, $accountMaps, $offsetMaps, $categoryMaps);
            if ($blockers === []) {
                $ready++;

                continue;
            }
            foreach ($blockers as $blocker) {
                $counts[$blocker] = ($counts[$blocker] ?? 0) + 1;
            }
            if (count($samples) < 20) {
                $samples[] = ['source_key' => $row['source_key'], 'asset_code' => $row['asset_code'], 'blockers' => $blockers];
            }
        }
        ksort($counts);
        $blocked = count($rows) - $ready;

        return [
            'ready_for_native_promotion' => count($rows) > 0 && $blocked === 0,
            'rows' => count($rows), 'ready_rows' => $ready, 'blocked_rows' => $blocked,
            'blocker_counts' => $counts, 'blocked_samples' => $samples,
            'rules' => [
                'asset_doc_value_is_gross_value' => true,
                'dep_complex_value_is_accumulated_depreciation' => true,
                'expired_value_is_flag_not_money' => true,
                'fixedassets_setting_101_is_not_trusted_authority' => true,
                'asset_age_candidate_is_non_authoritative' => true,
                'acquisition_journals_must_not_be_fabricated' => true,
                'native_write_requires_explicit_account_authority' => true,
            ],
        ];
    }

    private function sourceRows(): array
    {
        $db = $this->legacySource();
        $groups = $db->table('dbo.gl_AssetsGroups')->get()->keyBy('ID');
        $subGroups = $db->table('dbo.gl_AssetsSupGroups')->get()->keyBy('ID');
        $rows = [];
        foreach ($db->table('dbo.'.self::SOURCE_TABLE)->orderBy('ID')->get() as $sourceRow) {
            $asset = (array) $sourceRow;
            $groupId = $this->int($asset['GroupId'] ?? null);
            $subId = $this->int($asset['SupGroupId'] ?? null);
            $group = $groupId === null ? [] : (array) ($groups->get($groupId) ?? []);
            $sub = $subId === null ? [] : (array) ($subGroups->get($subId) ?? []);
            $age = $this->decimal($asset['AssetAge'] ?? null);
            $rate = $this->decimal($sub['DepreciationRate'] ?? null);
            $payload = ['asset' => $asset, 'group' => $group, 'sub_group' => $sub];
            $row = [
                'source_system' => self::SOURCE_SYSTEM, 'source_table' => self::SOURCE_TABLE,
                'source_key' => (string) ($asset['ID'] ?? ''), 'source_hash' => hash('sha256', $this->json($this->canonical($payload))),
                'asset_code' => $this->str($asset['Code'] ?? null),
                'name_ar' => $this->str($asset['Name_Ar'] ?? $asset['Name_AR'] ?? null),
                'name_en' => $this->str($asset['Name_En'] ?? $asset['Name_EN'] ?? null),
                'acquisition_date' => $this->date($asset['AssetDate'] ?? null),
                'branch_id' => $this->int($asset['BranchID'] ?? null), 'group_id' => $groupId, 'sub_group_id' => $subId,
                'place_id' => $this->int($asset['PlaceId'] ?? null), 'custody_employee_id' => $this->int($asset['CustodyEmpId'] ?? null),
                'asset_age_raw' => $age, 'native_useful_life_months_candidate' => $this->lifeMonths($age, $rate),
                'gross_value' => $this->decimal($asset['AssetDocValue'] ?? null),
                'accumulated_depreciation' => $this->decimal($asset['DepComplexValue'] ?? null),
                'expired_flag' => $this->flag($asset['ExpiredValue'] ?? null), 'status_raw' => $this->str($asset['Status'] ?? null),
                'group_code' => $this->str($group['Code'] ?? null), 'group_name_ar' => $this->str($group['Name_AR'] ?? $group['Name_Ar'] ?? null),
                'group_name_en' => $this->str($group['Name_En'] ?? $group['Name_EN'] ?? null),
                'sub_group_code' => $this->str($sub['Code'] ?? null), 'sub_group_name_ar' => $this->str($sub['Name_AR'] ?? $sub['Name_Ar'] ?? null),
                'sub_group_name_en' => $this->str($sub['Name_En'] ?? $sub['Name_EN'] ?? null), 'depreciation_rate_percent' => $rate,
                'native_blockers' => null, 'source_payload' => $this->json($payload),
            ];
            if ($row['source_key'] === '') {
                throw new RuntimeException('Legacy gl_Assets row is missing ID.');
            }
            $row['native_blockers'] = $this->json($this->intrinsicBlockers($row));
            $rows[] = $row;
        }

        return $rows;
    }

    private function nativeBlockers(array $row, array $accounts, array $offsets, array $categories): array
    {
        $blockers = $this->intrinsicBlockers($row);
        $payload = json_decode((string) ($row['source_payload'] ?? '{}'), true) ?: [];
        $group = (array) ($payload['group'] ?? []);
        $sub = (array) ($payload['sub_group'] ?? []);
        foreach ([
            'asset' => $this->positiveInt($group['GlAccId'] ?? null),
            'accumulated_depreciation' => $this->positiveInt($sub['DepComplexAccId'] ?? null),
            'depreciation_expense' => $this->positiveInt($sub['ExpenseComplexAccId'] ?? null),
        ] as $role => $accountId) {
            if ($accountId === null) {
                $blockers[] = 'missing_source_'.$role.'_account';
            } elseif (! isset($accounts[(string) $accountId])) {
                $blockers[] = 'unmapped_'.$role.'_account';
            }
        }
        $subId = (string) ($row['sub_group_id'] ?? '');
        if ($subId === '' || ! isset($offsets[$subId])) {
            $blockers[] = 'missing_explicit_acquisition_offset_account_mapping';
        }
        if ($subId === '' || ! isset($categories[$subId])) {
            $blockers[] = 'missing_native_asset_category_mapping';
        }

        return array_values(array_unique($blockers));
    }

    private function intrinsicBlockers(array $row): array
    {
        $b = [];
        if (($row['asset_code'] ?? null) === null) {
            $b[] = 'missing_asset_code';
        }
        if (($row['name_ar'] ?? null) === null && ($row['name_en'] ?? null) === null) {
            $b[] = 'missing_asset_name';
        }
        if (($row['acquisition_date'] ?? null) === null) {
            $b[] = 'invalid_acquisition_date';
        }
        if (($row['gross_value'] ?? null) === null || (float) $row['gross_value'] <= 0) {
            $b[] = 'invalid_gross_value';
        }
        $b[] = 'asset_age_requires_explicit_unit_confirmation';
        if (($row['sub_group_id'] ?? null) === null) {
            $b[] = 'missing_sub_group';
        }

        return $b;
    }

    private function mapping(string $sourceTable, string $targetTable): array
    {
        return DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)->where('source_table', $sourceTable)->where('target_table', $targetTable)
            ->get(['source_key', 'target_id'])->mapWithKeys(static fn ($r): array => [(string) $r->source_key => (int) $r->target_id])->all();
    }

    private function lifeMonths(?string $age, ?string $rate): ?int
    {
        if ($age === null || $rate === null || (float) $age <= 0 || (float) $rate <= 0) {
            return null;
        }
        $years = 100 / (float) $rate;
        if (abs((float) $age - $years) <= 0.05) {
            return (int) round((float) $age * 12);
        }
        if (abs((float) $age - ($years * 12)) <= 0.5) {
            return (int) round((float) $age);
        }

        return null;
    }

    private function date(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        if (preg_match('/^\d{8}$/', $v) !== 1) {
            return null;
        }
        [$y, $m, $d] = [(int) substr($v, 0, 4), (int) substr($v, 4, 2), (int) substr($v, 6, 2)];

        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    private function int(mixed $v): ?int
    {
        return $v === null || trim((string) $v) === '' || ! is_numeric($v) ? null : (int) $v;
    }

    private function positiveInt(mixed $v): ?int
    {
        $v = $this->int($v);

        return $v !== null && $v > 0 ? $v : null;
    }

    private function decimal(mixed $v): ?string
    {
        return $v === null || trim((string) $v) === '' || ! is_numeric($v) ? null : number_format((float) $v, 3, '.', '');
    }

    private function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private function flag(mixed $v): ?bool
    {
        if (! is_numeric($v) || ! in_array((int) $v, [0, 1], true)) {
            return null;
        }

        return (int) $v === 1;
    }

    private function canonical(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map(fn ($x): mixed => $this->canonical($x), $v);
        }
        ksort($v);
        foreach ($v as $k => $x) {
            $v[$k] = $this->canonical($x);
        }

        return $v;
    }

    private function json(mixed $v): string
    {
        $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode legacy fixed-asset JSON.');
        }

        return $json;
    }

    private function assertReady(): void
    {
        foreach ([self::TARGET_TABLE, 'legacy_import_runs', 'legacy_import_mappings'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}. Run php artisan migrate first.");
            }
        }
    }

    private function legacySource(): ConnectionInterface
    {
        return DB::connection('foodex_legacy');
    }
}
