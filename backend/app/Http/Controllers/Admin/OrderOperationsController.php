<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchPushNotification;
use App\Models\DriverAssignment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderStatusHistory;
use App\Models\OrderVanAssignment;
use App\Models\User;
use App\Services\AdminOrderManagementService;
use App\Services\AuditLogger;
use App\Services\DashboardOperationalNotifier;
use App\Services\DriverDeliveryEvidenceService;
use App\Services\OperationalLookupService;
use App\Services\OperationalTenantScope;
use App\Services\OrderDeliveryAddressSnapshotService;
use App\Services\OrderManualDispatchService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class OrderOperationsController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly OperationalTenantScope $scope,
        private readonly DriverDeliveryEvidenceService $deliveryEvidence,
        private readonly OperationalLookupService $lookups,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);

        $statusOptions = $this->orderStatusOptions();
        $statusCodes = array_values(array_unique(array_map(
            static fn (array $option): string => (string) $option['code'],
            $statusOptions,
        )));

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'order_number' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', Rule::in($statusCodes)],
            'driver_id' => ['nullable', 'integer', 'min:1'],
            'dispatch_status' => ['nullable', 'string', Rule::in(['awaiting_dispatch', 'assigned'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', Rule::in(['all', 'b2b', 'b2c'])],
            'order' => ['nullable', 'integer', 'min:1'],
        ]);

        $operationalChannel = isset($data['channel'])
            ? (string) $data['channel']
            : $this->defaultOperationalChannel($actor);
        $data['channel'] = $operationalChannel;

        $scopes = $this->operationalScopes($actor, $operationalChannel, 'orders.view');
        $dispatchScopes = $this->operationalScopes($actor, $operationalChannel, 'orders.dispatch');
        $storeIds = array_values(array_unique(array_merge(
            $scopes['b2b'],
            $scopes['b2c'],
        )));
        abort_if($storeIds === [], 403);

        $selectedStoreId = isset($data['store_id']) ? (int) $data['store_id'] : null;
        if ($selectedStoreId !== null && collect($storeIds)->containsStrict($selectedStoreId) === false) {
            abort(404);
        }

        $orderQuery = Order::query()
            ->where(fn ($query) => $this->applyOperationalScopes($query, $scopes))
            ->when($selectedStoreId !== null, fn ($query) => $query->where('store_id', $selectedStoreId))
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
            );

        if (isset($data['dispatch_status'])) {
            $dispatchStatus = (string) $data['dispatch_status'];
            $orderQuery->whereExists(function ($dispatch) use ($dispatchStatus): void {
                $dispatch->selectRaw('1')
                    ->from('order_dispatch_states')
                    ->whereColumn('order_dispatch_states.order_id', 'orders.id')
                    ->where('order_dispatch_states.status', $dispatchStatus);
            });
        }

        $statusCounts = (clone $orderQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        $selectedStatus = isset($data['status']) ? (string) $data['status'] : null;
        $orders = (clone $orderQuery)
            ->when($selectedStatus !== null, fn ($query) => $query->where('status', $selectedStatus))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $statusLabels = collect($statusOptions)
            ->mapWithKeys(static fn (array $option): array => [
                (string) $option['code'] => (string) $option['label'],
            ])
            ->all();
        $activeStatusCodes = $this->activeOrderStatusCodes();
        $statusTabs = collect($statusOptions)
            ->map(static fn (array $option): array => [
                ...$option,
                'count' => (int) ($statusCounts[(string) $option['code']] ?? 0),
            ])
            ->values()
            ->all();

        $rows = collect($orders->items())
            ->map(fn (Order $order): array => $this->row($order, $statusLabels, $activeStatusCodes, $dispatchScopes))
            ->all();

        $stores = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $drivers = DB::table('drivers')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->where(function ($query) use ($scopes): void {
                foreach (['b2b', 'b2c'] as $channel) {
                    $ids = $scopes[$channel];
                    if ($ids === []) {
                        continue;
                    }
                    $query->orWhere(function ($scope) use ($channel, $ids): void {
                        $scope->where('drivers.driver_type', $channel)
                            ->whereIn('drivers.store_id', $ids);
                    });
                }
            })
            ->where('drivers.is_active', true)
            ->orderBy('users.name')
            ->get([
                'drivers.id',
                'drivers.store_id',
                'drivers.driver_type',
                'drivers.user_id',
                'users.name',
            ]);

        $vans = DB::table('vans')
            ->join('van_assignments', 'van_assignments.van_id', '=', 'vans.id')
            ->where('vans.status', 'active')
            ->where('van_assignments.status', 'active')
            ->where('van_assignments.effective_from', '<=', now())
            ->where(fn ($query) => $query
                ->whereNull('van_assignments.effective_until')
                ->orWhere('van_assignments.effective_until', '>', now()))
            ->orderBy('vans.code')
            ->distinct()
            ->get(['vans.id', 'vans.code', 'vans.plate_number']);

        $detail = null;
        if (isset($data['order'])) {
            $detailOrder = Order::query()
                ->whereKey((int) $data['order'])
                ->where(fn ($query) => $this->applyOperationalScopes($query, $scopes))
                ->firstOrFail();
            $detail = $this->detail($actor, $detailOrder, $statusLabels, $activeStatusCodes, $dispatchScopes);
        }

        return view('admin.order-operations', [
            'actor' => $actor,
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'order_operations',
            'orders' => $orders,
            'rows' => $rows,
            'stores' => $stores,
            'drivers' => $drivers,
            'vans' => $vans,
            'detail' => $detail,
            'statuses' => array_map(
                static fn (array $option): string => (string) $option['code'],
                $statusOptions,
            ),
            'statusOptions' => $statusOptions,
            'statusTabs' => $statusTabs,
            'statusTotal' => array_sum($statusCounts),
            'selectedStatus' => $selectedStatus,
            'newOrderWizard' => $this->newOrderWizard($actor),
            'isAr' => app()->getLocale() === 'ar',
        ]);
    }

    public function quoteNewOrder(Request $request, AdminOrderManagementService $orders): JsonResponse
    {
        $actor = $this->actor($request);
        [$channel, $storeId] = $this->newOrderScope($request, $actor);

        return response()->json([
            'data' => $orders->quote($request, $channel, $storeId),
        ]);
    }

    public function storeNewOrder(Request $request, AdminOrderManagementService $orders): RedirectResponse
    {
        $actor = $this->actor($request);
        [$channel, $storeId] = $this->newOrderScope($request, $actor);
        $order = $orders->create($request, $actor, $channel, $storeId);

        return redirect()
            ->route('admin.operations.orders.index', [
                'channel' => $channel,
                'store_id' => $storeId,
                'status' => 'pending',
                'order' => $order->getKey(),
            ])
            ->with('status', $this->msg(
                'تم إنشاء الطلب '.$order->order_number.'.',
                'Order '.$order->order_number.' created.',
            ));
    }

    /** @return array{0:string,1:int} */
    private function newOrderScope(Request $request, User $actor): array
    {
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['required', 'integer', 'min:1'],
        ]);

        $channel = (string) $data['channel'];
        $storeId = (int) $data['store_id'];

        if ($channel === 'b2b') {
            $principalStoreId = app(WholesalePrincipal::class)->storeId();
            abort_unless($storeId === $principalStoreId, 404);
        }

        $this->scope->assertStore($actor, $storeId, 'orders.manage', $channel);

        return [$channel, $storeId];
    }

    /** @return array{channels:list<array<string,mixed>>,payment_methods:list<array{code:string,label:string}>} */
    private function newOrderWizard(User $actor): array
    {
        $channels = [];
        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        $b2bStoreIds = array_values(array_filter(
            $this->scope->allowedStoreIds($actor, 'orders.manage', 'b2b'),
            static fn (int $storeId): bool => $storeId === $principalStoreId,
        ));

        if ($b2bStoreIds !== []) {
            $channels[] = [
                'code' => 'b2b',
                'label' => $this->msg('الجملة', 'Wholesale'),
                'stores' => [$this->newOrderStoreData('b2b', $principalStoreId)],
            ];
        }

        $b2cStoreIds = $this->scope->allowedStoreIds($actor, 'orders.manage', 'b2c');
        if ($b2cStoreIds !== []) {
            $channels[] = [
                'code' => 'b2c',
                'label' => $this->msg('التجزئة', 'Retail'),
                'stores' => array_map(
                    fn (int $storeId): array => $this->newOrderStoreData('b2c', $storeId),
                    $b2cStoreIds,
                ),
            ];
        }

        $configuredPaymentMethods = array_values(array_unique(array_map(
            static fn ($method): string => (string) $method,
            (array) config('checkout.payment_methods', ['cash_on_delivery']),
        )));
        $lookupPaymentMethods = $this->lookups
            ->active(OperationalLookupService::PAYMENT_METHOD)
            ->keyBy(static fn (object $row): string => (string) $row->code);
        $paymentMethods = [];

        foreach ($configuredPaymentMethods as $code) {
            if ($lookupPaymentMethods->isNotEmpty() && $lookupPaymentMethods->has($code) === false) {
                continue;
            }

            $lookup = $lookupPaymentMethods->get($code);
            $label = $lookup === null
                ? $code
                : (app()->getLocale() === 'ar' ? (string) $lookup->label_ar : (string) $lookup->label_en);

            $paymentMethods[] = ['code' => $code, 'label' => $label ?: $code];
        }

        if ($paymentMethods === []) {
            $paymentMethods = array_map(
                static fn (string $code): array => ['code' => $code, 'label' => $code],
                $configuredPaymentMethods,
            );
        }

        return [
            'channels' => $channels,
            'payment_methods' => $paymentMethods,
        ];
    }

    /** @return array<string,mixed> */
    private function newOrderStoreData(string $channel, int $storeId): array
    {
        $store = DB::table('stores')
            ->where('id', $storeId)
            ->where('is_active', true)
            ->first(['id', 'code', 'name']);
        abort_unless($store !== null, 404);

        if ($channel === 'b2b') {
            $customers = DB::table('b2b_customers')
                ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
                ->where('b2b_accounts.status', 'active')
                ->orderBy('b2b_accounts.company_name')
                ->orderBy('b2b_customers.name')
                ->get([
                    'b2b_customers.id',
                    'b2b_customers.name',
                    'b2b_accounts.company_name',
                ])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'name' => trim(($row->company_name ? $row->company_name.' · ' : '').$row->name),
                ])
                ->values()
                ->all();

            $products = DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->join('store_products', function ($join): void {
                    $join->on('store_products.product_id', '=', 'products.id')
                        ->on('store_products.store_id', '=', 'catalogs.store_id');
                })
                ->where('catalogs.store_id', $storeId)
                ->where('catalogs.channel', 'b2b')
                ->where('catalogs.is_migration_quarantine', false)
                ->where('catalogs.is_active', true)
                ->where('products.is_active', true)
                ->where('store_products.is_active', true)
                ->orderBy('products.name')
                ->get(['products.id', 'products.sku', 'products.name', 'store_products.price'])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                    'price' => $row->price === null ? null : (float) $row->price,
                ])
                ->values()
                ->all();

            $warehouses = DB::table('warehouses')
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                ])
                ->values()
                ->all();

            $customerIds = collect($customers)->pluck('id')->all();
            $addresses = $this->newOrderAddresses('b2b_customer_id', $customerIds);

            return [
                'id' => (int) $store->id,
                'code' => (string) $store->code,
                'name' => (string) $store->name,
                'customers' => $customers,
                'products' => $products,
                'warehouses' => $warehouses,
                'addresses' => $addresses,
            ];
        }

        $customers = DB::table('b2c_customers')
            ->where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
            ])
            ->values()
            ->all();

        $products = DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('store_products.store_id', $storeId)
            ->whereColumn('catalogs.store_id', 'store_products.store_id')
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_migration_quarantine', false)
            ->where('catalogs.is_active', true)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->whereNotNull('store_products.price')
            ->orderBy('products.name')
            ->get(['products.id', 'products.sku', 'products.name', 'store_products.price'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'price' => (float) $row->price,
            ])
            ->values()
            ->all();

        $customerIds = collect($customers)->pluck('id')->all();

        return [
            'id' => (int) $store->id,
            'code' => (string) $store->code,
            'name' => (string) $store->name,
            'customers' => $customers,
            'products' => $products,
            'warehouses' => [],
            'addresses' => $this->newOrderAddresses('b2c_customer_id', $customerIds),
        ];
    }

    /** @param list<int> $customerIds
     * @return list<array{id:int,customer_id:int,label:string}>
     */
    private function newOrderAddresses(string $customerColumn, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return DB::table('addresses')
            ->whereIn($customerColumn, $customerIds)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['id', $customerColumn, 'label', 'line1', 'city', 'area', 'block', 'street', 'building'])
            ->map(static function (object $row) use ($customerColumn): array {
                $parts = array_values(array_filter([
                    trim((string) ($row->area ?? '')),
                    trim((string) ($row->block ?? '')),
                    trim((string) ($row->street ?? '')),
                    trim((string) ($row->building ?? '')),
                    trim((string) ($row->line1 ?? '')),
                    trim((string) ($row->city ?? '')),
                ], static fn (string $part): bool => $part !== ''));
                $label = trim((string) ($row->label ?? ''));

                if ($parts !== []) {
                    $label = trim(($label !== '' ? $label.' · ' : '').implode(', ', array_unique($parts)));
                }

                return [
                    'id' => (int) $row->id,
                    'customer_id' => (int) $row->{$customerColumn},
                    'label' => $label !== '' ? $label : 'Address #'.$row->id,
                ];
            })
            ->values()
            ->all();
    }

    public function remindDriver(
        Request $request,
        int $order,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.manage');

        $assignment = DriverAssignment::query()
            ->where('order_id', $model->getKey())
            ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
            ->latest('id')
            ->first();

        abort_unless($assignment instanceof DriverAssignment, 409, 'Order has no active driver assignment.');

        $driver = DB::table('drivers')
            ->where('id', $assignment->driver_id)
            ->first(['id', 'user_id']);
        abort_unless($driver !== null && $driver->user_id !== null, 409, 'Assigned driver has no active app user.');

        $notification = Notification::query()->create([
            'channel' => 'both',
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
                'assignment_id' => (int) $assignment->getKey(),
                'access_revoked' => false,
                'route' => 'assignment',
            ],
        ]);

        DispatchPushNotification::dispatch((int) $notification->getKey())->afterCommit();

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
        $request->validate([
            'status' => ['required', 'string', Rule::in($this->activeOrderStatusCodes())],
        ]);
        $request->merge(['store_id' => (int) $model->store_id]);

        $orders->transition($request, $order, $audit, $notifier);

        return back()->with('status', $this->msg('تم تحديث حالة الطلب.', 'Order status updated.'));
    }

    public function dispatch(
        Request $request,
        int $order,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $notifier,
        OrderManualDispatchService $dispatch,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.view');
        $this->scope->assertStore(
            $actor,
            (int) $model->store_id,
            'orders.dispatch',
            (string) $model->channel,
        );

        $data = $request->validate([
            'assignee_type' => ['required', Rule::in(['driver', 'van'])],
            'assignee_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $reason = trim((string) $data['reason']);

        if ((string) $data['assignee_type'] === 'driver') {
            $driverId = (int) $data['assignee_id'];
            $active = DriverAssignment::query()
                ->where('order_id', $model->getKey())
                ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                ->latest('id')
                ->first();

            if ($active instanceof DriverAssignment && (int) $active->driver_id === $driverId) {
                $dispatch->assignDriver($model, $actor, $active, $reason);
            } else {
                $request->merge([
                    'driver_id' => $driverId,
                    'order_id' => (int) $model->getKey(),
                    'store_id' => (int) $model->store_id,
                    'replace_existing' => true,
                    'customer_service_override' => true,
                ]);
                $deliveries->assign($request, $audit, $notifier);

                $assignment = DriverAssignment::query()
                    ->where('order_id', $model->getKey())
                    ->where('driver_id', $driverId)
                    ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                    ->latest('id')
                    ->first();
                abort_unless($assignment instanceof DriverAssignment, 409, 'Driver assignment was not persisted.');
                $dispatch->assignDriver($model, $actor, $assignment, $reason);
            }

            return back()->with('status', $this->msg(
                'تم توجيه الطلب إلى السائق.',
                'Order dispatched to driver.',
            ));
        }

        $vanId = (int) $data['assignee_id'];
        $vanAssignable = DB::table('vans')
            ->join('van_assignments', 'van_assignments.van_id', '=', 'vans.id')
            ->where('vans.id', $vanId)
            ->where('vans.status', 'active')
            ->where('van_assignments.status', 'active')
            ->where('van_assignments.effective_from', '<=', now())
            ->where(fn ($query) => $query
                ->whereNull('van_assignments.effective_until')
                ->orWhere('van_assignments.effective_until', '>', now()))
            ->exists();
        abort_unless($vanAssignable, 422, 'Selected Van has no effective active assignment.');

        $activeDriver = DriverAssignment::query()
            ->where('order_id', $model->getKey())
            ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
            ->latest('id')
            ->first();

        if ($activeDriver instanceof DriverAssignment) {
            $request->merge([
                'store_id' => (int) $model->store_id,
                'reason' => $reason,
            ]);
            $deliveries->unassign($request, $order, $audit, $notifier);
        }

        $dispatch->assignVan($model, $actor, $vanId, $reason);

        return back()->with('status', $this->msg(
            'تم توجيه الطلب إلى الفان.',
            'Order dispatched to Van.',
        ));
    }

    public function clearDispatch(
        Request $request,
        int $order,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $notifier,
        OrderManualDispatchService $dispatch,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.view');
        $this->scope->assertStore(
            $actor,
            (int) $model->store_id,
            'orders.dispatch',
            (string) $model->channel,
        );
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $reason = trim((string) $data['reason']);

        $activeDriver = DriverAssignment::query()
            ->where('order_id', $model->getKey())
            ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
            ->latest('id')
            ->first();

        if ($activeDriver instanceof DriverAssignment) {
            $request->merge([
                'store_id' => (int) $model->store_id,
                'reason' => $reason,
            ]);
            $deliveries->unassign($request, $order, $audit, $notifier);
        }

        $dispatch->clear($model, $actor, $reason);

        return back()->with('status', $this->msg(
            'تم إرجاع الطلب إلى قائمة التوجيه المعلق.',
            'Order returned to the pending dispatch queue.',
        ));
    }

    public function reassign(
        Request $request,
        int $order,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $notifier,
        OrderManualDispatchService $dispatch,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.manage');
        $this->scope->assertStore(
            $actor,
            (int) $model->store_id,
            'orders.dispatch',
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

        $assignment = DriverAssignment::query()
            ->where('order_id', $model->getKey())
            ->where('driver_id', (int) $request->input('driver_id'))
            ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
            ->latest('id')
            ->first();
        if ($assignment instanceof DriverAssignment) {
            $dispatch->assignDriver(
                $model,
                $actor,
                $assignment,
                trim((string) $request->input('reason', 'legacy_driver_assignment')),
            );
        }

        return back()->with('status', $this->msg('تم تعيين السائق للطلب.', 'Driver assigned to order.'));
    }

    public function unassign(
        Request $request,
        int $order,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $notifier,
        OrderManualDispatchService $dispatch,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.manage');
        $this->scope->assertStore(
            $actor,
            (int) $model->store_id,
            'orders.dispatch',
            (string) $model->channel,
        );

        $request->merge(['store_id' => (int) $model->store_id]);
        $deliveries->unassign($request, $order, $audit, $notifier);
        $dispatch->clear(
            $model,
            $actor,
            trim((string) $request->input('reason', 'legacy_driver_unassign')),
        );

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

    /**
     * @param  array<string,string>  $statusLabels
     * @param  list<string>  $activeStatusCodes
     * @return array<string,mixed>
     */
    private function row(
        Order $order,
        array $statusLabels,
        array $activeStatusCodes,
        array $dispatchScopes,
    ): array {

        $assignment = DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
            ->latest('id')
            ->first();

        $driver = $assignment === null
            ? null
            : DB::table('drivers')
                ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
                ->where('drivers.id', $assignment->driver_id)
                ->first(['drivers.id', 'drivers.user_id', 'users.name']);

        $dispatch = OrderDispatchState::query()
            ->where('order_id', $order->getKey())
            ->first();
        $dispatchAssignee = null;
        if ($dispatch instanceof OrderDispatchState && $dispatch->current_assignee_type === 'driver') {
            $dispatchDriver = DB::table('drivers')
                ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
                ->where('drivers.id', (int) $dispatch->current_assignee_id)
                ->first(['users.name']);
            $dispatchAssignee = trim((string) ($dispatchDriver?->name ?? ''))
                ?: $this->msg('سائق بدون اسم', 'Unnamed driver');
        } elseif ($dispatch instanceof OrderDispatchState && $dispatch->current_assignee_type === 'van') {
            $dispatchVan = DB::table('vans')
                ->where('id', (int) $dispatch->current_assignee_id)
                ->first(['code', 'plate_number']);
            if ($dispatchVan !== null) {
                $dispatchAssignee = trim((string) $dispatchVan->code)
                    .($dispatchVan->plate_number ? ' · '.$dispatchVan->plate_number : '');
            }
        }

        $dispatchTerritory = $dispatch?->service_territory_id === null
            ? null
            : DB::table('service_territories')
                ->where('id', (int) $dispatch->service_territory_id)
                ->first(['code', 'name_ar', 'name_en']);

        $customerName = strtolower((string) $order->channel) === 'b2b'
            ? DB::table('b2b_customers')->where('id', $order->b2b_customer_id)->value('name')
            : DB::table('b2c_customers')->where('id', $order->b2c_customer_id)->value('name');

        $payment = DB::table('payments')
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first(['status', 'provider']);

        $store = DB::table('stores')->where('id', $order->store_id)->first(['name', 'code']);
        $storeLabel = $store === null
            ? '#'.$order->store_id
            : ($store->name ?? $store->code ?? '#'.$order->store_id);
        $sourceNote = DB::table('order_status_history')
            ->where('order_id', $order->getKey())
            ->whereNull('from_status')
            ->where('to_status', 'pending')
            ->oldest('id')
            ->value('note');
        $source = $sourceNote === 'dashboard_order_created'
            ? 'dashboard'
            : ($sourceNote === 'checkout' ? 'customer_checkout' : 'legacy');

        $status = (string) $order->status;
        $availableStatuses = array_values(array_filter(
            OrderController::allowedTransitions($status),
            static fn (string $candidate): bool => in_array($candidate, $activeStatusCodes, true),
        ));

        return [
            'id' => (int) $order->getKey(),
            'number' => (string) $order->order_number,
            'channel' => strtolower((string) $order->channel),
            'store_id' => (int) $order->store_id,
            'store' => $storeLabel,
            'source' => $source,
            'customer' => $customerName ?? '-',
            'status' => $status,
            'status_label' => $statusLabels[$status] ?? $status,
            'available_statuses' => array_map(
                static fn (string $candidate): array => [
                    'code' => $candidate,
                    'label' => $statusLabels[$candidate] ?? $candidate,
                ],
                $availableStatuses,
            ),
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
            'dispatch_status' => $dispatch?->status ?? 'unrouted',
            'dispatch_source' => $dispatch?->routing_source,
            'dispatch_reason' => $dispatch?->routing_reason,
            'dispatch_assignee_type' => $dispatch?->current_assignee_type,
            'dispatch_assignee' => $dispatchAssignee,
            'dispatch_territory' => $dispatchTerritory === null
                ? null
                : ((app()->getLocale() === 'ar' ? $dispatchTerritory->name_ar : $dispatchTerritory->name_en)
                    ?: $dispatchTerritory->code),
            'can_dispatch' => in_array(
                (int) $order->store_id,
                $dispatchScopes[strtolower((string) $order->channel)] ?? [],
                true,
            ),
        ];
    }

    /**
     * @param  array<string,string>  $statusLabels
     * @param  list<string>  $activeStatusCodes
     * @return array<string,mixed>
     */
    private function detail(
        User $actor,
        Order $order,
        array $statusLabels,
        array $activeStatusCodes,
        array $dispatchScopes,
    ): array {
        $row = $this->row($order, $statusLabels, $activeStatusCodes, $dispatchScopes);
        $deliveryAddress = app(OrderDeliveryAddressSnapshotService::class)->payload($order);

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

        $vanAssignments = OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->get()
            ->map(function (OrderVanAssignment $assignment): array {
                $van = DB::table('vans')
                    ->where('id', $assignment->van_id)
                    ->first(['code', 'plate_number']);

                return [
                    'van' => $van === null
                        ? $this->msg('فان غير متاح', 'Unavailable Van')
                        : trim((string) $van->code).($van->plate_number ? ' · '.$van->plate_number : ''),
                    'status' => (string) $assignment->status,
                    'source' => (string) $assignment->source,
                    'reason' => $assignment->reason,
                    'assigned_at' => $assignment->assigned_at,
                    'ended_at' => $assignment->ended_at,
                ];
            })
            ->all();

        return [
            ...$row,
            'delivery_address' => $deliveryAddress,
            'history' => $history,
            'assignments' => $assignments,
            'van_assignments' => $vanAssignments,
            'delivery_evidence' => $this->deliveryEvidence->order($actor, $order),
        ];
    }

    /**
     * @return list<array{code:string,label:string,label_ar:string,label_en:string,active:bool}>
     */
    private function orderStatusOptions(): array
    {
        $locale = app()->getLocale();
        $options = DB::table('operational_lookups')
            ->where('type', OperationalLookupService::ORDER_STATUS)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['code', 'label_ar', 'label_en', 'is_active'])
            ->map(static function (object $row) use ($locale): array {
                $code = (string) $row->code;
                $labelAr = trim((string) $row->label_ar);
                $labelEn = trim((string) $row->label_en);

                return [
                    'code' => $code,
                    'label' => ($locale === 'ar' ? $labelAr : $labelEn) ?: $code,
                    'label_ar' => $labelAr ?: $code,
                    'label_en' => $labelEn ?: $code,
                    'active' => (bool) $row->is_active,
                ];
            })
            ->values()
            ->all();

        $known = array_fill_keys(array_map(
            static fn (array $option): string => (string) $option['code'],
            $options,
        ), true);

        foreach (OrderController::statusCodes() as $code) {
            if (isset($known[$code])) {
                continue;
            }

            $options[] = [
                'code' => $code,
                'label' => $code,
                'label_ar' => $code,
                'label_en' => $code,
                'active' => true,
            ];
        }

        return $options;
    }

    /** @return list<string> */
    private function activeOrderStatusCodes(): array
    {
        $codes = $this->lookups->activeCodes(OperationalLookupService::ORDER_STATUS);
        if ($codes === []) {
            return OrderController::statusCodes();
        }

        return array_values(array_intersect(OrderController::statusCodes(), $codes));
    }

    /** @return array{b2b:array<int,int>,b2c:array<int,int>} */
    private function operationalScopes(
        User $actor,
        string $channel,
        string $permission = 'orders.view',
    ): array {
        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        $b2b = in_array($channel, ['all', 'b2b'], true)
            ? array_values(array_filter(
                $this->scope->allowedStoreIds($actor, $permission, 'b2b'),
                static fn (int $storeId): bool => $storeId === $principalStoreId,
            ))
            : [];
        $b2c = in_array($channel, ['all', 'b2c'], true)
            ? $this->scope->allowedStoreIds($actor, $permission, 'b2c')
            : [];

        return ['b2b' => $b2b, 'b2c' => $b2c];
    }

    private function applyOperationalScopes($query, array $scopes): void
    {
        $query->where(function ($scopeQuery) use ($scopes): void {
            foreach (['b2b', 'b2c'] as $channel) {
                $ids = $scopes[$channel];
                if ($ids === []) {
                    continue;
                }
                $scopeQuery->orWhere(function ($channelQuery) use ($channel, $ids): void {
                    $channelQuery->where('channel', $channel)
                        ->whereIn('store_id', $ids);
                });
            }
        });
    }

    private function defaultOperationalChannel(User $actor): string
    {
        $b2b = $this->scope->allowedStoreIds($actor, 'orders.view', 'b2b');
        $b2c = $this->scope->allowedStoreIds($actor, 'orders.view', 'b2c');

        if ($b2b !== []) {
            return 'b2b';
        }

        abort_if($b2c === [], 403);

        return 'b2c';
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
