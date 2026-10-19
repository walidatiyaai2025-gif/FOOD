<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\Models\RestaurantLocationProfile;
use App\Domain\RestaurantSaaS\RestaurantOnboardingService;
use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\LifecycleService;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantGuestService;
use App\Domain\SmartOrdering\RestaurantKitchenService;
use App\Domain\SmartOrdering\RestaurantPaymentService;
use App\Domain\SmartOrdering\RestaurantTableService;
use App\Domain\SmartOrdering\RestaurantWorkforceService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SmartOrderingOrderContext;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SmartOrderingOperationsController extends Controller
{
    public function __construct(
        private readonly RestaurantSaasStoreAccess $restaurantAccess,
        private readonly RestaurantOnboardingService $onboarding,
        private readonly AdminNavigation $navigation,
        private readonly LifecycleService $lifecycle,
        private readonly SmartOrderingSiteService $sites,
        private readonly RestaurantPaymentService $payments,
        private readonly RestaurantTableService $restaurantTables,
        private readonly RestaurantKitchenService $restaurantKitchen,
        private readonly RestaurantWorkforceService $restaurantWorkforce,
        private readonly RestaurantGuestService $restaurantGuests,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['NEW', 'CONFIRMED', 'PREPARING', 'READY', 'OUT_FOR_DELIVERY', 'COMPLETED', 'CANCELLED'])],
            'fulfillment_mode' => ['nullable', Rule::in(['dine_in', 'pickup', 'delivery'])],
            'branch' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'tab' => ['nullable', Rule::in(['orders', 'branches', 'tables', 'kitchen', 'workforce', 'reservations', 'waitlist', 'guest_book', 'payments'])],
            'payment_status' => ['nullable', Rule::in(['pending', 'authorized', 'captured', 'partially_refunded', 'refunded', 'voided', 'failed'])],
            'payment_provider' => ['nullable', 'string', 'max:80'],
            'reconciliation' => ['nullable', Rule::in(['mismatch'])],
        ]);

        $tab = (string) ($validated['tab'] ?? 'orders');

        $setupStoreIds = $this->restaurantAccess->allowedStoreIds($actor, 'smart_ordering.view', $request);
        $requestedStoreId = (int) $request->integer('store_id');
        $setupStoreId = $requestedStoreId > 0 && in_array($requestedStoreId, $setupStoreIds, true)
            ? $requestedStoreId
            : (int) ($setupStoreIds[0] ?? 0);
        $setupStore = Store::query()->findOrFail($setupStoreId);
        $restaurantSetupStatus = $this->onboarding->status($setupStore, $actor);
        $restaurantSetupManaged = ($restaurantSetupStatus['profile'] ?? null) instanceof RestaurantLocationProfile;
        $restaurantLaunched = ! $restaurantSetupManaged || (bool) ($restaurantSetupStatus['launched'] ?? false);

        if ($restaurantSetupManaged && ! $restaurantLaunched && $tab !== 'branches') {
            return redirect()->to(route('admin.smart-ordering.operations.index', array_filter([
                'store_id' => $setupStoreId,
                'tab' => 'branches',
                'support_access' => $request->boolean('support_access') ? 1 : null,
            ])). '#restaurant-setup-operations');
        }

        $permission = $tab === 'payments' ? 'finance.view' : 'orders.view';
        $storeIds = $this->restaurantAccess->allowedStoreIds($actor, $permission, $request);

        $query = DB::table('smart_ordering_order_contexts as soc')
            ->join('orders as o', 'o.id', '=', 'soc.order_id')
            ->join('smart_ordering_branches as b', 'b.id', '=', 'soc.branch_id')
            ->whereIn('o.store_id', $storeIds)
            ->when(isset($validated['status']), fn ($q) => $q->where('soc.lifecycle_status', $validated['status']))
            ->when(isset($validated['fulfillment_mode']), fn ($q) => $q->where('soc.fulfillment_mode', $validated['fulfillment_mode']))
            ->when(isset($validated['branch']), fn ($q) => $q->where('b.public_id', $validated['branch']))
            ->when(isset($validated['from']), fn ($q) => $q->whereDate('o.created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->whereDate('o.created_at', '<=', $validated['to']))
            ->orderByDesc('o.id')
            ->select([
                'soc.id as context_id',
                'soc.public_reference',
                'soc.lifecycle_status',
                'soc.fulfillment_mode',
                'soc.scheduled_for',
                'soc.notification_status',
                'soc.grand_total',
                'o.id as order_id',
                'o.order_number',
                'o.store_id',
                'o.currency',
                'o.created_at',
                'b.public_id as branch_public_id',
                DB::raw('COALESCE(b.name_en, b.name_ar) as branch_name'),
            ]);

        $orders = $query->paginate(25)->withQueryString();
        $rows = collect($orders->items())->map(function (object $row) use ($actor): array {
            $allowedTargets = $this->lifecycle->allowedTargets(
                (string) $row->lifecycle_status,
                (string) $row->fulfillment_mode,
            );

            return [
                ...((array) $row),
                'can_manage' => $actor->hasPermission('orders.manage', (int) $row->store_id)
                    || $actor->hasPermission('orders.manage'),
                'allowed_targets' => $allowedTargets,
                'transition_keys' => collect($allowedTargets)
                    ->mapWithKeys(fn (string $target): array => [$target => (string) Str::uuid()])
                    ->all(),
                'scheduled_display' => $row->scheduled_for
                    ? Carbon::parse((string) $row->scheduled_for)->timezone('Asia/Kuwait')->format('Y-m-d H:i')
                    : '—',
                'created_display' => Carbon::parse((string) $row->created_at)
                    ->timezone('Asia/Kuwait')
                    ->format('Y-m-d H:i'),
            ];
        })->all();

        $branches = SmartOrderingBranch::query()
            ->with('site')
            ->whereHas('site', fn ($query) => $query->whereIn('store_id', $storeIds))
            ->orderBy('name_en')
            ->get();
        $stores = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        $tableWorkspace = [
            'selected_branch' => null,
            'floors' => [],
            'unassigned_tables' => [],
            'tables' => [],
            'active_combinations' => [],
            'can_manage' => false,
            'can_manage_qr' => false,
        ];
        if ($tab === 'tables') {
            $tableWorkspace = $this->restaurantTables->workspace(
                $branches,
                $validated['branch'] ?? null,
                $actor,
            );
        }

        $guestWorkspace = [
            'selected_branch' => null,
            'tables' => [],
            'records' => null,
            'can_manage' => false,
            'module_enabled' => false,
        ];
        if (in_array($tab, ['reservations', 'waitlist', 'guest_book'], true)) {
            $guestWorkspace = $this->restaurantGuests->workspace(
                $branches,
                $validated['branch'] ?? null,
                $actor,
                $tab,
            );
        }

        $workforceWorkspace = [
            'selected_branch' => null,
            'timezone' => null,
            'can_manage' => false,
            'actor_assigned' => false,
            'available_staff' => [],
            'assignments' => [],
            'shifts' => [],
            'sessions' => [],
            'current_session' => null,
        ];
        if ($tab === 'workforce') {
            $workforceWorkspace = $this->restaurantWorkforce->workspace(
                $branches,
                $validated['branch'] ?? null,
                $actor,
            );
        }

        $kitchenWorkspace = [
            'selected_branch' => null,
            'entitled' => false,
            'can_manage' => false,
            'routing_entitled' => false,
            'stations' => [],
            'rules' => [],
            'tickets' => [],
            'products' => [],
            'categories' => [],
        ];
        if ($tab === 'kitchen') {
            $kitchenWorkspace = $this->restaurantKitchen->workspace($branches, $validated['branch'] ?? null, $actor);
        }

        $paymentPaginator = null;
        $paymentRows = [];
        $paymentProviderOptions = [];
        if ($tab === 'payments') {
            $paymentProviderOptions = collect($this->payments->providerOptions($storeIds))
                ->map(fn (string $provider): array => [
                    'value' => $provider,
                    'label' => $this->paymentProviderLabel($provider),
                ])
                ->all();
            [$paymentPaginator, $paymentRows] = $this->payments->reconciliationPage(
                $storeIds,
                [
                    'payment_status' => $validated['payment_status'] ?? null,
                    'payment_provider' => $validated['payment_provider'] ?? null,
                    'reconciliation' => $validated['reconciliation'] ?? null,
                ],
                25,
            );
            $paymentRows = collect($paymentRows)->map(function (array $row) use ($actor): array {
                $status = (string) $row['status'];
                $capabilities = (array) ($row['capabilities'] ?? []);
                $actions = [];
                if (($capabilities['authorize'] ?? false) && in_array($status, ['pending', 'failed'], true)) {
                    $actions[] = 'authorize';
                }
                if (($capabilities['capture'] ?? false) && $status === 'authorized') {
                    $actions[] = 'capture';
                }
                if (($capabilities['void'] ?? false) && in_array($status, ['pending', 'authorized'], true)) {
                    $actions[] = 'void';
                }
                if (($capabilities['refund'] ?? false)
                    && in_array($status, ['captured', 'partially_refunded'], true)
                    && (float) ($row['remaining_refundable_amount'] ?? 0) > 0) {
                    $actions[] = 'refund';
                }

                return [
                    ...$row,
                    'provider_label' => $this->paymentProviderLabel((string) $row['provider']),
                    'can_manage' => $actor->hasPermission('finance.manage', (int) $row['store_id'])
                        || $actor->hasPermission('finance.manage'),
                    'actions' => $actions,
                    'idempotency_keys' => collect($actions)
                        ->mapWithKeys(fn (string $action): array => [$action => (string) Str::uuid()])
                        ->all(),
                ];
            })->all();
        }

        return view('admin.smart-ordering-operations', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'smart_ordering',
            'orders' => $orders,
            'rows' => $rows,
            'branches' => $branches,
            'stores' => $stores,
            'tab' => $tab,
            'tableWorkspace' => $tableWorkspace,
            'kitchenWorkspace' => $kitchenWorkspace,
            'workforceWorkspace' => $workforceWorkspace,
            'guestWorkspace' => $guestWorkspace,
            'paymentPaginator' => $paymentPaginator,
            'paymentRows' => $paymentRows,
            'paymentProviderOptions' => $paymentProviderOptions,
            'isAr' => app()->getLocale() === 'ar',
            'timezoneOptions' => $this->timezoneOptions(),
            'restaurantSetupStatus' => $restaurantSetupStatus,
            'restaurantLaunched' => $restaurantLaunched,
        ]);
    }

    public function storeBranch(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->branchData($request);
        $storeId = (int) $data['store_id'];
        $this->restaurantAccess->assertStore($actor, $storeId, 'orders.manage', 'b2c');

        $site = $this->sites->ensureForStore($storeId, $actor, $request);
        $branch = SmartOrderingBranch::query()->create([
            ...$this->branchAttributes($data),
            'public_id' => (string) Str::uuid(),
            'site_id' => $site->getKey(),
            'code' => 'branch-'.Str::lower(Str::random(10)),
        ]);
        $this->syncHours($branch, (array) ($data['hours'] ?? []));

        return redirect()->route('admin.smart-ordering.operations.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.branch_saved'));
    }

    public function updateBranch(string $branch, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $model = SmartOrderingBranch::query()->where('public_id', $branch)->firstOrFail();
        $this->restaurantAccess->assertStore($actor, (int) $model->store_id, 'orders.manage', 'b2c');

        $data = $this->branchData($request, false);
        $model->update($this->branchAttributes($data));
        $this->syncHours($model, (array) ($data['hours'] ?? []));

        return redirect()->route('admin.smart-ordering.operations.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.branch_saved'));
    }

    public function storeZone(string $branch, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $branchModel = SmartOrderingBranch::query()->where('public_id', $branch)->firstOrFail();
        $this->restaurantAccess->assertStore($actor, (int) $branchModel->store_id, 'orders.manage', 'b2c');
        $data = $this->zoneData($request);

        DB::table('smart_ordering_delivery_zones')->insert([
            'public_id' => (string) Str::uuid(),
            'branch_id' => $branchModel->getKey(),
            ...$this->zoneAttributes($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.smart-ordering.operations.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.zone_saved'));
    }

    public function updateZone(string $branch, string $zone, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $branchModel = SmartOrderingBranch::query()->where('public_id', $branch)->firstOrFail();
        $this->restaurantAccess->assertStore($actor, (int) $branchModel->store_id, 'orders.manage', 'b2c');
        $zoneModel = DB::table('smart_ordering_delivery_zones')
            ->where('public_id', $zone)
            ->where('branch_id', $branchModel->getKey())
            ->first();
        abort_unless($zoneModel !== null, 404);

        DB::table('smart_ordering_delivery_zones')
            ->where('id', $zoneModel->id)
            ->update([
                ...$this->zoneAttributes($this->zoneData($request)),
                'updated_at' => now(),
            ]);

        return redirect()->route('admin.smart-ordering.operations.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.zone_saved'));
    }

    public function destroyZone(string $branch, string $zone, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $branchModel = SmartOrderingBranch::query()->where('public_id', $branch)->firstOrFail();
        $this->restaurantAccess->assertStore($actor, (int) $branchModel->store_id, 'orders.manage', 'b2c');

        $deleted = DB::table('smart_ordering_delivery_zones')
            ->where('public_id', $zone)
            ->where('branch_id', $branchModel->getKey())
            ->delete();
        abort_unless($deleted === 1, 404);

        return redirect()->route('admin.smart-ordering.operations.index', ['tab' => 'branches'])
            ->with('status', __('smart_ordering.zone_deleted'));
    }

    public function transition(string $reference, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $context = SmartOrderingOrderContext::query()
            ->where('public_reference', $reference)
            ->firstOrFail();
        $order = DB::table('orders')->where('id', $context->order_id)->first(['store_id']);
        abort_unless($order !== null, 404);
        $this->restaurantAccess->assertStore($actor, (int) $order->store_id, 'orders.manage', 'b2c');

        $data = $request->validate([
            'status' => ['required', Rule::in(['CONFIRMED', 'PREPARING', 'READY', 'OUT_FOR_DELIVERY', 'COMPLETED', 'CANCELLED'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'transition_key' => ['nullable', 'string', 'max:100'],
        ]);

        $this->lifecycle->transition(
            $context,
            $actor,
            (string) $data['status'],
            isset($data['note']) ? (string) $data['note'] : null,
            isset($data['transition_key']) ? (string) $data['transition_key'] : null,
        );

        return redirect()->back()->with('status', __('smart_ordering.status_updated'));
    }

    public function paymentOperation(int $payment, string $operation, Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $model = Payment::query()->whereKey($payment)->firstOrFail();
        abort_if($model->order_id === null, 404);
        $order = DB::table('orders')->where('id', $model->order_id)->first(['store_id']);
        abort_unless($order !== null, 404);
        abort_unless(
            DB::table('smart_ordering_order_contexts')->where('order_id', $model->order_id)->exists(),
            404,
        );
        $this->restaurantAccess->assertStore($actor, (int) $order->store_id, 'finance.manage', 'b2c');

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:160'],
            'amount' => ['nullable', 'string', 'regex:/^\\d{1,11}(?:\\.\\d{1,3})?$/'],
        ]);

        $this->payments->execute(
            $model,
            $operation,
            isset($data['amount']) ? (string) $data['amount'] : null,
            (string) $data['idempotency_key'],
            $actor,
        );

        return redirect()
            ->route('admin.smart-ordering.operations.index', ['tab' => 'payments'])
            ->with('status', __('smart_ordering.payments.operation_saved'));
    }

    private function paymentProviderLabel(string $provider): string
    {
        $key = 'smart_ordering.payments.providers.'.$provider;
        $translated = __($key);

        return $translated === $key ? Str::headline($provider) : $translated;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @return array<string,mixed> */
    private function branchData(Request $request, bool $withStore = true): array
    {
        return $request->validate([
            'store_id' => [$withStore ? 'required' : 'sometimes', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:160'],
            'timezone' => ['required', 'timezone', Rule::in($this->supportedTimezones())],
            'is_active' => ['nullable', 'boolean'],
            'accepting_orders' => ['nullable', 'boolean'],
            'supports_dine_in' => ['nullable', 'boolean'],
            'supports_pickup' => ['nullable', 'boolean'],
            'supports_delivery' => ['nullable', 'boolean'],
            'allow_scheduled' => ['nullable', 'boolean'],
            'pickup_lead_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'max_schedule_days' => ['required', 'integer', 'min:1', 'max:90'],
            'max_active_orders' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'capacity_window_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'dine_in_promise_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'pickup_promise_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'delivery_promise_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'service_charge' => ['required', 'numeric', 'min:0', 'max:999999'],
            'whatsapp_routing_identity' => ['nullable', 'string', 'max:190'],
            'hours' => ['nullable', 'array'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['nullable', 'boolean'],
        ]);
    }

    /** @return list<string> */
    private function supportedTimezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /** @return list<array{value:string,label:string,search:string}> */
    private function timezoneOptions(): array
    {
        $now = new DateTimeImmutable('now');

        return collect($this->supportedTimezones())
            ->map(function (string $identifier) use ($now): array {
                $local = $now->setTimezone(new DateTimeZone($identifier));
                $human = collect(explode('/', $identifier))
                    ->map(fn (string $part): string => str_replace('_', ' ', $part))
                    ->implode(' · ');
                $offset = 'UTC'.$local->format('P');

                return [
                    'value' => $identifier,
                    'label' => $human.' · '.$offset.' · '.$identifier,
                    'search' => mb_strtolower($human.' '.$offset.' '.$identifier),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function zoneData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'center_latitude' => ['required', 'numeric', 'between:-90,90'],
            'center_longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:999999'],
            'minimum_order' => ['required', 'numeric', 'min:0', 'max:999999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function zoneAttributes(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'center_latitude' => round((float) $data['center_latitude'], 7),
            'center_longitude' => round((float) $data['center_longitude'], 7),
            'radius_km' => round((float) $data['radius_km'], 3),
            'delivery_fee' => round((float) $data['delivery_fee'], 3),
            'minimum_order' => round((float) $data['minimum_order'], 3),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function branchAttributes(array $data): array
    {
        return [
            'name_ar' => (string) $data['name'],
            'name_en' => (string) $data['name'],
            'timezone' => (string) $data['timezone'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'accepting_orders' => (bool) ($data['accepting_orders'] ?? false),
            'supports_dine_in' => (bool) ($data['supports_dine_in'] ?? false),
            'supports_pickup' => (bool) ($data['supports_pickup'] ?? false),
            'supports_delivery' => (bool) ($data['supports_delivery'] ?? false),
            'allow_scheduled' => (bool) ($data['allow_scheduled'] ?? false),
            'pickup_lead_minutes' => (int) $data['pickup_lead_minutes'],
            'max_schedule_days' => (int) $data['max_schedule_days'],
            'max_active_orders' => (int) ($data['max_active_orders'] ?? 0),
            'capacity_window_minutes' => (int) ($data['capacity_window_minutes'] ?? 30),
            'dine_in_promise_minutes' => (int) ($data['dine_in_promise_minutes'] ?? 20),
            'pickup_promise_minutes' => (int) ($data['pickup_promise_minutes'] ?? 20),
            'delivery_promise_minutes' => (int) ($data['delivery_promise_minutes'] ?? 45),
            'service_charge' => round((float) $data['service_charge'], 3),
            'whatsapp_routing_identity' => trim((string) ($data['whatsapp_routing_identity'] ?? '')) ?: null,
        ];
    }

    /** @param array<int|string,mixed> $hours */
    private function syncHours(SmartOrderingBranch $branch, array $hours): void
    {
        foreach ($hours as $day => $settings) {
            $day = (int) $day;
            if ($day < 0 || $day > 6 || ! is_array($settings)) {
                continue;
            }
            DB::table('smart_ordering_branch_hours')->updateOrInsert(
                ['branch_id' => $branch->getKey(), 'day_of_week' => $day],
                [
                    'opens_at' => $settings['opens_at'] ?? null,
                    'closes_at' => $settings['closes_at'] ?? null,
                    'is_closed' => (bool) ($settings['is_closed'] ?? false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
