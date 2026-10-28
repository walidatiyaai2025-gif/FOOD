<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchPushNotification;
use App\Models\AuditLog;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\DriverAssignment;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderStatusHistory;
use App\Models\OrderVanAssignment;
use App\Models\Payment;
use App\Models\User;
use App\Services\AdminOrderManagementService;
use App\Services\AuditLogger;
use App\Services\B2bAccountLedgerService;
use App\Services\CustomerServiceTeamManager;
use App\Services\DashboardOperationalNotifier;
use App\Services\DeliverySettlementService;
use App\Services\DriverDeliveryEvidenceService;
use App\Services\InvoiceService;
use App\Services\OperationalLookupService;
use App\Services\OperationalTenantScope;
use App\Services\OrderDeliveryAddressSnapshotService;
use App\Services\OrderLifecycleNotificationService;
use App\Services\OrderManualDispatchService;
use App\Services\OrderOperationsRowContext;
use App\Services\SalesOrderOperationsDashboardService;
use App\Services\VanDeliveryEvidenceService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use App\Support\DisplayNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderOperationsController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly OperationalTenantScope $scope,
        private readonly B2bAccountLedgerService $accountLedger,
        private readonly DriverDeliveryEvidenceService $deliveryEvidence,
        private readonly VanDeliveryEvidenceService $vanDeliveryEvidence,
        private readonly OperationalLookupService $lookups,
        private readonly SalesOrderOperationsDashboardService $salesDashboard,
        private readonly CustomerServiceTeamManager $customerServiceTeam,
    ) {}

    public function b2bIndex(Request $request): View
    {
        $request->merge(['channel' => 'b2b']);

        return $this->index($request);
    }

    public function b2cIndex(Request $request): View
    {
        $request->merge(['channel' => 'b2c']);

        return $this->index($request);
    }

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $availableChannels = $this->availableOperationalChannels($actor, 'orders.view');

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
            'exception' => ['nullable', 'string', Rule::in(SalesOrderOperationsDashboardService::EXCEPTIONS)],
            'action_required' => ['nullable', 'boolean'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', Rule::in(['all', 'b2b', 'b2c'])],
            'order' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $customerServiceProfile = $this->navigation->usesCustomerServiceDashboardProfile($actor);
        $actionRequiredExplicit = array_key_exists('action_required', $data);
        $selectedActionRequired = $actionRequiredExplicit
            ? (bool) $data['action_required']
            : $customerServiceProfile;

        $operationalChannel = isset($data['channel'])
            ? (string) $data['channel']
            : $this->defaultOperationalChannel($actor);
        $this->assertOperationalChannelAllowed($availableChannels, $operationalChannel);
        $data['channel'] = $operationalChannel;
        $perPage = (int) ($data['per_page'] ?? 25);

        $scopes = $this->operationalScopes($actor, $operationalChannel, 'orders.view');
        $manageScopes = $this->operationalScopes($actor, $operationalChannel, 'orders.manage');
        $dispatchScopes = $this->operationalScopes($actor, $operationalChannel, 'orders.dispatch');
        $financeScopes = $this->operationalScopes($actor, $operationalChannel, 'finance.view');
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
            ->when(
                isset($data['from']),
                fn ($query) => $query->where(
                    'created_at',
                    '>=',
                    CarbonImmutable::parse((string) $data['from'], 'Asia/Kuwait')->startOfDay()->utc(),
                ),
            )
            ->when(
                isset($data['to']),
                fn ($query) => $query->where(
                    'created_at',
                    '<=',
                    CarbonImmutable::parse((string) $data['to'], 'Asia/Kuwait')->endOfDay()->utc(),
                ),
            )
            ->when(
                isset($data['order_number']) && trim((string) $data['order_number']) !== '',
                function ($query) use ($data): void {
                    $search = trim((string) $data['order_number']);
                    $displayId = DisplayNumber::referenceId($search);

                    $query->where(function ($nested) use ($search, $displayId): void {
                        if ($displayId !== null) {
                            $nested->where('id', $displayId)
                                ->orWhere('order_number', 'like', '%'.$search.'%');

                            return;
                        }

                        $nested->where('order_number', 'like', '%'.$search.'%');
                    });
                },
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

        $selectedException = isset($data['exception']) ? (string) $data['exception'] : null;
        if ($selectedException !== null) {
            $this->salesDashboard->applyExceptionFilter($orderQuery, $selectedException);
        }

        $dashboardVisible = ! (
            isset($data['order_number'])
            || isset($data['status'])
            || isset($data['driver_id'])
            || isset($data['dispatch_status'])
            || isset($data['exception'])
            || isset($data['order'])
        );

        $statusBaseQuery = clone $orderQuery;
        $summaryLoader = function () use ($statusBaseQuery): array {
            $actionRequiredCount = (clone $statusBaseQuery)
                ->whereNotIn('status', ['delivered', 'completed', 'cancelled'])
                ->whereExists(function ($dispatch): void {
                    $dispatch->selectRaw('1')
                        ->from('order_dispatch_states')
                        ->whereColumn('order_dispatch_states.order_id', 'orders.id')
                        ->where('order_dispatch_states.status', 'awaiting_dispatch');
                })
                ->count();

            $statusCounts = (clone $statusBaseQuery)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(static fn ($count): int => (int) $count)
                ->all();

            return [
                'action_required_count' => $actionRequiredCount,
                'status_counts' => $statusCounts,
            ];
        };

        if ($dashboardVisible && ! app()->environment('testing')) {
            $normalizedSummaryScopes = [
                'b2b' => array_values(array_unique(array_map('intval', $scopes['b2b']))),
                'b2c' => array_values(array_unique(array_map('intval', $scopes['b2c']))),
            ];
            sort($normalizedSummaryScopes['b2b']);
            sort($normalizedSummaryScopes['b2c']);
            $summaryCacheKey = 'admin.order-operations.summary.v2.'.sha1(json_encode([
                'scopes' => $normalizedSummaryScopes,
                'store_id' => $selectedStoreId,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
            ], JSON_THROW_ON_ERROR));

            $summary = Cache::remember($summaryCacheKey, now()->addSeconds(10), $summaryLoader);
        } else {
            $summary = $summaryLoader();
        }

        $actionRequiredCount = (int) $summary['action_required_count'];
        $statusCounts = (array) $summary['status_counts'];

        if ($selectedActionRequired && ! $actionRequiredExplicit && $actionRequiredCount === 0) {
            $selectedActionRequired = false;
        }

        if ($selectedActionRequired) {
            $orderQuery
                ->whereNotIn('status', ['delivered', 'completed', 'cancelled'])
                ->whereExists(function ($dispatch): void {
                    $dispatch->selectRaw('1')
                        ->from('order_dispatch_states')
                        ->whereColumn('order_dispatch_states.order_id', 'orders.id')
                        ->where('order_dispatch_states.status', 'awaiting_dispatch');
                });
        }

        $selectedStatus = isset($data['status']) ? (string) $data['status'] : null;
        $orders = (clone $orderQuery)
            ->when($selectedStatus !== null, fn ($query) => $query->where('status', $selectedStatus))
            ->select([
                'id',
                'store_id',
                'b2b_customer_id',
                'b2c_customer_id',
                'order_number',
                'channel',
                'status',
                'currency',
                'grand_total',
                'payment_method',
                'commercial_locked_at',
                'created_at',
                'updated_at',
            ])
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $statusLabels = collect($statusOptions)
            ->mapWithKeys(static fn (array $option): array => [
                (string) $option['code'] => (string) $option['label'],
            ])
            ->all();
        $activeStatusCodes = $this->activeOrderStatusCodes();
        $rowContext = $this->listRowContext($orders->items());
        $statusTabs = collect($statusOptions)
            ->map(static fn (array $option): array => [
                ...$option,
                'count' => (int) ($statusCounts[(string) $option['code']] ?? 0),
            ])
            ->values()
            ->all();

        $rows = collect($orders->items())
            ->map(fn (Order $order): array => $this->row(
                $order,
                $statusLabels,
                $activeStatusCodes,
                $dispatchScopes,
                $manageScopes,
                $financeScopes,
                $rowContext,
            ))
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

        $dashboard = null;
        if ($dashboardVisible) {
            $dashboardLoader = fn (): array => $this->salesDashboard->build(
                $scopes,
                $selectedStoreId,
                isset($data['from']) ? (string) $data['from'] : null,
                isset($data['to']) ? (string) $data['to'] : null,
            );

            if (app()->environment('testing')) {
                $dashboard = $dashboardLoader();
            } else {
                $normalizedScopes = [
                    'b2b' => array_values(array_unique(array_map('intval', $scopes['b2b']))),
                    'b2c' => array_values(array_unique(array_map('intval', $scopes['b2c']))),
                ];
                sort($normalizedScopes['b2b']);
                sort($normalizedScopes['b2c']);
                $dashboardCacheKey = 'admin.order-operations.dashboard.v3.'.sha1(json_encode([
                    'scopes' => $normalizedScopes,
                    'store_id' => $selectedStoreId,
                    'from' => $data['from'] ?? null,
                    'to' => $data['to'] ?? null,
                ], JSON_THROW_ON_ERROR));

                $dashboard = Cache::remember(
                    $dashboardCacheKey,
                    now()->addSeconds(20),
                    $dashboardLoader,
                );
            }
        }

        $dashboardBaseQuery = [];
        $dashboardKpis = [];
        $dashboardMoney = static fn ($value): string => '—';
        $dailyMax = 1;
        $statusMax = 1;

        if ($dashboardVisible) {
            $dashboardBaseQuery = [
                'channel' => $operationalChannel,
                'from' => $dashboard['period']['from'],
                'to' => $dashboard['period']['to'],
                'action_required' => 0,
            ];
            if ($selectedStoreId !== null) {
                $dashboardBaseQuery['store_id'] = $selectedStoreId;
            }

            $dashboardCurrency = $dashboard['currency'];
            $dashboardMoney = static fn ($value): string => $value === null || $dashboardCurrency === null
                ? __('order_operations.dashboard.multiple_currencies')
                : DisplayNumber::money((float) $value, $dashboardCurrency).' '.$dashboardCurrency;

            $dashboardKpis = [
                [
                    'key' => 'orders',
                    'icon' => 'orders',
                    'tone' => 'green',
                    'label' => __('order_operations.dashboard.kpis.orders'),
                    'value' => number_format((int) $dashboard['kpis']['orders']),
                    'raw' => (float) $dashboard['kpis']['orders'],
                    'decimals' => 0,
                    'drilldown_url' => route('admin.operations.orders.index', $dashboardBaseQuery),
                ],
                [
                    'key' => 'recognized_revenue',
                    'icon' => 'revenue',
                    'tone' => 'blue',
                    'label' => __('order_operations.dashboard.kpis.recognized_revenue'),
                    'value' => $dashboardMoney($dashboard['kpis']['recognized_revenue']),
                    'raw' => $dashboard['kpis']['recognized_revenue'],
                    'decimals' => 2,
                    'drilldown_url' => route('admin.reports.index', array_merge($dashboardBaseQuery, ['report' => 'orders'])),
                ],
                [
                    'key' => 'aov',
                    'icon' => 'reports',
                    'tone' => 'violet',
                    'label' => __('order_operations.dashboard.kpis.aov'),
                    'value' => $dashboardMoney($dashboard['kpis']['average_order_value']),
                    'raw' => $dashboard['kpis']['average_order_value'],
                    'decimals' => 2,
                    'drilldown_url' => route('admin.reports.index', array_merge($dashboardBaseQuery, ['report' => 'orders'])),
                ],
                [
                    'key' => 'open_orders',
                    'icon' => 'orders',
                    'tone' => 'orange',
                    'label' => __('order_operations.dashboard.kpis.open_orders'),
                    'value' => number_format((int) $dashboard['kpis']['open_orders']),
                    'raw' => (float) $dashboard['kpis']['open_orders'],
                    'decimals' => 0,
                    'drilldown_url' => route('admin.operations.orders.index', $dashboardBaseQuery),
                ],
                [
                    'key' => 'delayed_orders',
                    'icon' => 'bell',
                    'tone' => 'red',
                    'label' => __('order_operations.dashboard.kpis.delayed_orders'),
                    'value' => number_format((int) $dashboard['kpis']['delayed_orders']),
                    'raw' => (float) $dashboard['kpis']['delayed_orders'],
                    'decimals' => 0,
                    'status' => ['label' => __('order_operations.dashboard.exceptions.delayed'), 'state' => $dashboard['kpis']['delayed_orders'] > 0 ? 'warning' : 'success'],
                    'drilldown_url' => route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['exception' => 'delayed'])),
                ],
                [
                    'key' => 'awaiting_dispatch',
                    'icon' => 'delivery',
                    'tone' => 'amber',
                    'label' => __('order_operations.dashboard.kpis.awaiting_dispatch'),
                    'value' => number_format((int) $dashboard['kpis']['awaiting_dispatch']),
                    'raw' => (float) $dashboard['kpis']['awaiting_dispatch'],
                    'decimals' => 0,
                    'status' => ['label' => __('order_operations.dashboard.exceptions.awaiting_dispatch'), 'state' => $dashboard['kpis']['awaiting_dispatch'] > 0 ? 'warning' : 'success'],
                    'drilldown_url' => route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['exception' => 'awaiting_dispatch'])),
                ],
                [
                    'key' => 'fulfillment_minutes',
                    'icon' => 'delivery',
                    'tone' => 'blue',
                    'label' => __('order_operations.dashboard.kpis.fulfillment_minutes'),
                    'value' => $dashboard['kpis']['average_fulfillment_minutes'] === null ? '—' : number_format((float) $dashboard['kpis']['average_fulfillment_minutes'], 1),
                    'raw' => $dashboard['kpis']['average_fulfillment_minutes'],
                    'decimals' => 1,
                    'drilldown_url' => route('admin.reports.index', array_merge($dashboardBaseQuery, ['report' => 'operations'])),
                ],
                [
                    'key' => 'sla',
                    'icon' => 'inspector',
                    'tone' => 'green',
                    'label' => __('order_operations.dashboard.kpis.sla'),
                    'value' => $dashboard['kpis']['open_within_sla_percent'] === null ? '—' : number_format((float) $dashboard['kpis']['open_within_sla_percent'], 1).'%',
                    'raw' => $dashboard['kpis']['open_within_sla_percent'],
                    'decimals' => 1,
                    'status' => ['label' => __('order_operations.dashboard.sla_help'), 'state' => ($dashboard['kpis']['open_within_sla_percent'] ?? 100) >= 90 ? 'success' : 'warning'],
                    'drilldown_url' => route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['exception' => 'delayed'])),
                ],
            ];

            $dailyMax = max(1, (int) collect($dashboard['daily'])->max('orders'));
            $statusMax = max(1, (int) collect($dashboard['status_breakdown'])->max('orders'));
        }

        $canAssignCustomerService = $this->customerServiceTeam->canManage($actor);
        $customerServiceAgents = $canAssignCustomerService
            ? $this->customerServiceTeam->members($actor)->where('is_active', true)->values()
            : collect();

        $detail = null;
        if (isset($data['order'])) {
            $detailOrder = Order::query()
                ->whereKey((int) $data['order'])
                ->where(fn ($query) => $this->applyOperationalScopes($query, $scopes))
                ->firstOrFail();
            $detail = $this->detail(
                $actor,
                $detailOrder,
                $statusLabels,
                $activeStatusCodes,
                $dispatchScopes,
                $manageScopes,
                $financeScopes,
            );
        }

        return view('admin.order-operations', [
            'actor' => $actor,
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'order_operations',
            'orders' => $orders,
            'rows' => $rows,
            'stores' => $stores,
            'storeId' => $selectedStoreId ?? (count($storeIds) === 1 ? $storeIds[0] : null),
            'storeIds' => $selectedStoreId !== null ? [$selectedStoreId] : $storeIds,
            'availableStores' => $stores
                ->map(static fn (object $store): array => [
                    'id' => (int) $store->id,
                    'name' => (string) $store->name,
                    'code' => (string) $store->code,
                ])
                ->all(),
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
            'actionRequiredCount' => $actionRequiredCount,
            'selectedActionRequired' => $selectedActionRequired,
            'customerServiceProfile' => $customerServiceProfile,
            'selectedStatus' => $selectedStatus,
            'selectedException' => $selectedException,
            'exceptionOptions' => SalesOrderOperationsDashboardService::EXCEPTIONS,
            'dashboard' => $dashboard,
            'dashboardVisible' => $dashboardVisible,
            'dashboardBaseQuery' => $dashboardBaseQuery,
            'dashboardKpis' => $dashboardKpis,
            'dashboardMoney' => $dashboardMoney,
            'dailyMax' => $dailyMax,
            'statusMax' => $statusMax,
            'selectedStoreId' => $selectedStoreId,
            'selectedChannel' => $operationalChannel,
            'availableChannels' => $availableChannels,
            'newOrderWizard' => $this->newOrderWizard($actor, $operationalChannel),
            'canAssignCustomerService' => $canAssignCustomerService,
            'customerServiceAgents' => $customerServiceAgents,
            'isAr' => app()->getLocale() === 'ar',
        ]);
    }

    public function popup(
        Request $request,
        int $order,
        string $kind,
    ): View {
        abort_unless(in_array($kind, ['details', 'invoice', 'timeline'], true), 404);

        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.view');
        $channel = strtolower((string) $model->channel);
        $statusOptions = $this->orderStatusOptions();
        $statusLabels = collect($statusOptions)
            ->mapWithKeys(static fn (array $option): array => [
                (string) $option['code'] => (string) $option['label'],
            ])
            ->all();

        $detail = $this->detail(
            $actor,
            $model,
            $statusLabels,
            $this->activeOrderStatusCodes(),
            $this->operationalScopes($actor, $channel, 'orders.dispatch'),
            $this->operationalScopes($actor, $channel, 'orders.manage'),
            $this->operationalScopes($actor, $channel, 'finance.view'),
        );

        if ($kind === 'invoice') {
            abort_unless(
                ! empty($detail['invoice_id']) && ! empty($detail['can_view_invoice']),
                404,
            );
        }

        return view('admin._order-operation-popup', [
            'kind' => $kind,
            'detail' => $detail,
            'timeline' => $kind === 'timeline'
                ? $this->orderTimeline($model, $detail)
                : [],
            'isAr' => app()->getLocale() === 'ar',
        ]);
    }

    public function vanProof(
        Request $request,
        int $assignment,
        int $proof,
    ): StreamedResponse {
        $actor = $this->actor($request);
        $event = $this->vanDeliveryEvidence->proof($actor, $assignment, $proof);
        $path = trim((string) $event->proof_path);
        abort_unless($path !== '' && Storage::disk('public')->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = 'van-delivery-proof-'.$event->getKey()
            .($extension === '' ? '' : '.'.$extension);

        return Storage::disk('public')->response($path, $filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function newOrderFeed(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $base = Notification::query()
            ->where('user_id', $actor->getKey())
            ->where('app', 'dashboard')
            ->where('type', 'order.created')
            ->where('status', 'published');

        if (! array_key_exists('after', $validated)) {
            $cursor = (int) ((clone $base)->max('id') ?? 0);

            return response()->json([
                'data' => [],
                'meta' => ['cursor' => $cursor],
            ])->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $after = (int) $validated['after'];
        $notifications = (clone $base)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(50)
            ->get();

        $cursor = $after;
        $items = [];

        foreach ($notifications as $notification) {
            $cursor = max($cursor, (int) $notification->getKey());
            if (data_get($notification->data, 'source') !== 'customer_app') {
                continue;
            }

            $channel = strtolower((string) data_get($notification->data, 'channel', ''));
            $storeId = (int) data_get($notification->data, 'store_id', 0);
            $orderId = (int) data_get($notification->data, 'order_id', 0);

            if (! in_array($channel, ['b2b', 'b2c'], true) || $storeId <= 0 || $orderId <= 0) {
                continue;
            }

            if (! in_array(
                $storeId,
                $this->scope->allowedStoreIds($actor, 'orders.view', $channel),
                true,
            )) {
                continue;
            }

            $order = Order::query()
                ->whereKey($orderId)
                ->where('store_id', $storeId)
                ->where('channel', $channel)
                ->first();

            if (! $order instanceof Order) {
                continue;
            }

            $customerName = trim((string) data_get($notification->data, 'customer_name', ''));
            $customerPhone = trim((string) data_get($notification->data, 'customer_phone', ''));

            if ($customerName === '') {
                $customer = $channel === 'b2b'
                    ? DB::table('b2b_customers')->where('id', $order->b2b_customer_id)->first(['name', 'phone'])
                    : DB::table('b2c_customers')->where('id', $order->b2c_customer_id)->first(['name', 'phone']);

                $customerName = trim((string) data_get($customer, 'name', ''));
                $customerPhone = trim((string) data_get($customer, 'phone', ''));
            }

            $storeName = DB::table('stores')->where('id', $storeId)->value('name');

            $items[] = [
                'notification_id' => (int) $notification->getKey(),
                'order_id' => (int) $order->getKey(),
                'order_number' => (string) $order->order_number,
                'channel' => $channel,
                'store_id' => $storeId,
                'store_name' => $storeName === null ? null : (string) $storeName,
                'customer_name' => $customerName === '' ? null : $customerName,
                'customer_phone' => $customerPhone === '' ? null : $customerPhone,
                'grand_total' => (float) $order->grand_total,
                'currency' => (string) $order->currency,
                'created_at' => $order->created_at?->toIso8601String(),
                'url' => route('admin.operations.orders.index', [
                    'channel' => $channel,
                    'order' => $order->getKey(),
                ], false),
            ];
        }

        return response()->json([
            'data' => $items,
            'meta' => ['cursor' => $cursor],
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
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
        $idempotency = $request->validate([
            '_order_create_context' => ['required', 'string', Rule::in(['central'])],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100', 'regex:/\\A[A-Za-z0-9._:-]+\\z/'],
        ]);

        $actor = $this->actor($request);
        [$channel, $storeId] = $this->newOrderScope($request, $actor);
        $order = $orders->create(
            $request,
            $actor,
            $channel,
            $storeId,
            'dashboard',
            'admin',
            trim((string) $idempotency['idempotency_key']),
        );

        return redirect()
            ->route('admin.operations.orders.index', [
                'channel' => $channel,
                'store_id' => $storeId,
                'status' => 'pending',
                'order' => $order->getKey(),
            ])
            ->with('status', $this->msg(
                'تم إنشاء الطلب '.DisplayNumber::reference($order->getKey()).'.',
                'Order '.DisplayNumber::reference($order->getKey()).' created.',
            ));
    }

    public function update(
        Request $request,
        int $order,
        AdminOrderManagementService $orders,
        OrderLifecycleNotificationService $notifications,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $model = $this->managedOrder($actor, $order, 'orders.manage');
        $orders->update($request, $actor, $model);
        $notifications->customerOrderChanged($model->fresh(), 'details_updated', [
            'source' => 'dashboard',
        ]);

        return redirect()
            ->route('admin.operations.orders.index', [
                'channel' => strtolower((string) $model->channel),
                'store_id' => (int) $model->store_id,
                'order' => (int) $model->getKey(),
            ])
            ->with('status', $this->msg('تم تحديث الطلب.', 'Order updated.'));
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

        abort_unless(
            in_array($storeId, $this->orderCreateStoreIds($actor, $channel), true),
            404,
        );

        return [$channel, $storeId];
    }

    /** @return array{channels:list<array<string,mixed>>,payment_methods:list<array{code:string,label:string}>} */
    private function newOrderWizard(User $actor, string $channel): array
    {
        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 404);

        $channels = [];
        if ($channel === 'b2b') {
            $principalStoreId = app(WholesalePrincipal::class)->storeId();
            $b2bStoreIds = array_values(array_filter(
                $this->orderCreateStoreIds($actor, 'b2b'),
                static fn (int $storeId): bool => $storeId === $principalStoreId,
            ));

            if ($b2bStoreIds !== []) {
                $channels[] = [
                    'code' => 'b2b',
                    'label' => $this->msg('الجملة', 'Wholesale'),
                    'stores' => [$this->newOrderStoreData('b2b', $principalStoreId)],
                ];
            }
        } else {
            $b2cStoreIds = $this->orderCreateStoreIds($actor, 'b2c');
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
            'fixed_channel' => $channel,
            'payment_methods' => $paymentMethods,
        ];
    }

    /** @return list<int> */
    private function orderCreateStoreIds(User $actor, string $channel): array
    {
        return array_values(array_unique(array_merge(
            $this->scope->allowedStoreIds($actor, 'orders.create', $channel),
            $this->scope->allowedStoreIds($actor, 'orders.manage', $channel),
        )));
    }

    /** @return array<string,mixed> */
    private function newOrderStoreData(string $channel, int $storeId): array
    {
        $store = DB::table('stores')
            ->where('id', $storeId)
            ->where('is_active', true)
            ->first(['id', 'code', 'name']);
        abort_unless($store !== null, 404);

        $warehouses = [];
        if ($channel === 'b2b') {
            $warehouses = DB::table('warehouses')
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'code', 'name'])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                ])
                ->values()
                ->all();
        }

        return [
            'id' => (int) $store->id,
            'code' => (string) $store->code,
            'name' => (string) $store->name,
            'warehouses' => $warehouses,
        ];
    }

    public function newOrderLookup(Request $request): JsonResponse
    {
        $request->merge(['type' => (string) $request->route('type')]);
        $actor = $this->actor($request);
        [$channel, $storeId] = $this->newOrderScope($request, $actor);
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['customer', 'product', 'address'])],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'selected_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $type = (string) $data['type'];
        $query = trim((string) ($data['q'] ?? ''));
        $page = (int) ($data['page'] ?? 1);
        $selectedId = isset($data['selected_id']) ? (int) $data['selected_id'] : null;

        $result = match ($type) {
            'customer' => $this->newOrderCustomerLookup($channel, $storeId, $query, $page, $selectedId),
            'product' => $this->newOrderProductLookup(
                $channel,
                $storeId,
                $query,
                $page,
                $selectedId,
                isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            ),
            'address' => $this->newOrderAddressLookup(
                $channel,
                $storeId,
                (int) ($data['customer_id'] ?? 0),
                $selectedId,
            ),
            default => throw new \InvalidArgumentException('Unsupported order lookup type.'),
        };

        return response()->json(['data' => $result]);
    }

    /** @return array{items:list<array<string,mixed>>,page:int,has_more:bool} */
    private function newOrderCustomerLookup(
        string $channel,
        int $storeId,
        string $query,
        int $page,
        ?int $selectedId = null,
    ): array {
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';

        if ($channel === 'b2b') {
            $builder = DB::table('b2b_customers')
                ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
                ->leftJoin('retail_wholesale_accounts', 'retail_wholesale_accounts.b2b_customer_id', '=', 'b2b_customers.id')
                ->leftJoin('stores as retail_customer_store', 'retail_customer_store.id', '=', 'retail_wholesale_accounts.retail_store_id')
                ->where(function ($scope) use ($selectedId): void {
                    $scope->where(function ($active): void {
                        $active->where('b2b_accounts.status', 'active')
                            ->where(function ($eligible): void {
                                $eligible->whereNotNull('b2b_accounts.price_tier_id')
                                    ->orWhereNotNull('retail_wholesale_accounts.retail_store_id');
                            });
                    });
                    if ($selectedId !== null) {
                        $scope->orWhere('b2b_customers.id', $selectedId);
                    }
                })
                ->when($query !== '', function ($scope) use ($needle): void {
                    $scope->where(function ($search) use ($needle): void {
                        $search->where('b2b_customers.name', 'like', $needle)
                            ->orWhere('b2b_customers.phone', 'like', $needle)
                            ->orWhere('b2b_customers.email', 'like', $needle)
                            ->orWhere('b2b_accounts.company_name', 'like', $needle)
                            ->orWhere('b2b_accounts.tax_number', 'like', $needle);
                    });
                })
                ->when($selectedId !== null, fn ($scope) => $scope->orderByRaw('b2b_customers.id = ? desc', [$selectedId]))
                ->orderByRaw('retail_customer_store.id IS NULL')
                ->orderBy('b2b_accounts.company_name')
                ->orderBy('b2b_customers.name')
                ->orderBy('b2b_customers.id')
                ->offset($offset)
                ->limit($limit + 1)
                ->get([
                    'b2b_customers.id',
                    'b2b_customers.name',
                    'b2b_customers.phone',
                    'b2b_customers.email',
                    'b2b_accounts.company_name',
                    'b2b_accounts.tax_number',
                    'b2b_accounts.status as account_status',
                    'retail_wholesale_accounts.retail_store_id',
                    'retail_customer_store.name as retail_store_name',
                ]);
        } else {
            $builder = DB::table('b2c_customers')
                ->where('store_id', $storeId)
                ->when($query !== '', function ($scope) use ($needle): void {
                    $scope->where(function ($search) use ($needle): void {
                        $search->where('name', 'like', $needle)
                            ->orWhere('phone', 'like', $needle)
                            ->orWhere('email', 'like', $needle);
                    });
                })
                ->when($selectedId !== null, fn ($scope) => $scope->orderByRaw('id = ? desc', [$selectedId]))
                ->orderBy('name')
                ->orderBy('id')
                ->offset($offset)
                ->limit($limit + 1)
                ->get(['id', 'name', 'phone', 'email']);
        }

        $hasMore = $builder->count() > $limit;
        $items = $builder->take($limit)->map(function (object $row) use ($channel): array {
            $name = (string) $row->name;
            $company = trim((string) ($row->company_name ?? ''));
            $retailStoreId = $row->retail_store_id ?? null;
            $retailStoreName = trim((string) ($row->retail_store_name ?? ''));
            $label = $channel === 'b2b' && $retailStoreId !== null
                ? $this->msg('التجزئة · ', 'Retail · ').($retailStoreName !== '' ? $retailStoreName : '#'.$retailStoreId)
                : trim(($company !== '' ? $company.' · ' : '').$name);
            $context = array_values(array_filter([
                trim((string) ($row->phone ?? '')),
                trim((string) ($row->email ?? '')),
                trim((string) ($row->tax_number ?? '')),
            ], static fn (string $value): bool => $value !== ''));

            return [
                'id' => (int) $row->id,
                'label' => $label !== '' ? $label : '#'.$row->id,
                'meta' => implode(' · ', $context),
                'active' => (string) ($row->account_status ?? 'active') === 'active',
            ];
        })->values()->all();

        return ['items' => $items, 'page' => $page, 'has_more' => $hasMore];
    }

    /** @return array{items:list<array<string,mixed>>,page:int,has_more:bool} */
    private function newOrderProductLookup(
        string $channel,
        int $storeId,
        string $query,
        int $page,
        ?int $selectedId = null,
        ?int $warehouseId = null,
    ): array {
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';

        $availableStock = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->when(
                $channel === 'b2b' && $warehouseId !== null,
                fn ($scope) => $scope->where('warehouses.id', $warehouseId),
            )
            ->groupBy('inventories.product_id')
            ->select('inventories.product_id')
            ->selectRaw('SUM(inventories.quantity - inventories.reserved_quantity) as available_quantity')
            ->havingRaw('SUM(inventories.quantity - inventories.reserved_quantity) > 0');

        $builder = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->joinSub($availableStock, 'available_stock', function ($join): void {
                $join->on('available_stock.product_id', '=', 'products.id');
            })
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', $channel)
            ->where('catalogs.is_migration_quarantine', false)
            ->where(function ($scope) use ($selectedId): void {
                $scope->where(function ($active): void {
                    $active->where('catalogs.is_active', true)
                        ->where('products.is_active', true)
                        ->where('store_products.is_active', true);
                });
                if ($selectedId !== null) {
                    $scope->orWhere('products.id', $selectedId);
                }
            })
            ->when($channel === 'b2c', fn ($scope) => $scope->whereNotNull('store_products.price'))
            ->when($query !== '', function ($scope) use ($needle): void {
                $scope->where(function ($search) use ($needle): void {
                    $search->where('products.name', 'like', $needle)
                        ->orWhere('products.sku', 'like', $needle)
                        ->orWhere('products.barcode', 'like', $needle);
                });
            })
            ->when($selectedId !== null, fn ($scope) => $scope->orderByRaw('products.id = ? desc', [$selectedId]))
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get([
                'products.id',
                'products.sku',
                'products.barcode',
                'products.name',
                'products.is_active as product_active',
                'catalogs.is_active as catalog_active',
                'store_products.is_active as store_product_active',
                'store_products.price',
                'available_stock.available_quantity',
            ]);

        $hasMore = $builder->count() > $limit;
        $items = $builder->take($limit)->map(static function (object $row): array {
            $sku = trim((string) $row->sku);
            $barcode = trim((string) ($row->barcode ?? ''));
            $meta = array_values(array_filter([$sku, $barcode], static fn (string $value): bool => $value !== ''));
            $availableQuantity = max(0, (float) ($row->available_quantity ?? 0));
            $meta[] = (app()->getLocale() === 'ar' ? 'المتاح' : 'Available').': '
                .rtrim(rtrim(number_format($availableQuantity, 3, '.', ''), '0'), '.');

            return [
                'id' => (int) $row->id,
                'label' => (string) $row->name,
                'meta' => implode(' · ', $meta),
                'price' => $row->price === null ? null : (float) $row->price,
                'available_quantity' => $availableQuantity,
                'active' => (bool) $row->product_active && (bool) $row->catalog_active && (bool) $row->store_product_active,
            ];
        })->values()->all();

        return ['items' => $items, 'page' => $page, 'has_more' => $hasMore];
    }

    /** @return array{items:list<array<string,mixed>>,page:int,has_more:bool} */
    private function newOrderAddressLookup(
        string $channel,
        int $storeId,
        int $customerId,
        ?int $selectedId = null,
    ): array {
        if ($customerId <= 0) {
            return ['items' => [], 'page' => 1, 'has_more' => false];
        }

        if ($channel === 'b2c') {
            abort_unless(
                DB::table('b2c_customers')->where('id', $customerId)->where('store_id', $storeId)->exists(),
                404,
            );
            $customerColumn = 'b2c_customer_id';
        } else {
            $eligible = $this->newOrderCustomerLookup('b2b', $storeId, '', 1, $customerId)['items'];
            abort_unless(collect($eligible)->contains(fn (array $item): bool => (int) $item['id'] === $customerId), 404);
            $customerColumn = 'b2b_customer_id';
        }

        $rows = DB::table('addresses')
            ->where($customerColumn, $customerId)
            ->where(function ($scope) use ($selectedId): void {
                $scope->whereNull('deleted_at');
                if ($selectedId !== null) {
                    $scope->orWhere('id', $selectedId);
                }
            })
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->limit(51)
            ->get(['id', 'deleted_at', 'label', 'line1', 'city', 'area', 'block', 'street', 'building']);

        $hasMore = $rows->count() > 50;
        $items = $rows->take(50)->map(function (object $row): array {
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
                'label' => $label !== '' ? $label : $this->msg('عنوان #'.$row->id, 'Address #'.$row->id),
                'meta' => '',
                'active' => $row->deleted_at === null,
            ];
        })->values()->all();

        return ['items' => $items, 'page' => 1, 'has_more' => $hasMore];
    }

    /** @param list<int> $productIds
     * @return array<int,array<string,mixed>>
     */
    private function newOrderSelectedProducts(string $channel, int $storeId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', $channel)
            ->where('catalogs.is_migration_quarantine', false)
            ->whereIn('products.id', $productIds)
            ->when($channel === 'b2c', fn ($scope) => $scope->whereNotNull('store_products.price'))
            ->get([
                'products.id',
                'products.sku',
                'products.barcode',
                'products.name',
                'products.is_active as product_active',
                'catalogs.is_active as catalog_active',
                'store_products.is_active as store_product_active',
                'store_products.price',
            ])
            ->mapWithKeys(static function (object $row): array {
                $sku = trim((string) $row->sku);
                $barcode = trim((string) ($row->barcode ?? ''));
                $meta = array_values(array_filter([$sku, $barcode], static fn (string $value): bool => $value !== ''));
                $id = (int) $row->id;

                return [$id => [
                    'id' => $id,
                    'label' => (string) $row->name,
                    'meta' => implode(' · ', $meta),
                    'price' => $row->price === null ? null : (float) $row->price,
                    'active' => (bool) $row->product_active && (bool) $row->catalog_active && (bool) $row->store_product_active,
                ]];
            })
            ->all();
    }

    /** @return array<string,mixed> */
    private function newOrderEditData(Order $order): array
    {
        $channel = strtolower((string) $order->channel);
        $storeId = (int) $order->store_id;
        $data = $this->newOrderStoreData($channel, $storeId);
        $customerId = $channel === 'b2b' ? (int) $order->b2b_customer_id : (int) $order->b2c_customer_id;

        $data['selected_customer'] = collect(
            $this->newOrderCustomerLookup($channel, $storeId, '', 1, $customerId)['items']
        )->firstWhere('id', $customerId);
        $data['selected_address'] = $order->address_id === null
            ? null
            : collect($this->newOrderAddressLookup(
                $channel,
                $storeId,
                $customerId,
                (int) $order->address_id,
            )['items'])->firstWhere('id', (int) $order->address_id);
        $productIds = DB::table('order_items')
            ->where('order_id', $order->getKey())
            ->pluck('product_id')
            ->filter()
            ->map(static fn ($productId): int => (int) $productId)
            ->unique()
            ->values()
            ->all();
        $data['selected_products'] = $this->newOrderSelectedProducts($channel, $storeId, $productIds);

        return $data;
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
            'title' => 'تذكير بالطلب '.DisplayNumber::reference($model->getKey()),
            'body' => 'يوجد طلب يحتاج متابعتك الآن.',
            'title_ar' => 'تذكير بالطلب '.DisplayNumber::reference($model->getKey()),
            'title_en' => 'Order reminder '.DisplayNumber::reference($model->getKey()),
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
                'order_number' => DisplayNumber::reference($model->getKey()),
                'internal_order_number' => (string) $model->order_number,
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
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in($this->activeOrderStatusCodes())],
        ]);
        $targetStatus = (string) $validated['status'];

        abort_if(
            in_array(strtolower((string) $model->status), ['delivered', 'completed'], true)
                && $targetStatus === 'cancelled',
            409,
            'Delivered orders cannot be cancelled.',
        );

        abort_if(
            in_array($targetStatus, ['confirmed', 'preparing', 'ready'], true),
            409,
            'Confirmation and preparation steps are automatic when fulfillment is assigned.',
        );

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
        abort_unless(
            $this->orderCanBeDispatched($model),
            409,
            'Order is not available for fulfillment assignment.',
        );
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

        $currentDriverAssignment = DriverAssignment::query()
            ->where('order_id', $model->getKey())
            ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
            ->latest('id')
            ->first();

        if ($currentDriverAssignment instanceof DriverAssignment) {
            abort_unless(
                (string) $data['assignee_type'] === 'driver',
                409,
                'Orders already with a Driver can only be withdrawn and transferred to another Driver.',
            );
            abort_if(
                (int) $data['assignee_id'] === (int) $currentDriverAssignment->driver_id,
                422,
                'Select another Driver to transfer this order.',
            );
        }

        if ((string) $data['assignee_type'] === 'driver') {
            $driverId = (int) $data['assignee_id'];

            DB::transaction(function () use (
                $model,
                $actor,
                $driverId,
                $reason,
                $request,
                $deliveries,
                $audit,
                $notifier,
                $dispatch,
            ): void {
                $active = DriverAssignment::query()
                    ->where('order_id', $model->getKey())
                    ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($active instanceof DriverAssignment && (int) $active->driver_id === $driverId) {
                    $dispatch->assignDriver($model, $actor, $active, $reason);
                    $this->activateAssignedOrder($model, $actor, $audit, $notifier, $request);

                    return;
                }

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
                $this->activateAssignedOrder($model, $actor, $audit, $notifier, $request);
            });

            return back()->with('status', $this->msg(
                'تم توجيه الطلب إلى السائق.',
                'Order dispatched to driver.',
            ));
        }

        $vanId = (int) $data['assignee_id'];

        DB::transaction(function () use (
            $model,
            $actor,
            $order,
            $vanId,
            $reason,
            $request,
            $deliveries,
            $audit,
            $notifier,
            $dispatch,
        ): void {
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
                ->lockForUpdate()
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
            $this->activateAssignedOrder($model, $actor, $audit, $notifier, $request);
        });

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
        abort_unless(
            $this->orderCanBeDispatched($model),
            409,
            'Order is not available for fulfillment assignment.',
        );
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

        DB::transaction(function () use (
            $model,
            $actor,
            $order,
            $reason,
            $request,
            $deliveries,
            $audit,
            $notifier,
            $dispatch,
        ): void {
            $activeDriver = DriverAssignment::query()
                ->where('order_id', $model->getKey())
                ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                ->lockForUpdate()
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
        });

        return back()->with('status', $this->msg(
            'تم إرجاع الطلب إلى قائمة التوجيه المعلق.',
            'Order returned to the pending dispatch queue.',
        ));
    }

    public function assignCustomerServiceOwner(
        Request $request,
        int $order,
        AuditLogger $audit,
        OrderLifecycleNotificationService $notifications,
    ): RedirectResponse {
        $actor = $this->actor($request);
        abort_unless($this->customerServiceTeam->canManage($actor), 403);
        $model = $this->managedOrder($actor, $order, 'orders.manage');

        $validated = $request->validate([
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $assignedUserId = isset($validated['assigned_user_id'])
            ? (int) $validated['assigned_user_id']
            : null;
        $reason = trim((string) ($validated['reason'] ?? ''));

        if ($assignedUserId !== null) {
            $allowed = $this->customerServiceTeam
                ->members($actor)
                ->where('is_active', true)
                ->contains(fn (User $member): bool => (int) $member->getKey() === $assignedUserId);
            abort_unless($allowed, 403);
        }

        DB::transaction(function () use (
            $model,
            $actor,
            $assignedUserId,
            $reason,
            $request,
            $audit,
        ): void {
            DB::table('orders')
                ->where('id', $model->getKey())
                ->lockForUpdate()
                ->first(['id']);

            $active = DB::table('order_customer_service_assignments')
                ->where('order_id', $model->getKey())
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            DB::table('order_customer_service_assignments')
                ->where('order_id', $model->getKey())
                ->whereNull('unassigned_at')
                ->update([
                    'unassigned_at' => now(),
                    'updated_at' => now(),
                ]);

            $assignmentId = null;
            if ($assignedUserId !== null) {
                $assignmentId = (int) DB::table('order_customer_service_assignments')->insertGetId([
                    'order_id' => (int) $model->getKey(),
                    'assigned_user_id' => $assignedUserId,
                    'assigned_by_user_id' => (int) $actor->getKey(),
                    'reason' => $reason === '' ? null : $reason,
                    'assigned_at' => now(),
                    'unassigned_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $audit->record(
                'order.customer_service_owner.changed',
                $actor,
                $model,
                [
                    'assignment_id' => $active?->id,
                    'assigned_user_id' => $active?->assigned_user_id,
                ],
                [
                    'assignment_id' => $assignmentId,
                    'assigned_user_id' => $assignedUserId,
                    'reason' => $reason === '' ? null : $reason,
                ],
                $request,
            );
        }, 3);

        $notifications->customerOrderChanged($model->fresh(), 'customer_service_updated', [
            'assigned' => $assignedUserId !== null,
        ]);

        return back()->with('status', $this->msg(
            $assignedUserId === null ? 'تم إلغاء إسناد الطلب من فريق خدمة العملاء.' : 'تم إسناد الطلب لموظف خدمة العملاء.',
            $assignedUserId === null ? 'Customer Service owner removed.' : 'Order assigned to Customer Service.',
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
        abort_unless(
            $this->orderCanBeDispatched($model),
            409,
            'Order is not available for fulfillment assignment.',
        );
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
        abort_unless(
            $this->orderCanBeDispatched($model),
            409,
            'Order is not available for fulfillment assignment.',
        );
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
     * @param  list<Order>  $orders
     */
    private function listRowContext(array $orders): OrderOperationsRowContext
    {
        if ($orders === []) {
            return new OrderOperationsRowContext([], [], [], [], [], [], [], [], [], [], [], [], [], [], []);
        }

        $orderIds = array_map(
            static fn (Order $order): int => (int) $order->getKey(),
            $orders,
        );

        $assignments = [];
        foreach (
            DriverAssignment::query()
                ->whereIn('order_id', $orderIds)
                ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                ->whereIn('id', function ($query) use ($orderIds): void {
                    $query->selectRaw('MAX(id)')
                        ->from('driver_assignments')
                        ->whereIn('order_id', $orderIds)
                        ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                        ->groupBy('order_id');
                })
                ->get() as $assignment
        ) {
            $assignments[(int) $assignment->order_id] = $assignment;
        }

        $dispatchStates = [];
        foreach (
            OrderDispatchState::query()
                ->whereIn('order_id', $orderIds)
                ->orderByDesc('id')
                ->get() as $dispatch
        ) {
            $orderId = (int) $dispatch->order_id;
            if (isset($dispatchStates[$orderId]) === false) {
                $dispatchStates[$orderId] = $dispatch;
            }
        }

        $customerServiceAssignments = [];
        foreach (
            DB::table('order_customer_service_assignments')
                ->whereIn('order_id', $orderIds)
                ->whereNull('unassigned_at')
                ->orderByDesc('id')
                ->get(['id', 'order_id', 'assigned_user_id', 'assigned_by_user_id', 'reason', 'assigned_at']) as $assignment
        ) {
            $orderId = (int) $assignment->order_id;
            if (! isset($customerServiceAssignments[$orderId])) {
                $customerServiceAssignments[$orderId] = $assignment;
            }
        }

        $customerServiceUserIds = collect($customerServiceAssignments)
            ->pluck('assigned_user_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $customerServiceUsers = [];
        if ($customerServiceUserIds !== []) {
            foreach (
                DB::table('users')
                    ->whereIn('id', $customerServiceUserIds)
                    ->get(['id', 'name', 'email', 'is_active']) as $teamUser
            ) {
                $customerServiceUsers[(int) $teamUser->id] = $teamUser;
            }
        }

        $driverIds = [];
        foreach ($assignments as $assignment) {
            $driverIds[] = (int) $assignment->driver_id;
        }
        $vanIds = [];
        $territoryIds = [];
        foreach ($dispatchStates as $dispatch) {
            if ($dispatch->current_assignee_type === 'driver' && $dispatch->current_assignee_id !== null) {
                $driverIds[] = (int) $dispatch->current_assignee_id;
            }
            if ($dispatch->current_assignee_type === 'van' && $dispatch->current_assignee_id !== null) {
                $vanIds[] = (int) $dispatch->current_assignee_id;
            }
            if ($dispatch->service_territory_id !== null) {
                $territoryIds[] = (int) $dispatch->service_territory_id;
            }
        }

        $drivers = [];
        $driverIds = array_values(array_unique($driverIds));
        if ($driverIds !== []) {
            foreach (
                DB::table('drivers')
                    ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
                    ->whereIn('drivers.id', $driverIds)
                    ->get(['drivers.id', 'drivers.user_id', 'users.name']) as $driver
            ) {
                $drivers[(int) $driver->id] = $driver;
            }
        }

        $vans = [];
        $vanIds = array_values(array_unique($vanIds));
        if ($vanIds !== []) {
            foreach (
                DB::table('vans')
                    ->whereIn('id', $vanIds)
                    ->get(['id', 'code', 'plate_number']) as $van
            ) {
                $vans[(int) $van->id] = $van;
            }
        }

        $territories = [];
        $territoryIds = array_values(array_unique($territoryIds));
        if ($territoryIds !== []) {
            foreach (
                DB::table('service_territories')
                    ->whereIn('id', $territoryIds)
                    ->get(['id', 'code', 'name_ar', 'name_en']) as $territory
            ) {
                $territories[(int) $territory->id] = $territory;
            }
        }

        $b2bCustomerIds = [];
        $b2cCustomerIds = [];
        $storeIds = [];
        foreach ($orders as $order) {
            $storeIds[] = (int) $order->store_id;
            if (strtolower((string) $order->channel) === 'b2b' && $order->b2b_customer_id !== null) {
                $b2bCustomerIds[] = (int) $order->b2b_customer_id;
            }
            if (strtolower((string) $order->channel) === 'b2c' && $order->b2c_customer_id !== null) {
                $b2cCustomerIds[] = (int) $order->b2c_customer_id;
            }
        }

        $b2bNames = [];
        $b2bCustomerIds = array_values(array_unique($b2bCustomerIds));
        if ($b2bCustomerIds !== []) {
            $b2bNames = DB::table('b2b_customers')
                ->whereIn('id', $b2bCustomerIds)
                ->pluck('name', 'id')
                ->map(static fn ($name): string => (string) $name)
                ->all();
        }

        $b2cNames = [];
        $b2cCustomerIds = array_values(array_unique($b2cCustomerIds));
        if ($b2cCustomerIds !== []) {
            $b2cNames = DB::table('b2c_customers')
                ->whereIn('id', $b2cCustomerIds)
                ->pluck('name', 'id')
                ->map(static fn ($name): string => (string) $name)
                ->all();
        }

        $customerNames = [];
        foreach ($orders as $order) {
            $orderId = (int) $order->getKey();
            if (strtolower((string) $order->channel) === 'b2b') {
                $customerNames[$orderId] = (string) ($b2bNames[(int) $order->b2b_customer_id] ?? '-');
            } else {
                $customerNames[$orderId] = (string) ($b2cNames[(int) $order->b2c_customer_id] ?? '-');
            }
        }

        $payments = [];
        foreach (
            DB::table('payments')
                ->whereIn('order_id', $orderIds)
                ->whereIn('id', function ($query) use ($orderIds): void {
                    $query->selectRaw('MAX(id)')
                        ->from('payments')
                        ->whereIn('order_id', $orderIds)
                        ->groupBy('order_id');
                })
                ->get(['id', 'order_id', 'status', 'provider', 'amount', 'currency']) as $payment
        ) {
            $payments[(int) $payment->order_id] = $payment;
        }

        $invoices = [];
        foreach (
            DB::table('invoices')
                ->whereIn('order_id', $orderIds)
                ->whereIn('id', function ($query) use ($orderIds): void {
                    $query->selectRaw('MAX(id)')
                        ->from('invoices')
                        ->whereIn('order_id', $orderIds)
                        ->groupBy('order_id');
                })
                ->get(['id', 'order_id', 'invoice_number', 'status', 'total', 'currency']) as $invoice
        ) {
            $invoices[(int) $invoice->order_id] = $invoice;
        }

        $issuedInvoiceOrders = [];
        foreach (
            DB::table('invoices')
                ->whereIn('order_id', $orderIds)
                ->whereIn('status', ['issued', 'reissued'])
                ->distinct()
                ->pluck('order_id') as $orderId
        ) {
            $issuedInvoiceOrders[(int) $orderId] = true;
        }

        $stores = [];
        $storeIds = array_values(array_unique($storeIds));
        foreach (
            DB::table('stores')
                ->whereIn('id', $storeIds)
                ->get(['id', 'name', 'code']) as $store
        ) {
            $stores[(int) $store->id] = $store;
        }

        $sources = [];
        foreach (
            DB::table('order_status_history')
                ->whereIn('order_id', $orderIds)
                ->whereNull('from_status')
                ->where('to_status', 'pending')
                ->whereIn('id', function ($query) use ($orderIds): void {
                    $query->selectRaw('MIN(id)')
                        ->from('order_status_history')
                        ->whereIn('order_id', $orderIds)
                        ->whereNull('from_status')
                        ->where('to_status', 'pending')
                        ->groupBy('order_id');
                })
                ->get(['order_id', 'note']) as $history
        ) {
            $sources[(int) $history->order_id] = $history->note === 'dashboard_order_created'
                ? 'dashboard'
                : ($history->note === 'checkout' ? 'customer_checkout' : 'legacy');
        }

        $deliveredAt = [];
        foreach (
            DB::table('order_status_history')
                ->whereIn('order_id', $orderIds)
                ->where('to_status', 'delivered')
                ->whereIn('id', function ($query) use ($orderIds): void {
                    $query->selectRaw('MAX(id)')
                        ->from('order_status_history')
                        ->whereIn('order_id', $orderIds)
                        ->where('to_status', 'delivered')
                        ->groupBy('order_id');
                })
                ->get(['order_id', 'created_at']) as $history
        ) {
            $deliveredAt[(int) $history->order_id] = $history->created_at;
        }

        return new OrderOperationsRowContext(
            assignments: $assignments,
            drivers: $drivers,
            dispatchStates: $dispatchStates,
            vans: $vans,
            territories: $territories,
            customerServiceAssignments: $customerServiceAssignments,
            customerServiceUsers: $customerServiceUsers,
            customerNames: $customerNames,
            payments: $payments,
            invoices: $invoices,
            issuedInvoiceOrders: $issuedInvoiceOrders,
            stores: $stores,
            sources: $sources,
            settlements: app(DeliverySettlementService::class)->summarizeMany($orders),
            deliveredAt: $deliveredAt,
        );
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
        array $manageScopes,
        array $financeScopes,
        ?OrderOperationsRowContext $context = null,
    ): array {
        $orderId = (int) $order->getKey();

        $customerServiceAssignment = $context === null
            ? DB::table('order_customer_service_assignments')
                ->where('order_id', $orderId)
                ->whereNull('unassigned_at')
                ->latest('id')
                ->first(['id', 'assigned_user_id', 'reason', 'assigned_at'])
            : ($context->customerServiceAssignments[$orderId] ?? null);
        $customerServiceOwner = $customerServiceAssignment === null
            ? null
            : ($context === null
                ? DB::table('users')
                    ->where('id', (int) $customerServiceAssignment->assigned_user_id)
                    ->first(['id', 'name', 'email', 'is_active'])
                : ($context->customerServiceUsers[(int) $customerServiceAssignment->assigned_user_id] ?? null));

        $assignment = $context === null
            ? DriverAssignment::query()
                ->where('order_id', $orderId)
                ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                ->latest('id')
                ->first()
            : ($context->assignments[$orderId] ?? null);

        $driver = $assignment === null
            ? null
            : ($context === null
                ? DB::table('drivers')
                    ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.id', $assignment->driver_id)
                    ->first(['drivers.id', 'drivers.user_id', 'users.name'])
                : ($context->drivers[(int) $assignment->driver_id] ?? null));

        $dispatch = $context === null
            ? OrderDispatchState::query()->where('order_id', $orderId)->first()
            : ($context->dispatchStates[$orderId] ?? null);
        /** @var OrderDispatchState|null $dispatch */
        $dispatchAssignee = null;
        if ($dispatch instanceof OrderDispatchState && $dispatch->current_assignee_type === 'driver') {
            $dispatchDriver = $context === null
                ? DB::table('drivers')
                    ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.id', (int) $dispatch->current_assignee_id)
                    ->first(['drivers.id', 'users.name'])
                : ($context->drivers[(int) $dispatch->current_assignee_id] ?? null);
            $dispatchAssignee = $dispatchDriver === null
                ? $this->msg('سائق بدون اسم', 'Unnamed driver')
                : (trim((string) $dispatchDriver->name)
                    ?: $this->msg('سائق بدون اسم', 'Unnamed driver'));
        } elseif ($dispatch instanceof OrderDispatchState && $dispatch->current_assignee_type === 'van') {
            $dispatchVan = $context === null
                ? DB::table('vans')
                    ->where('id', (int) $dispatch->current_assignee_id)
                    ->first(['id', 'code', 'plate_number'])
                : ($context->vans[(int) $dispatch->current_assignee_id] ?? null);
            if ($dispatchVan !== null) {
                $dispatchAssignee = trim((string) $dispatchVan->code)
                    .($dispatchVan->plate_number ? ' · '.$dispatchVan->plate_number : '');
            }
        }

        $dispatchTerritory = $dispatch?->service_territory_id === null
            ? null
            : ($context === null
                ? DB::table('service_territories')
                    ->where('id', (int) $dispatch->service_territory_id)
                    ->first(['id', 'code', 'name_ar', 'name_en'])
                : ($context->territories[(int) $dispatch->service_territory_id] ?? null));

        $customerName = $context === null
            ? (strtolower((string) $order->channel) === 'b2b'
                ? DB::table('b2b_customers')->where('id', $order->b2b_customer_id)->value('name')
                : DB::table('b2c_customers')->where('id', $order->b2c_customer_id)->value('name'))
            : ($context->customerNames[$orderId] ?? '-');

        $payment = $context === null
            ? DB::table('payments')
                ->where('order_id', $orderId)
                ->latest('id')
                ->first(['status', 'provider', 'amount', 'currency'])
            : ($context->payments[$orderId] ?? null);

        $invoice = $context === null
            ? DB::table('invoices')
                ->where('order_id', $orderId)
                ->latest('id')
                ->first(['id', 'invoice_number', 'status', 'total', 'currency'])
            : ($context->invoices[$orderId] ?? null);

        if ($context === null) {
            $issuedFinancialInvoice = Invoice::query()
                ->where('order_id', $orderId)
                ->whereIn('status', ['issued', 'reissued'])
                ->orderByDesc('revision')
                ->orderByDesc('id')
                ->first();
            $financialPayment = Payment::query()
                ->where('order_id', $orderId)
                ->latest('id')
                ->first();
            $paymentSettlement = app(DeliverySettlementService::class)->summarize(
                $order,
                $issuedFinancialInvoice,
                $financialPayment,
            );
        } else {
            $paymentSettlement = $context->settlements[$orderId];
        }

        $channel = strtolower((string) $order->channel);
        $canManage = in_array(
            (int) $order->store_id,
            $manageScopes[$channel] ?? [],
            true,
        );
        $canViewInvoice = in_array(
            (int) $order->store_id,
            $financeScopes[$channel] ?? [],
            true,
        );
        $hasIssuedInvoice = $context === null
            ? DB::table('invoices')
                ->where('order_id', $orderId)
                ->whereIn('status', ['issued', 'reissued'])
                ->exists()
            : isset($context->issuedInvoiceOrders[$orderId]);
        $canEdit = $canManage
            && (string) $order->status === 'pending'
            && $order->commercial_locked_at === null
            && ($payment->status ?? null) !== 'paid'
            && $hasIssuedInvoice === false;

        $store = $context === null
            ? DB::table('stores')->where('id', $order->store_id)->first(['id', 'name', 'code'])
            : ($context->stores[(int) $order->store_id] ?? null);
        $storeLabel = $store === null
            ? '#'.$order->store_id
            : ($store->name ?? $store->code ?? '#'.$order->store_id);
        if ($context === null) {
            $sourceNote = DB::table('order_status_history')
                ->where('order_id', $orderId)
                ->whereNull('from_status')
                ->where('to_status', 'pending')
                ->oldest('id')
                ->value('note');
            $source = $sourceNote === 'dashboard_order_created'
                ? 'dashboard'
                : ($sourceNote === 'checkout' ? 'customer_checkout' : 'legacy');
        } else {
            $source = $context->sources[$orderId] ?? 'legacy';
        }

        $status = (string) $order->status;
        $deliveredAt = $status === 'delivered'
            ? ($context === null
                ? DB::table('order_status_history')
                    ->where('order_id', $orderId)
                    ->where('to_status', 'delivered')
                    ->latest('id')
                    ->value('created_at')
                : ($context->deliveredAt[$orderId] ?? null))
            : null;
        $hasActiveDriver = $assignment instanceof DriverAssignment;
        $availableStatuses = ! in_array($status, ['delivered', 'completed', 'cancelled'], true)
            && in_array('cancelled', $activeStatusCodes, true)
                ? ['cancelled']
                : [];

        $displayStatus = $this->orderOperationsDisplayStatus(
            $order,
            $assignment,
            $dispatch,
            $statusLabels,
        );

        return [
            'id' => (int) $order->getKey(),
            'number' => DisplayNumber::reference($order->getKey()),
            'internal_number' => (string) $order->order_number,
            'channel' => $channel,
            'store_id' => (int) $order->store_id,
            'store' => $storeLabel,
            'source' => $source,
            'customer' => $customerName ?? '-',
            'status' => $status,
            'status_label' => $displayStatus['label'],
            'status_visual_state' => $displayStatus['state'],
            'has_active_driver' => $hasActiveDriver,
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
            'delivered_at' => $deliveredAt,
            'payment_status' => $payment?->status,
            'collection_payment_state' => $paymentSettlement['payment_state'],
            'collection_paid_amount' => $paymentSettlement['paid_amount'],
            'collection_remaining_amount' => $paymentSettlement['remaining_amount'],
            'payment_provider' => $payment?->provider,
            'payment_amount' => $payment?->amount === null ? null : (float) $payment->amount,
            'payment_currency' => $payment?->currency,
            'invoice_id' => $invoice?->id === null ? null : (int) $invoice->id,
            'invoice_number' => $invoice?->invoice_number,
            'invoice_status' => $invoice?->status,
            'invoice_total' => $invoice?->total === null ? null : (float) $invoice->total,
            'invoice_currency' => $invoice?->currency,
            'can_view_invoice' => $canViewInvoice,
            'can_manage' => $canManage,
            'can_edit' => $canEdit,
            'customer_service_owner_id' => $customerServiceOwner?->id === null ? null : (int) $customerServiceOwner->id,
            'customer_service_owner' => $customerServiceOwner?->name,
            'customer_service_owner_email' => $customerServiceOwner?->email,
            'customer_service_assignment_reason' => $customerServiceAssignment?->reason,
            'assignment_id' => $assignment?->getKey(),
            'assignment_status' => $assignment?->status,
            'driver_id' => $driver?->id,
            'driver' => $driver?->name,
            'dispatch_status' => $dispatch === null ? 'unrouted' : (string) $dispatch->status,
            'dispatch_source' => $dispatch?->routing_source,
            'dispatch_reason' => $dispatch?->routing_reason,
            'dispatch_assignee_type' => $dispatch?->current_assignee_type,
            'dispatch_assignee_id' => $dispatch?->current_assignee_id === null ? null : (int) $dispatch->current_assignee_id,
            'dispatch_assignee' => $dispatchAssignee,
            'dispatch_territory' => $dispatchTerritory === null
                ? null
                : ((app()->getLocale() === 'ar' ? $dispatchTerritory->name_ar : $dispatchTerritory->name_en)
                    ?: $dispatchTerritory->code),
            'can_dispatch_authorized' => $canDispatchAuthorized = in_array(
                (int) $order->store_id,
                $dispatchScopes[strtolower((string) $order->channel)] ?? [],
                true,
            ),
            'can_dispatch' => $canDispatchAuthorized && $this->orderCanBeDispatched($order),
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
        array $manageScopes,
        array $financeScopes,
    ): array {
        $row = $this->row(
            $order,
            $statusLabels,
            $activeStatusCodes,
            $dispatchScopes,
            $manageScopes,
            $financeScopes,
        );
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

        $dispatchAudit = AuditLog::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.auditable_type', Order::class)
            ->where('audit_logs.auditable_id', $order->getKey())
            ->where('audit_logs.event', 'like', 'order.dispatch.%')
            ->latest('audit_logs.id')
            ->get([
                'audit_logs.event',
                'audit_logs.after',
                'audit_logs.created_at',
                'users.name as actor_name',
            ])
            ->map(static function (AuditLog $entry): array {
                /** @var array<string,mixed> $after */
                $after = $entry->getAttribute('after') ?? [];

                return [
                    'event_key' => str_replace('.', '_', (string) $entry->event),
                    'actor' => trim((string) ($entry->actor_name ?? '')),
                    'status' => $after['status'] ?? null,
                    'source' => $after['routing_source'] ?? null,
                    'reason' => $after['routing_reason'] ?? null,
                    'assignee_type' => $after['current_assignee_type'] ?? null,
                    'created_at' => $entry->created_at,
                ];
            })
            ->all();

        $vanExecution = null;
        $vanEvents = [];
        $collections = [];

        if (strtolower((string) $order->channel) === 'b2b') {
            $vanExecution = DB::table('order_van_execution_states')
                ->where('order_id', $order->getKey())
                ->orderByDesc('id')
                ->first([
                    'order_van_assignment_id',
                    'van_id',
                    'status',
                    'failure_reason_code',
                    'failure_note',
                    'last_transition_at',
                ]);

            $vanEvents = DB::table('order_van_execution_events')
                ->leftJoin('users', 'users.id', '=', 'order_van_execution_events.user_id')
                ->where('order_van_execution_events.order_id', $order->getKey())
                ->orderByDesc('order_van_execution_events.captured_at')
                ->orderByDesc('order_van_execution_events.id')
                ->get([
                    'order_van_execution_events.id',
                    'order_van_execution_events.order_van_assignment_id',
                    'order_van_execution_events.action',
                    'order_van_execution_events.from_status',
                    'order_van_execution_events.to_status',
                    'order_van_execution_events.proof_type',
                    'order_van_execution_events.proof_path',
                    'order_van_execution_events.reason_code',
                    'order_van_execution_events.note',
                    'order_van_execution_events.captured_at',
                    'users.name as actor_name',
                ])
                ->map(static fn (object $event): array => [
                    'id' => (int) $event->id,
                    'order_van_assignment_id' => (int) $event->order_van_assignment_id,
                    'action' => (string) $event->action,
                    'from_status' => (string) $event->from_status,
                    'to_status' => (string) $event->to_status,
                    'proof_type' => $event->proof_type,
                    'proof_path' => $event->proof_path,
                    'reason_code' => $event->reason_code,
                    'note' => $event->note,
                    'captured_at' => $event->captured_at,
                    'actor' => trim((string) ($event->actor_name ?? '')),
                ])
                ->all();

            $collections = DB::table('collection_allocations')
                ->join(
                    'collection_transactions',
                    'collection_transactions.id',
                    '=',
                    'collection_allocations.collection_transaction_id',
                )
                ->join('invoices', 'invoices.id', '=', 'collection_allocations.invoice_id')
                ->where('invoices.order_id', $order->getKey())
                ->where('collection_transactions.type', 'collection')
                ->orderByDesc('collection_transactions.id')
                ->get([
                    'collection_transactions.id',
                    'collection_transactions.status',
                    'collection_transactions.source',
                    'collection_transactions.created_at',
                    'collection_allocations.amount',
                    'collection_allocations.currency',
                ])
                ->map(static fn (object $collection): array => [
                    'id' => (int) $collection->id,
                    'status' => (string) $collection->status,
                    'source' => (string) $collection->source,
                    'amount' => (float) $collection->amount,
                    'currency' => (string) $collection->currency,
                    'created_at' => $collection->created_at,
                ])
                ->all();
        }

        $items = DB::table('order_items')
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get(['product_id', 'sku_snapshot', 'name_snapshot', 'quantity', 'unit_price', 'line_total', 'currency'])
            ->map(static fn (object $item): array => [
                'product_id' => (int) $item->product_id,
                'sku' => (string) $item->sku_snapshot,
                'name' => (string) $item->name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'currency' => (string) $item->currency,
            ])
            ->all();

        return [
            ...$row,
            'customer_id' => strtolower((string) $order->channel) === 'b2b'
                ? (int) $order->b2b_customer_id
                : (int) $order->b2c_customer_id,
            'warehouse_id' => $order->warehouse_id === null ? null : (int) $order->warehouse_id,
            'address_id' => $order->address_id === null ? null : (int) $order->address_id,
            'payment_method' => $order->payment_method,
            'coupon_code' => data_get($order->pricing_snapshot, 'coupon.code'),
            'customer_note' => $order->customer_note,
            'items' => $items,
            'edit_options' => $row['can_edit']
                ? $this->newOrderEditData($order)
                : null,
            'delivery_address' => $deliveryAddress,
            'history' => $history,
            'assignments' => $assignments,
            'van_assignments' => $vanAssignments,
            'dispatch_audit' => $dispatchAudit,
            'van_execution' => $vanExecution,
            'van_events' => $vanEvents,
            'collections' => $collections,
            'delivery_evidence' => $this->deliveryEvidence->order($actor, $order),
            'van_delivery_evidence' => strtolower((string) $order->channel) === 'b2b'
                ? $this->vanDeliveryEvidence->order($actor, $order)
                : [],
        ];
    }

    /**
     * @param  array<string,string>  $statusLabels
     * @return array{label:string,state:string}
     */
    private function orderOperationsDisplayStatus(
        Order $order,
        ?DriverAssignment $assignment,
        ?OrderDispatchState $dispatch,
        array $statusLabels,
    ): array {
        $status = strtolower((string) $order->status);
        $assignmentStatus = strtolower((string) ($assignment->status ?? ''));

        if (in_array($status, ['delivered', 'completed'], true)) {
            return [
                'label' => __('order_operations.lifecycle_labels.completed'),
                'state' => 'completed',
            ];
        }

        if (in_array($status, ['cancelled', 'failed'], true)) {
            return [
                'label' => $statusLabels[$status] ?? $this->operationBusinessLabel($status),
                'state' => $status,
            ];
        }

        if ($assignment instanceof DriverAssignment) {
            if ($assignmentStatus === 'assigned') {
                return [
                    'label' => __('order_operations.lifecycle_labels.assigned_driver'),
                    'state' => 'assigned',
                ];
            }

            if (in_array($assignmentStatus, ['accepted', 'picked_up', 'out_for_delivery'], true)) {
                return [
                    'label' => __('order_operations.lifecycle_labels.driver_processing'),
                    'state' => 'processing',
                ];
            }
        }

        if (
            $dispatch instanceof OrderDispatchState
            && (string) $dispatch->status === 'assigned'
            && (string) $dispatch->current_assignee_type === 'driver'
        ) {
            return [
                'label' => __('order_operations.lifecycle_labels.assigned_driver'),
                'state' => 'assigned',
            ];
        }

        if (
            $dispatch instanceof OrderDispatchState
            && (string) $dispatch->status === 'assigned'
            && (string) $dispatch->current_assignee_type === 'van'
        ) {
            return [
                'label' => __('order_operations.lifecycle_labels.assigned_van'),
                'state' => 'assigned',
            ];
        }

        if (
            $dispatch instanceof OrderDispatchState
            && (string) $dispatch->status === 'awaiting_dispatch'
        ) {
            return [
                'label' => strtolower((string) $order->channel) === 'b2b'
                    ? __('order_operations.lifecycle_labels.awaiting_dispatch_assignment')
                    : __('order_operations.lifecycle_labels.awaiting_driver_assignment'),
                'state' => 'pending',
            ];
        }

        if (in_array($status, ['pending', 'confirmed', 'preparing', 'ready'], true)) {
            return [
                'label' => strtolower((string) $order->channel) === 'b2b'
                    ? __('order_operations.lifecycle_labels.awaiting_dispatch_assignment')
                    : __('order_operations.lifecycle_labels.awaiting_driver_assignment'),
                'state' => 'pending',
            ];
        }

        return [
            'label' => $statusLabels[$status] ?? $this->operationBusinessLabel($status),
            'state' => $status,
        ];
    }

    private function operationBusinessLabel(mixed $value): string
    {
        $key = strtolower(trim((string) ($value ?? '')));
        if ($key === '') {
            return '—';
        }

        $translationKey = 'order_operations.business_labels.'.$key;
        $translated = __($translationKey);

        if ($translated !== $translationKey) {
            return $translated;
        }

        return app()->getLocale() === 'ar'
            ? 'غير محدد'
            : ucwords(str_replace(['_', '-', '.'], ' ', $key));
    }

    private function localizedOperationalValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $key = strtolower($raw);
        $translationKey = 'order_operations.business_labels.'.$key;
        $translated = __($translationKey);
        if ($translated !== $translationKey) {
            return $translated;
        }

        if (preg_match('/^[a-z0-9_.:-]+$/i', $raw) === 1) {
            return app()->getLocale() === 'ar'
                ? 'تحديث على الطلب'
                : ucwords(str_replace(['_', '-', '.'], ' ', $raw));
        }

        return $raw;
    }

    /** @return list<array<string,mixed>> */
    private function orderTimeline(Order $order, array $detail): array
    {
        $events = collect();

        foreach ($detail['history'] ?? [] as $entry) {
            $events->push([
                'kind' => 'status',
                'title' => $this->msg(
                    'تغيير حالة الطلب',
                    'Order status changed',
                ),
                'summary' => $this->operationBusinessLabel($entry['from'] ?? null).' → '.$this->operationBusinessLabel($entry['to'] ?? null),
                'note' => $this->localizedOperationalValue($entry['note'] ?? null),
                'actor' => null,
                'created_at' => $entry['created_at'] ?? null,
            ]);
        }

        foreach ($detail['assignments'] ?? [] as $entry) {
            $events->push([
                'kind' => 'driver',
                'title' => $this->msg('إجراء على السائق', 'Driver action'),
                'summary' => trim((string) ($entry['driver'] ?? '')).' · '.$this->operationBusinessLabel($entry['status'] ?? null),
                'note' => null,
                'actor' => null,
                'created_at' => $entry['assigned_at'] ?? null,
            ]);
            if (! empty($entry['completed_at'])) {
                $events->push([
                    'kind' => 'driver',
                    'title' => $this->msg('انتهاء إجراء السائق', 'Driver action completed'),
                    'summary' => trim((string) ($entry['driver'] ?? '')).' · '.$this->operationBusinessLabel($entry['status'] ?? null),
                    'note' => null,
                    'actor' => null,
                    'created_at' => $entry['completed_at'],
                ]);
            }
        }

        foreach ($detail['delivery_evidence'] ?? [] as $assignmentEvidence) {
            foreach ($assignmentEvidence['timeline'] ?? [] as $entry) {
                $events->push([
                    'kind' => 'driver_execution',
                    'title' => $this->msg('تنفيذ السائق', 'Driver execution'),
                    'summary' => trim((string) ($assignmentEvidence['driver_name'] ?? ''))
                        .' · '.$this->operationBusinessLabel($entry['from_status'] ?? null)
                        .' → '.$this->operationBusinessLabel($entry['to_status'] ?? null),
                    'note' => $this->localizedOperationalValue(
                        $entry['note'] ?? $entry['reason_code'] ?? null,
                    ),
                    'actor' => trim((string) ($assignmentEvidence['driver_name'] ?? '')),
                    'created_at' => $entry['captured_at'] ?? null,
                ]);
            }
        }

        foreach ($detail['van_assignments'] ?? [] as $entry) {
            $events->push([
                'kind' => 'van',
                'title' => $this->msg('إجراء على الفان', 'Van action'),
                'summary' => trim((string) ($entry['van'] ?? '')).' · '.$this->operationBusinessLabel($entry['status'] ?? null),
                'note' => $this->localizedOperationalValue($entry['reason'] ?? null),
                'actor' => null,
                'created_at' => $entry['assigned_at'] ?? null,
            ]);
            if (! empty($entry['ended_at'])) {
                $events->push([
                    'kind' => 'van',
                    'title' => $this->msg('انتهاء إسناد الفان', 'Van assignment ended'),
                    'summary' => trim((string) ($entry['van'] ?? '')).' · '.$this->operationBusinessLabel($entry['status'] ?? null),
                    'note' => $this->localizedOperationalValue($entry['reason'] ?? null),
                    'actor' => null,
                    'created_at' => $entry['ended_at'],
                ]);
            }
        }

        foreach ($detail['van_events'] ?? [] as $entry) {
            $events->push([
                'kind' => 'execution',
                'title' => $this->msg('تنفيذ الفان', 'Van execution'),
                'summary' => $this->operationBusinessLabel($entry['from_status'] ?? null).' → '.$this->operationBusinessLabel($entry['to_status'] ?? null),
                'note' => $this->localizedOperationalValue($entry['note'] ?? $entry['reason_code'] ?? null),
                'actor' => $entry['actor'] ?? null,
                'created_at' => $entry['captured_at'] ?? null,
            ]);
        }

        $invoiceIds = Invoice::query()
            ->where('order_id', $order->getKey())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $audits = AuditLog::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where(function ($query) use ($order, $invoiceIds): void {
                $query->where(function ($orderAudit) use ($order): void {
                    $orderAudit
                        ->where('audit_logs.auditable_type', Order::class)
                        ->where('audit_logs.auditable_id', $order->getKey());
                });
                if ($invoiceIds !== []) {
                    $query->orWhere(function ($invoiceAudit) use ($invoiceIds): void {
                        $invoiceAudit
                            ->where('audit_logs.auditable_type', Invoice::class)
                            ->whereIn('audit_logs.auditable_id', $invoiceIds);
                    });
                }
            })
            ->latest('audit_logs.id')
            ->get([
                'audit_logs.event',
                'audit_logs.after',
                'audit_logs.created_at',
                'users.name as actor_name',
            ]);

        foreach ($audits as $audit) {
            /** @var array<string,mixed> $after */
            $after = $audit->getAttribute('after') ?? [];
            $summaryParts = array_values(array_filter(array_map(
                fn ($value): ?string => $this->localizedOperationalValue($value),
                [
                    $after['status'] ?? null,
                    $after['routing_reason'] ?? null,
                    $after['reason'] ?? null,
                    $after['note'] ?? null,
                ],
            ), static fn ($value): bool => is_string($value) && trim($value) !== ''));

            $events->push([
                'kind' => 'audit',
                'title' => $this->timelineAuditLabel((string) $audit->event),
                'summary' => implode(' · ', array_map('strval', $summaryParts)),
                'note' => null,
                'actor' => trim((string) ($audit->actor_name ?? '')),
                'created_at' => $audit->created_at,
            ]);
        }

        return $events
            ->filter(static fn (array $event): bool => ! empty($event['created_at']))
            ->sortByDesc(static fn (array $event): string => (string) $event['created_at'])
            ->values()
            ->all();
    }

    private function timelineAuditLabel(string $event): string
    {
        if (str_ends_with($event, '.order_created')) {
            return $this->msg('إنشاء الطلب', 'Order created');
        }

        return match ($event) {
            'dashboard.order_updated' => $this->msg('تعديل تفاصيل الطلب', 'Order details updated'),
            'order.customer_service_owner.changed' => $this->msg('تحديث مسؤول خدمة العملاء', 'Customer Service owner updated'),
            'order.dispatch.manual_assigned' => $this->msg('توجيه الطلب', 'Order routed'),
            'order.dispatch.manual_cleared' => $this->msg('سحب توجيه الطلب', 'Order routing cleared'),
            'operations.order.driver_reminder_sent' => $this->msg('إرسال تذكير للسائق', 'Driver reminder sent'),
            'invoice.issued' => $this->msg('إصدار الفاتورة', 'Invoice issued'),
            'invoice.reissued' => $this->msg('إعادة إصدار الفاتورة', 'Invoice reissued'),
            'invoice.voided' => $this->msg('إلغاء الفاتورة', 'Invoice voided'),
            'invoice.settled' => $this->msg('تسوية الفاتورة', 'Invoice settled'),
            default => app()->getLocale() === 'ar'
                ? 'تحديث على الطلب'
                : ucwords(str_replace(['.', '_'], ' ', $event)),
        };
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

        return array_values(array_filter(
            $options,
            static fn (array $option): bool => ! in_array(
                (string) $option['code'],
                ['confirmed', 'preparing', 'ready'],
                true,
            ),
        ));
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

    /** @return list<array{code:string,label:string}> */
    private function availableOperationalChannels(User $actor, string $permission): array
    {
        $channels = [];
        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        $b2b = array_values(array_filter(
            $this->scope->allowedStoreIds($actor, $permission, 'b2b'),
            static fn (int $storeId): bool => $storeId === $principalStoreId,
        ));
        if ($b2b !== []) {
            $channels[] = [
                'code' => 'b2b',
                'label' => $this->msg('B2B · الجملة', 'B2B · Wholesale'),
            ];
        }

        if ($this->scope->allowedStoreIds($actor, $permission, 'b2c') !== []) {
            $channels[] = [
                'code' => 'b2c',
                'label' => $this->msg('B2C · التجزئة', 'B2C · Retail'),
            ];
        }

        abort_if($channels === [], 403);

        return $channels;
    }

    /** @param list<array{code:string,label:string}> $availableChannels */
    private function assertOperationalChannelAllowed(array $availableChannels, string $channel): void
    {
        $codes = array_column($availableChannels, 'code');

        // Order operations are deliberately single-channel. B2B and Retail must
        // never share one operational order workspace, including for SUPER_ADMIN.
        abort_if($channel === 'all', 403);
        abort_unless(in_array($channel, $codes, true), 403);
    }

    private function defaultOperationalChannel(User $actor): string
    {
        $channels = $this->availableOperationalChannels($actor, 'orders.view');

        return (string) $channels[0]['code'];
    }

    private function activateAssignedOrder(
        Order $order,
        User $actor,
        AuditLogger $audit,
        DashboardOperationalNotifier $notifier,
        Request $request,
    ): void {
        $result = DB::transaction(function () use ($order, $actor, $audit, $request): ?array {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $before = strtolower((string) $locked->status);

            if ($before === 'out_for_delivery') {
                return null;
            }

            abort_unless(
                in_array($before, ['pending', 'confirmed', 'preparing', 'ready', 'failed'], true),
                409,
                'Order is not available for fulfillment assignment.',
            );

            if ($before === 'pending' && strtolower((string) $locked->channel) === 'b2b') {
                $this->assertB2bAssignmentFinance($locked);
            }

            $locked->forceFill(['status' => 'out_for_delivery'])->save();

            app(InvoiceService::class)->issueForOrder($locked, $actor);

            OrderStatusHistory::query()->create([
                'order_id' => $locked->getKey(),
                'store_id' => (int) $locked->store_id,
                'user_id' => $actor->getKey(),
                'from_status' => $before,
                'to_status' => 'out_for_delivery',
                'note' => 'fulfillment_assigned_auto_finalized',
            ]);

            $audit->record(
                'order.status_changed',
                $actor,
                $locked,
                ['status' => $before],
                [
                    'status' => 'out_for_delivery',
                    'source' => 'fulfillment_assignment',
                    'note' => 'fulfillment_assigned_auto_finalized',
                ],
                $request,
            );

            return ['order' => $locked->fresh(), 'from' => $before];
        }, 3);

        if (is_array($result) && ($result['order'] ?? null) instanceof Order) {
            $notifier->orderStatusChanged($result['order'], (string) $result['from'], 'out_for_delivery');
        }
    }

    private function assertB2bAssignmentFinance(Order $order): void
    {
        if ((string) $order->payment_method !== 'account_credit') {
            return;
        }

        $customer = B2bCustomer::query()->findOrFail((int) $order->b2b_customer_id);
        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (! $account instanceof B2bAccount) {
            abort(409, 'The B2B account is no longer active and cannot be assigned for account credit.');
        }

        $finance = $this->accountLedger->summary($customer, (int) $order->store_id);
        $alreadyInLedger = Invoice::query()
            ->where('order_id', $order->getKey())
            ->whereIn('status', ['issued', 'reissued'])
            ->exists();

        if ($alreadyInLedger) {
            abort_if(
                (float) $finance['outstanding_receivable'] > (float) $finance['credit_limit'] + 0.0001,
                409,
                'Account credit exposure exceeds the current authoritative credit limit.',
            );

            return;
        }

        abort_if(
            (float) $order->grand_total > (float) $finance['purchasing_power'] + 0.0001,
            409,
            'Account credit is insufficient for the authoritative order total at assignment time.',
        );
    }

    private function orderCanBeDispatched(Order $order): bool
    {
        return in_array(
            strtolower((string) $order->status),
            ['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'failed'],
            true,
        );
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
