<?php

namespace Tests\Performance\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ExplainEvidenceTool
{
    /**
     * @param  array<int, mixed>  $bindings
     * @param  array<int, string>  $growingTables
     * @return array<string, mixed>
     */
    public function capture(
        string $sql,
        array $bindings = [],
        array $growingTables = [],
        ?string $connectionName = null,
    ): array {
        if (! preg_match('/^\\s*(select|with)\\b/i', $sql)) {
            throw new InvalidArgumentException('EXPLAIN evidence only accepts read-only SELECT/WITH SQL.');
        }

        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        $statement = $driver === 'sqlite'
            ? 'EXPLAIN QUERY PLAN '.$sql
            : 'EXPLAIN '.$sql;

        $rows = array_map(
            static fn (object|array $row): array => (array) $row,
            $connection->select($statement, $bindings),
        );

        $normalizedSql = QueryCountProbe::normalizeSql($sql);
        $fullScanTables = $this->fullScanTables($driver, $rows, $growingTables);

        return [
            'driver' => $driver,
            'fingerprint' => hash('sha256', $normalizedSql),
            'sql' => $normalizedSql,
            'plan' => $rows,
            'growing_tables' => array_values(array_unique($growingTables)),
            'full_scan_tables' => $fullScanTables,
            'investigation_required' => $fullScanTables !== [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $growingTables
     * @return array<int, string>
     */
    private function fullScanTables(
        string $driver,
        array $rows,
        array $growingTables,
    ): array {
        $growing = array_fill_keys(
            array_map(static fn (string $table): string => strtolower($table), $growingTables),
            true,
        );
        $fullScans = [];

        foreach ($rows as $row) {
            if ($driver === 'sqlite') {
                $detail = (string) ($row['detail'] ?? '');

                if (! preg_match('/\\bSCAN\\s+["\\[]?([A-Za-z0-9_]+)/i', $detail, $matches)) {
                    continue;
                }

                $table = strtolower($matches[1]);
                $upperDetail = strtoupper($detail);
                $usesIndex = str_contains($upperDetail, 'USING INDEX')
                    || str_contains($upperDetail, 'USING COVERING INDEX');

                if (! $usesIndex && isset($growing[$table])) {
                    $fullScans[$table] = true;
                }

                continue;
            }

            $table = strtolower((string) ($row['table'] ?? ''));
            $accessType = strtoupper((string) ($row['type'] ?? ''));

            if ($table !== '' && $accessType === 'ALL' && isset($growing[$table])) {
                $fullScans[$table] = true;
            }
        }

        return array_keys($fullScans);
    }
}
