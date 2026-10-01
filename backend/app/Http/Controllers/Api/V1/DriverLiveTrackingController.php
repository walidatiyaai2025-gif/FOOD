<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DriverLiveTrackingController extends Controller
{
    public function feed(Request $request, OperationalTenantScope $tenantScope): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $request->validate([
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['online', 'stale', 'offline'])],
            'driver_id' => ['nullable', 'integer', 'min:1'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'since' => ['nullable', 'date'],
        ]);

        $channels = isset($data['channel'])
            ? [(string) $data['channel']]
            : ['b2b', 'b2c'];

        /** @var array<string, list<int>> $allowedStoresByChannel */
        $allowedStoresByChannel = [];

        foreach ($channels as $channel) {
            $storeIds = $tenantScope->allowedStoreIds(
                $user,
                'drivers.tracking.view',
                $channel,
            );

            if ($storeIds !== []) {
                $allowedStoresByChannel[$channel] = $storeIds;
            }
        }

        abort_if($allowedStoresByChannel === [], 403);

        if (isset($data['store_id'])) {
            $requestedStoreId = (int) $data['store_id'];
            $matched = false;

            foreach ($allowedStoresByChannel as $channel => $storeIds) {
                if (in_array($requestedStoreId, $storeIds, true)) {
                    $allowedStoresByChannel[$channel] = [$requestedStoreId];
                    $matched = true;

                    continue;
                }

                unset($allowedStoresByChannel[$channel]);
            }

            abort_unless($matched, 404);
        }

        $now = CarbonImmutable::now();
        $onlineCutoff = $now->subSeconds(45);
        $staleCutoff = $now->subMinutes(3);

        $rows = DB::table('driver_current_locations as locations')
            ->join('drivers', 'drivers.id', '=', 'locations.driver_id')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->leftJoin('driver_assignments', function ($join): void {
                $join
                    ->on('driver_assignments.id', '=', 'locations.active_assignment_id')
                    ->on('driver_assignments.driver_id', '=', 'locations.driver_id')
                    ->on('driver_assignments.store_id', '=', 'locations.store_id')
                    ->on('driver_assignments.assignment_type', '=', 'locations.channel')
                    ->whereNull('driver_assignments.completed_at')
                    ->whereIn(
                        'driver_assignments.status',
                        ['accepted', 'picked_up', 'out_for_delivery'],
                    );
            })
            ->leftJoin('orders', 'orders.id', '=', 'driver_assignments.order_id')
            ->where(function ($query) use ($allowedStoresByChannel): void {
                foreach ($allowedStoresByChannel as $channel => $storeIds) {
                    $query->orWhere(function ($scope) use ($channel, $storeIds): void {
                        $scope
                            ->where('locations.channel', $channel)
                            ->whereIn('locations.store_id', $storeIds);
                    });
                }
            })
            ->when(
                isset($data['driver_id']),
                fn ($query) => $query->where('locations.driver_id', (int) $data['driver_id']),
            )
            ->when(
                isset($data['order_id']),
                fn ($query) => $query->where('orders.id', (int) $data['order_id']),
            )
            ->when(
                isset($data['since']),
                fn ($query) => $query->where('locations.received_at', '>', CarbonImmutable::parse((string) $data['since'])),
            )
            ->orderByDesc('locations.received_at')
            ->get([
                'locations.driver_id',
                'locations.store_id',
                'locations.channel',
                'locations.latitude',
                'locations.longitude',
                'locations.accuracy',
                'locations.speed',
                'locations.heading',
                'locations.captured_at',
                'locations.received_at',
                'locations.app_version',
                'locations.is_mocked',
                'driver_assignments.id as active_assignment_id',
                'driver_assignments.status as active_assignment_status',
                'users.name as driver_name',
                'orders.id as order_id',
                'orders.order_number',
            ])
            ->map(function (object $row) use ($onlineCutoff, $staleCutoff): array {
                $receivedAt = CarbonImmutable::parse((string) $row->received_at);
                $status = $receivedAt->greaterThanOrEqualTo($onlineCutoff)
                    ? 'online'
                    : ($receivedAt->greaterThanOrEqualTo($staleCutoff) ? 'stale' : 'offline');

                return [
                    'driver_id' => (int) $row->driver_id,
                    'driver_name' => (string) $row->driver_name,
                    'store_id' => (int) $row->store_id,
                    'channel' => (string) $row->channel,
                    'status' => $status,
                    'latitude' => (float) $row->latitude,
                    'longitude' => (float) $row->longitude,
                    'accuracy' => $row->accuracy === null ? null : (float) $row->accuracy,
                    'speed' => $row->speed === null ? null : (float) $row->speed,
                    'heading' => $row->heading === null ? null : (float) $row->heading,
                    'captured_at' => CarbonImmutable::parse((string) $row->captured_at)->toISOString(),
                    'received_at' => $receivedAt->toISOString(),
                    'app_version' => $row->app_version,
                    'is_mocked' => $row->is_mocked === null ? null : (bool) $row->is_mocked,
                    'active_assignment_id' => $row->active_assignment_id === null ? null : (int) $row->active_assignment_id,
                    'active_assignment_status' => $row->active_assignment_status === null
                        ? null
                        : (string) $row->active_assignment_status,
                    'tracking_required' => $row->active_assignment_id !== null,
                    'order' => $row->order_id === null ? null : [
                        'id' => (int) $row->order_id,
                        'number' => (string) $row->order_number,
                    ],
                ];
            })
            ->values();

        if (isset($data['status'])) {
            $rows = $rows->where('status', (string) $data['status'])->values();
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'generated_at' => $now->toISOString(),
                'freshness' => [
                    'online_seconds' => 45,
                    'stale_seconds' => 180,
                ],
            ],
        ]);
    }
}
