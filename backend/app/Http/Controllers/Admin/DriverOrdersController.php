<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DriverOrdersController extends Controller
{
    public function __construct(private readonly OperationalTenantScope $operationalScope) {}

    public function index(Request $request, int $driver): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $channel = (string) $validated['channel'];
        $storeIds = array_values(array_unique(array_merge(
            $this->operationalScope->allowedStoreIds($user, 'drivers.'.$channel.'.view', $channel),
            $this->operationalScope->allowedStoreIds($user, 'drivers.'.$channel.'.manage', $channel),
        )));
        abort_if($storeIds === [], 403);

        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        if ($storeId !== null) {
            $allowedStoreLookup = array_fill_keys($storeIds, true);
            abort_unless(isset($allowedStoreLookup[$storeId]), 404);
            $storeIds = [$storeId];
        }

        $driverRow = DB::table('drivers')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->where('drivers.id', $driver)
            ->where('drivers.driver_type', $channel)
            ->whereIn('drivers.store_id', $storeIds)
            ->first([
                'drivers.id',
                'drivers.store_id',
                'users.name',
                'users.phone_e164',
            ]);
        abort_unless($driverRow !== null, 404);

        $orders = DB::table('driver_assignments')
            ->join('orders', 'orders.id', '=', 'driver_assignments.order_id')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->where('driver_assignments.driver_id', $driver)
            ->where('orders.channel', $channel)
            ->whereIn('orders.store_id', $storeIds)
            ->whereRaw(
                'driver_assignments.id = (select max(latest_assignment.id) from driver_assignments as latest_assignment where latest_assignment.order_id = driver_assignments.order_id and latest_assignment.driver_id = driver_assignments.driver_id)'
            )
            ->when(
                isset($validated['from']),
                fn ($query) => $query->whereDate('orders.created_at', '>=', (string) $validated['from']),
            )
            ->when(
                isset($validated['to']),
                fn ($query) => $query->whereDate('orders.created_at', '<=', (string) $validated['to']),
            )
            ->orderByDesc('orders.created_at')
            ->orderByDesc('orders.id')
            ->select([
                'orders.id',
                'orders.order_number',
                'orders.status as order_status',
                'orders.created_at',
                'stores.name as store_name',
                'driver_assignments.status as assignment_status',
                'driver_assignments.assigned_at',
            ])
            ->paginate(10)
            ->withQueryString();

        return view('admin._driver-orders-popup', [
            'driver' => $driverRow,
            'orders' => $orders,
            'channel' => $channel,
            'storeId' => $storeId,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ]);
    }
}
