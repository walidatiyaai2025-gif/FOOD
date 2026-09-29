<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Controller;
use App\Models\DriverAssignment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DashboardOperationalNotifier;
use App\Services\OperationalTenantScope;
use App\Services\PushDeliveryService;
use App\Support\AdminNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class OrderOperationsController extends Controller
{
    private const STATUSES = [
        'pending',
        'confirmed',
        'preparing',
        'ready',
        'out_for_delivery',
        'failed',
        'delivered',
        'cancelled',
    ];

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly OperationalTenantScope $scope,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $storeIds = $this->scope->allowedStoreIds($actor, 'orders.view');
        abort_if($storeIds === [], 403);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'order_number' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'driver_id' => ['nullable', 'integer', 'min:1'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', Rule::in(['b2b', 'b2c'])],
            'order' => ['nullable', 'integer', 'min:1'],
        ]);

        $selectedStoreId = isset($data['store_id']) ? (int) $data['store_id'] : null;
        if ($selectedStoreId !== null) {
            abort_unless(in_array($selectedStoreId, $storeIds, true), 404);
        }

        $orders = Order::query()
            ->whereIn('store_id', $storeIds)
            ->when($selectedStoreId !== null, fn ($query) => $query->where('store_id', $selectedStoreId))
            ->when(isset($data['channel']), fn ($query) => $query->where('channel', $data['channel']))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['from']), fn ($query) => $query->whereDate('created_at', '>=', $data['from']))
            ->when(isset($data['to']), fn ($query) => $query->whereDate('created_at', '<=', $data['to']))
            ->when(
                isset($data['order_number']) && trim((string) $data['order_number']) !== '',
                fn ($query) => $query->where('order_number', 'like', '%'.trim((string) $data['order_number']).'%'),
            )
            ->when(
                isset($data['driver_id']),
                fn ($query) => $query->whereExists(function ($assignment) use ($data): void {
                    $assignment->selectRaw('1')
                        ->from('driver_assignments')
                        ->whereColumn('driver_assignments.order_id', 'orders.id')
                        ->where('driver_assignments.driver_id', (int) $data['driver_id'])
                        ->whereNotIn('driver_assignments.status', ['unassigned', 'reassigned', 'cancelled']);
                }),
            )
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $rows = collect($orders->items())
            ->map(fn (Order $order): array => $this->row($order))
            ->all();

        $stores = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $drivers = DB::table('drivers')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->whereIn('drivers.store_id', $storeIds)
            ->where('drivers.is_active', true)
            ->orderBy('users.name')
            ->get([
                'drivers.id',
                'drivers.store_id',
                'drivers.driver_type',
                'drivers.user_id',
                'users.name',
            ]);

        $detail = null;
        if (isset($data['order'])) {
            $detailOrder = Order::query()
                ->whereKey((int) $data['order'])
                ->whereIn('store_id', $storeIds)
                ->firstOrFail();
            $detail = $this->detail($detailOrder);
        }

        return view('admin.order-operations', [
            'actor' => $actor,
            'navigation' => $this->navigation->sidebar($actor),
            'orders' => $orders,
            'rows' => $rows,
            'stores' => $stores,
            'drivers' => $drivers,
            'detail' => $detail,
            'statuses' => self::STATUSES,
            'isAr' => app()->getLocale() === 'ar',
        ]);
    }

    public function remindDriver(
        Request $request,
        int $order,
        PushDeliveryService $push,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.manage');

        $assignment = DriverAssignment::query()
            ->where('order_id', $model->getKey())
            ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered'])
            ->latest('id')
            ->first();

        abort_unless($assignment instanceof DriverAssignment, 409, 'Order has no active driver assignment.');

        $driver = DB::table('drivers')
            ->where('id', $assignment->driver_id)
            ->first(['id', 'user_id']);
        abort_unless($driver !== null && $driver->user_id !== null, 409, 'Assigned driver has no active app user.');

        $notification = Notification::query()->create([
            'channel' => 'push',
            'type' => 'order.driver_reminder',
            'title' => 'تذكير بالطلب '.$model->order_number,
            'body' => 'يوجد طلب يحتاج متابعتك الآن.',
            'title_ar' => 'تذكير بالطلب '.$model->order_number,
            'title_en' => 'Order reminder '.$model->order_number,
            'body_ar' => 'يوجد طلب يحتاج متابعتك الآن.',
            'body_en' => 'This order needs your attention now.',
            'audience' => 'driver',
            'app' => 'driver',
            'target_channel' => (string) $model->channel,
            'user_id' => (int) $driver->user_id,
            'store_id' => (int) $model->store_id,
            'status' => 'published',
            'published_at' => now(),
            'data' => [
                'order_id' => (int) $model->getKey(),
                'order_number' => (string) $model->order_number,
                'channel' => (string) $model->channel,
                'driver_id' => (int) $driver->id,
                'route' => '/deliveries',
            ],
        ]);

        $push->dispatchNotification($notification);
        $audit->record(
            'operations.order.driver_reminder_sent',
            $actor,
            $model,
            null,
            [
                'driver_id' => (int) $driver->id,
                'notification_id' => (int) $notification->getKey(),
            ],
            $request,
        );

        return back()->with('status', $this->msg('تم إرسال التذكير للسائق.', 'Driver reminder sent.'));
    }

    public function transition(
        Request $request,
        int $order,
        OrderController $orders,
        AuditLogger $audit,
        DashboardOperationalNotifier $notifier,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.manage');
        $request->merge(['store_id' => (int) $model->store_id]);

        $orders->transition($request, $order, $audit, $notifier);

        return back()->with('status', $this->msg('تم تحديث حالة الطلب.', 'Order status updated.'));
    }

    public function reassign(
        Request $request,
        int $order,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $notifier,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.manage');
        $this->scope->assertStore(
            $actor,
            (int) $model->store_id,
            'drivers.'.strtolower((string) $model->channel).'.manage',
            (string) $model->channel,
        );

        $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
        ]);
        $request->merge([
            'order_id' => (int) $model->getKey(),
            'store_id' => (int) $model->store_id,
            'replace_existing' => true,
        ]);

        $deliveries->assign($request, $audit, $notifier);

        return back()->with('status', $this->msg('تم تعيين السائق للطلب.', 'Driver assigned to order.'));
    }

    public function unassign(
        Request $request,
        int $order,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $notifier,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.manage');
        $this->scope->assertStore(
            $actor,
            (int) $model->store_id,
            'drivers.'.strtolower((string) $model->channel).'.manage',
            (string) $model->channel,
        );

        $request->merge(['store_id' => (int) $model->store_id]);
        $deliveries->unassign($request, $order, $audit, $notifier);

        return back()->with('status', $this->msg('تم سحب الطلب من السائق.', 'Order unassigned from driver.'));
    }

    private function managedOrder(User $actor, int $order, string $permission): Order
    {
        $model = Order::query()->findOrFail($order);
        $this->scope->assertStore(
            $actor,
            (int) $model->store_id,
            $permission,
            (string) $model->channel,
        );

        return $model;
    }

    /** @return array<string,mixed> */
    private function row(Order $order): array
    {
        $assignment = DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled'])
            ->latest('id')
            ->first();

        $driver = $assignment === null
            ? null
            : DB::table('drivers')
                ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
                ->where('drivers.id', $assignment->driver_id)
                ->first(['drivers.id', 'drivers.user_id', 'users.name']);

        $customerName = strtolower((string) $order->channel) === 'b2b'
            ? DB::table('b2b_customers')->where('id', $order->b2b_customer_id)->value('name')
            : DB::table('b2c_customers')->where('id', $order->b2c_customer_id)->value('name');

        $payment = DB::table('payments')
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first(['status', 'provider']);

        $store = DB::table('stores')->where('id', $order->store_id)->first(['name', 'code']);

        return [
            'id' => (int) $order->getKey(),
            'number' => (string) $order->order_number,
            'channel' => strtolower((string) $order->channel),
            'store_id' => (int) $order->store_id,
            'store' => $store?->name ?? $store?->code ?? '#'.$order->store_id,
            'customer' => $customerName ?? '-',
            'status' => (string) $order->status,
            'total' => (float) $order->grand_total,
            'currency' => (string) $order->currency,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            'payment_status' => $payment?->status,
            'payment_provider' => $payment?->provider,
            'assignment_id' => $assignment?->getKey(),
            'assignment_status' => $assignment?->status,
            'driver_id' => $driver?->id,
            'driver' => $driver?->name,
        ];
    }

    /** @return array<string,mixed> */
    private function detail(Order $order): array
    {
        $row = $this->row($order);

        $history = OrderStatusHistory::query()
            ->where('order_id', $order->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(static fn (OrderStatusHistory $entry): array => [
                'from' => $entry->from_status,
                'to' => $entry->to_status,
                'note' => $entry->note,
                'created_at' => $entry->created_at,
            ])
            ->all();

        $assignments = DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->get()
            ->map(function (DriverAssignment $assignment): array {
                $name = DB::table('drivers')
                    ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.id', $assignment->driver_id)
                    ->value('users.name');

                return [
                    'driver' => $name ?? '#'.$assignment->driver_id,
                    'status' => (string) $assignment->status,
                    'assigned_at' => $assignment->assigned_at,
                    'completed_at' => $assignment->completed_at,
                ];
            })
            ->all();

        return [
            ...$row,
            'history' => $history,
            'assignments' => $assignments,
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
