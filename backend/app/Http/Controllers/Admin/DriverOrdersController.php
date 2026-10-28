<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\DeliverySettlementService;
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

        // Only posted, invoice-allocated collections belonging to this driver
        // count towards the driver total. Never attribute an order's other
        // collectors or online payments to this driver.
        $collections = DB::table('collection_allocations as alloc')
            ->join('collection_transactions as ct', 'ct.id', '=', 'alloc.collection_transaction_id')
            ->join('collection_accounts as account', 'account.id', '=', 'ct.collection_account_id')
            ->join('invoices as invoice', 'invoice.id', '=', 'alloc.invoice_id')
            ->join('orders as collected_order', 'collected_order.id', '=', 'invoice.order_id')
            ->where('account.actor_type', 'driver')
            ->where('account.actor_id', $driver)
            ->where('account.store_id', $driverRow->store_id)
            ->where('ct.type', 'collection')
            ->where('ct.status', 'posted')
            ->whereColumn('alloc.currency', 'collected_order.currency')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('collection_transactions as reversal')
                    ->whereColumn('reversal.reversal_of_id', 'ct.id')
                    ->where('reversal.status', 'posted');
            })
            ->select('invoice.order_id')
            ->selectRaw('SUM(alloc.amount) as collected_amount')
            ->groupBy('invoice.order_id');

        $baseOrders = DB::table('driver_assignments')
            ->join('orders', 'orders.id', '=', 'driver_assignments.order_id')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->leftJoinSub($collections, 'driver_collections', fn ($join) => $join->on('driver_collections.order_id', '=', 'orders.id'))
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
            );

        $collectionTotals = (clone $baseOrders)
            ->select('orders.currency')
            ->selectRaw('SUM(COALESCE(driver_collections.collected_amount, 0)) as collected_amount')
            ->groupBy('orders.currency')
            ->orderBy('orders.currency')
            ->get();

        $orders = $baseOrders
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
                'orders.currency',
                'orders.grand_total',
                'driver_collections.collected_amount',
            ])
            ->paginate(10)
            ->withQueryString();

        $ids = array_map(static fn (object $row): int => (int) $row->id, $orders->items());
        $orderModels = Order::query()->whereIn('id', $ids)->get()->keyBy('id');
        $invoices = Invoice::query()->whereIn('order_id', $ids)
            ->whereIn('status', ['issued', 'reissued'])
            ->orderByDesc('revision')->orderByDesc('id')
            ->get()->unique('order_id')->keyBy('order_id');
        $payments = Payment::query()->whereIn('order_id', $ids)
            ->orderByDesc('id')->get()->unique('order_id')->keyBy('order_id');
        $settlements = app(DeliverySettlementService::class);
        foreach ($orders->items() as $row) {
            $order = $orderModels->get((int) $row->id);
            if (! $order instanceof Order) {
                continue;
            }
            $amounts = $settlements->summarize(
                $order,
                $invoices->get((int) $row->id),
                $payments->get((int) $row->id),
            );
            $row->remaining_amount = (float) $amounts['remaining_amount'];
            $row->paid_amount = (float) $amounts['paid_amount'];
            $row->payment_state = (string) $amounts['payment_state'];
        }

        return view('admin._driver-orders-popup', [
            'driver' => $driverRow,
            'orders' => $orders,
            'collectionTotals' => $collectionTotals,
            'channel' => $channel,
            'storeId' => $storeId,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ]);
    }
}
