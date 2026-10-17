<?php

namespace App\Enterprise\Reporting\ReadModels;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportQuery;
use App\Services\FieldOperationsDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FieldOperationsReadModel
{
    public function read(ReportQuery $query, EntitlementScope $scope): array
    {
        if ($query->report->code !== 'field_operations.delivery_performance'
            || $query->report->readModel !== 'field_operations') {
            throw ValidationException::withMessages([
                'report' => ['Field Operations read model received an unsupported report definition.'],
            ]);
        }

        if ($scope->type !== EntitlementScope::STORE || $scope->id === null) {
            throw ValidationException::withMessages([
                'scope' => ['Field Operations reporting requires an explicit store scope.'],
            ]);
        }

        $to = $query->to ?? CarbonImmutable::today('Asia/Kuwait');
        $from = $query->from ?? $to->subDays($query->report->maxRangeDays - 1);
        $fromUtc = $from->startOfDay()->setTimezone('UTC');
        $toUtc = $to->endOfDay()->setTimezone('UTC');
        $lateCutoff = CarbonImmutable::now('UTC')->subHours(FieldOperationsDashboardService::ROUTE_SLA_HOURS);

        $raw = DB::table('order_van_assignments')
            ->join('orders', 'orders.id', '=', 'order_van_assignments.order_id')
            ->join('vans', 'vans.id', '=', 'order_van_assignments.van_id')
            ->leftJoin(
                'order_van_execution_states',
                'order_van_execution_states.order_van_assignment_id',
                '=',
                'order_van_assignments.id',
            )
            ->leftJoin('van_assignments', 'van_assignments.id', '=', 'order_van_assignments.van_assignment_id')
            ->where('orders.channel', 'b2b')
            ->where('orders.store_id', $scope->id)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->orderBy('order_van_assignments.id')
            ->limit(10000)
            ->get([
                'order_van_assignments.id',
                'order_van_assignments.assigned_at',
                'vans.id as van_id',
                'van_assignments.territory_key',
                'order_van_execution_states.status',
                'order_van_execution_states.failure_reason_code',
                'order_van_execution_states.last_transition_at',
            ])
            ->map(function (object $row) use ($lateCutoff): array {
                $status = $row->status === null ? 'assigned' : (string) $row->status;
                $assignedAt = $row->assigned_at === null
                    ? null
                    : CarbonImmutable::parse((string) $row->assigned_at, 'UTC');
                $transitionAt = $row->last_transition_at === null
                    ? null
                    : CarbonImmutable::parse((string) $row->last_transition_at, 'UTC');
                $completed = in_array($status, ['delivered', 'completed'], true);
                $failed = in_array($status, ['failed', 'returned'], true);
                $onTime = $completed
                    && $assignedAt !== null
                    && $transitionAt !== null
                    && $assignedAt->diffInMinutes($transitionAt) <= FieldOperationsDashboardService::ROUTE_SLA_HOURS * 60;
                $late = ! in_array($status, ['delivered', 'completed', 'failed', 'cancelled', 'returned'], true)
                    && $assignedAt !== null
                    && $assignedAt->lessThanOrEqualTo($lateCutoff);

                return [
                    'date' => $assignedAt?->setTimezone('Asia/Kuwait')->toDateString() ?? 'unassigned',
                    'status' => $status,
                    'van_id' => (int) $row->van_id,
                    'territory' => $row->territory_key === null ? 'unassigned' : (string) $row->territory_key,
                    'failure_reason' => $row->failure_reason_code === null ? 'none' : (string) $row->failure_reason_code,
                    'deliveries' => 1,
                    'completed' => $completed ? 1 : 0,
                    'failed' => $failed ? 1 : 0,
                    'on_time' => $onTime ? 1 : 0,
                    'late' => $late ? 1 : 0,
                ];
            });

        foreach ($query->filters as $field => $value) {
            $raw = $raw->filter(
                static fn (array $row): bool => array_key_exists($field, $row)
                    && (string) $row[$field] === (string) $value,
            );
        }

        /** @var list<string> $dimensions */
        $dimensions = $query->dimensions;
        /** @var list<string> $measures */
        $measures = $query->measures;
        /** @var array<string, array<string, int|string>> $groups */
        $groups = [];

        foreach ($raw as $row) {
            $keyParts = array_map(static fn (string $field): string => (string) ($row[$field] ?? ''), $dimensions);
            $key = implode("\x1F", $keyParts);
            if (! isset($groups[$key])) {
                $groups[$key] = [];
                foreach ($dimensions as $dimension) {
                    $dimensionValue = $row[$dimension] ?? '';
                    $groups[$key][$dimension] = is_int($dimensionValue)
                        ? $dimensionValue
                        : (string) $dimensionValue;
                }
                foreach ($measures as $measure) {
                    $groups[$key][$measure] = 0;
                }
            }
            foreach ($measures as $measure) {
                $groups[$key][$measure] = (int) $groups[$key][$measure] + (int) ($row[$measure] ?? 0);
            }
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = array_values($groups);

        foreach (array_reverse($query->sort) as $sort) {
            $field = (string) $sort['field'];
            $direction = (string) $sort['direction'];
            usort($rows, static function (array $left, array $right) use ($field, $direction): int {
                $comparison = strnatcmp(
                    (string) ($left[$field] ?? ''),
                    (string) ($right[$field] ?? ''),
                );

                return $direction === 'desc' ? -$comparison : $comparison;
            });
        }

        if ($query->sort === []) {
            usort($rows, static fn (array $left, array $right): int => strnatcmp(
                implode('|', array_map('strval', $left)),
                implode('|', array_map('strval', $right)),
            ));
        }

        $offset = ($query->page - 1) * $query->perPage;
        $pageRows = array_slice($rows, $offset, $query->perPage + 1);
        $hasMore = count($pageRows) > $query->perPage;
        $pageRows = array_slice($pageRows, 0, $query->perPage);

        return [
            'rows' => $pageRows,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'page' => $query->page,
            'per_page' => $query->perPage,
            'has_more' => $hasMore,
        ];
    }
}
