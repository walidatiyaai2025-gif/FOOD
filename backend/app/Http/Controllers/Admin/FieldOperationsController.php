<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\FleetLocationController;
use App\Http\Controllers\Controller;
use App\Models\AddressQualityReview;
use App\Models\FleetCurrentLocation;
use App\Models\GeographyNode;
use App\Models\Remittance;
use App\Models\Role;
use App\Models\RoutingPolicy;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use App\Models\VanNoOrderReason;
use App\Models\VanVisit;
use App\Services\AddressQualityService;
use App\Services\AuditLogger;
use App\Services\CollectionCustodyService;
use App\Services\CommercialFeatureFlags;
use App\Services\FieldOperationsFinanceService;
use App\Services\FleetLocationService;
use App\Services\OperationalTenantScope;
use App\Services\RoutingPolicyService;
use App\Services\TerritoryService;
use App\Services\VanCustomerCollectionContextService;
use App\Services\VanRegistryService;
use App\Services\VanVisitLifecycleService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FieldOperationsController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly CommercialFeatureFlags $featureFlags,
        private readonly FieldOperationsFinanceService $finance,
        private readonly VanRegistryService $registry,
        private readonly VanVisitLifecycleService $visitLifecycle,
        private readonly VanCustomerCollectionContextService $customerCollectionContext,
        private readonly TerritoryService $territoryService,
        private readonly AddressQualityService $addressQuality,
        private readonly AuditLogger $audit,
        private readonly RoutingPolicyService $routing,
        private readonly CollectionCustodyService $custody,
    ) {
        // Constructor promotion defines the complete immutable service dependencies.
    }

    public function overview(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['field_ops.manage', 'drivers.b2b.view', 'drivers.tracking.view', 'customers.view', 'territories.manage', 'finance.view']);

        $isSuper = $user->hasRole('SUPER_ADMIN');
        $canTrack = $isSuper || $user->hasPermission('drivers.tracking.view');
        $canDrivers = $isSuper || $user->hasPermission('drivers.b2b.view');
        $canVisits = $canDrivers && ($isSuper || $user->hasPermission('customers.view'));
        $canTerritories = $isSuper || $user->hasPermission('territories.manage') || $user->hasPermission('field_ops.manage');
        $canAddress = $isSuper || $user->hasPermission('customers.view');
        $locationHealth = ['online' => 0, 'stale' => 0, 'offline' => 0];
        if ($canTrack) {
            $locations = FleetCurrentLocation::query()->where('actor_type', 'van')->get();
            $fleetService = app(FleetLocationService::class);
            foreach ($locations as $location) {
                $status = $fleetService->status($location);
                if (array_key_exists($status, $locationHealth)) {
                    $locationHealth[$status]++;
                }
            }
        }

        $canFinance = $isSuper || $user->hasPermission('finance.view');
        $outstanding = $canFinance
            ? (float) DB::table('custody_ledger_entries')
                ->join('collection_accounts', 'collection_accounts.id', '=', 'custody_ledger_entries.collection_account_id')
                ->where('collection_accounts.actor_type', 'van')
                ->sum('custody_ledger_entries.amount')
            : 0.0;

        return $this->render($request, 'overview', [
            'summary' => [
                'active_vans' => $canDrivers ? Van::query()->where('status', 'active')->count() : 0,
                'suspended_vans' => $canDrivers ? Van::query()->where('status', 'suspended')->count() : 0,
                'assigned_vans' => $canDrivers ? VanAssignment::query()->where('status', 'active')->distinct()->count('van_id') : 0,
                'unassigned_vans' => $canDrivers ? Van::query()->whereDoesntHave('assignments', fn ($q) => $q->where('status', 'active'))->count() : 0,
                'operators' => $canDrivers ? VanAssignment::query()->where('status', 'active')->whereNotNull('representative_user_id')->distinct()->count('representative_user_id') : 0,
                'customers_served' => $canVisits
                    ? DB::query()->fromSub(
                        VanVisit::query()->select(['customer_type', 'customer_id'])->distinct(),
                        'served_customers',
                    )->count()
                    : 0,
                'active_visits' => $canVisits ? VanVisit::query()->whereIn('status', ['planned', 'started'])->count() : 0,
                'completed_visits' => $canVisits ? VanVisit::query()->whereIn('status', ['completed_with_order', 'completed_no_order', 'customer_unavailable', 'closed'])->count() : 0,
                'no_order_visits' => $canVisits ? VanVisit::query()->where('status', 'completed_no_order')->count() : 0,
                'outstanding_collections' => $outstanding,
                'territories' => $canTerritories ? ServiceTerritory::query()->where('status', 'active')->count() : 0,
                'unresolved_addresses' => $canAddress ? AddressQualityReview::query()->where('status', 'unmapped')->count() : 0,
                'location_health' => $locationHealth,
                'pending_remittances' => $canFinance ? Remittance::query()->where('status', 'pending')->count() : 0,
            ],
            'overviewVisibility' => [
                'drivers' => $canDrivers,
                'visits' => $canVisits,
                'tracking' => $canTrack,
                'territories' => $canTerritories,
                'address' => $canAddress,
                'finance' => $canFinance,
            ],
        ]);
    }

    public function fleet(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['drivers.tracking.view']);

        return $this->render($request, 'fleet', [
            'feedUrl' => route('admin.field-operations.fleet.feed'),
        ]);
    }

    public function fleetFeed(
        Request $request,
        FleetLocationController $fleet,
        FleetLocationService $service,
        OperationalTenantScope $scope,
    ): JsonResponse {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['drivers.tracking.view']);
        $request->merge(['actor_type' => 'van']);

        return $fleet->feed($request, $service, $scope);
    }

    public function vans(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['drivers.b2b.view']);

        $vans = Van::query()->with(['assignments' => fn ($q) => $q->orderByDesc('effective_from')])->orderBy('code')->paginate(25);
        $locations = FleetCurrentLocation::query()
            ->where('actor_type', 'van')
            ->whereIn('actor_id', $vans->getCollection()->pluck('id'))
            ->get()
            ->keyBy('actor_id');
        $warehouses = DB::table('warehouses')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
        $transferVans = Van::query()
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['id', 'code', 'plate_number']);

        return $this->render($request, 'vans', compact('vans', 'locations', 'warehouses', 'transferVans'));
    }

    public function showVan(Request $request, Van $van): View
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['drivers.b2b.view']);

        $van->load(['assignments' => fn ($query) => $query->orderByDesc('effective_from')]);
        $location = FleetCurrentLocation::query()
            ->where('actor_type', 'van')
            ->where('actor_id', $van->id)
            ->first();
        $locationStatus = $location === null ? null : app(FleetLocationService::class)->status($location);
        $homeWarehouseName = $van->home_warehouse_id === null
            ? null
            : DB::table('warehouses')->where('id', $van->home_warehouse_id)->value('name');
        $driverNames = DB::table('drivers')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->whereIn('drivers.id', $van->assignments->pluck('driver_id')->filter())
            ->pluck('users.name', 'drivers.id');
        $representativeNames = DB::table('users')
            ->whereIn('id', $van->assignments->pluck('representative_user_id')->filter())
            ->pluck('name', 'id');
        $territoryNames = ServiceTerritory::query()
            ->whereIn('code', $van->assignments->pluck('territory_key')->filter())
            ->get(['code', 'name_en', 'name_ar'])
            ->each(function (ServiceTerritory $territory): void {
                $territory->setAttribute(
                    'localized_name',
                    $this->localizedText($territory->name_ar, $territory->name_en),
                );
            })
            ->keyBy('code');

        return $this->render($request, 'van-detail', compact(
            'van',
            'location',
            'locationStatus',
            'homeWarehouseName',
            'driverNames',
            'representativeNames',
            'territoryNames',
        ));
    }

    public function storeVan(Request $request): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['drivers.b2b.manage']);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:vans,code'],
            'plate_number' => ['nullable', 'string', 'max:100', 'unique:vans,plate_number'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'capacity_units' => ['nullable', 'integer', 'min:0'],
            'capacity_weight' => ['nullable', 'numeric', 'min:0'],
            'home_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->registry->createVan($data);

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function suspendVan(Request $request, Van $van): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['drivers.b2b.manage']);

        $data = $request->validate([
            'transfer_target_van_id' => ['nullable', 'integer', 'exists:vans,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $target = isset($data['transfer_target_van_id']) ? Van::query()->findOrFail((int) $data['transfer_target_van_id']) : null;
        abort_if($target?->is($van), 422, 'Transfer target must be a different Van.');

        $this->registry->suspend($van, $target, $data['reason'] ?? null);

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function assignments(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['drivers.b2b.view']);

        $assignments = VanAssignment::query()->with('van')->orderByDesc('effective_from')->paginate(25);
        $vans = Van::query()->where('status', 'active')->orderBy('code')->get();
        $drivers = DB::table('drivers')->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->where('drivers.is_active', true)->orderBy('users.name')
            ->get(['drivers.id', 'drivers.user_id', 'users.name']);
        $territories = ServiceTerritory::query()
            ->where('status', 'active')
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_ar'])
            ->each(function (ServiceTerritory $territory): void {
                $territory->setAttribute(
                    'localized_name',
                    $this->localizedText($territory->name_ar, $territory->name_en),
                );
            });
        $representatives = DB::table('users')
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'email']);
        $warehouses = DB::table('warehouses')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return $this->render($request, 'assignments', compact(
            'assignments',
            'vans',
            'drivers',
            'territories',
            'representatives',
            'warehouses',
        ));
    }

    public function storeAssignment(Request $request, Van $van): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['drivers.b2b.manage']);

        $data = $request->validate([
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'representative_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'territory_key' => ['nullable', 'string', 'max:150'],
            'van_pool_key' => ['nullable', 'string', 'max:150'],
            'assignment_type' => ['required', Rule::in(['primary', 'backup'])],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'loaded_work_count' => ['nullable', 'integer', 'min:0'],
            'allow_van_app' => ['nullable', 'boolean'],
        ]);

        $assignment = $this->registry->assign($user, $van, $data);

        if (array_key_exists('allow_van_app', $data)) {
            $operatorUserId = null;
            if ($assignment->driver_id !== null) {
                $operatorUserId = DB::table('drivers')->where('id', $assignment->driver_id)->value('user_id');
            }
            $operatorUserId ??= $assignment->representative_user_id;

            if ((bool) $data['allow_van_app'] && $operatorUserId === null) {
                throw ValidationException::withMessages([
                    'allow_van_app' => [__('field_operations.van_app_operator_required')],
                ]);
            }

            if ($operatorUserId !== null) {
                $operator = User::query()->findOrFail((int) $operatorUserId);
                $vanRole = $this->ensureVanOperatorRole();
                $beforeAccess = $operator->hasPermission('van.login');
                if ((bool) $data['allow_van_app']) {
                    $operator->roles()->syncWithoutDetaching([$vanRole->id]);
                } else {
                    $operator->roles()->detach($vanRole->id);
                }
                $operator->unsetRelation('roles');
                $afterAccess = $operator->hasPermission('van.login');
                $this->audit->record('van.runtime_access.updated', $user, $operator, [
                    'van_login' => $beforeAccess,
                    'van_id' => (int) $van->id,
                    'assignment_id' => (int) $assignment->id,
                ], [
                    'van_login' => $afterAccess,
                    'van_id' => (int) $van->id,
                    'assignment_id' => (int) $assignment->id,
                ]);
            }
        }

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function customers(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAll($user, ['customers.view', 'drivers.b2b.view']);

        $latestIds = DB::table('van_visits')
            ->selectRaw('MAX(id) AS id')
            ->groupBy('customer_type', 'customer_id')
            ->pluck('id');

        $relationships = VanVisit::query()
            ->with('actor')
            ->whereIn('id', $latestIds)
            ->orderByDesc('updated_at')
            ->paginate(30);

        $actorIds = $relationships->getCollection()->pluck('actor_user_id')->unique()->values();
        $assignmentsByActor = VanAssignment::query()
            ->with('van')
            ->where('status', 'active')
            ->whereIn('representative_user_id', $actorIds)
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('representative_user_id');

        $canFinance = $user->hasRole('SUPER_ADMIN') || $user->hasPermission('finance.view');
        $relationships->setCollection($relationships->getCollection()->map(function (VanVisit $visit) use ($assignmentsByActor, $canFinance): VanVisit {
            $assignment = $assignmentsByActor->get($visit->actor_user_id)?->first();
            $visit->setAttribute('customer_display', $this->customerDisplay((string) $visit->customer_type, (int) $visit->customer_id));
            $visit->setRelation('servingAssignment', $assignment);
            $visit->setAttribute(
                'collection_context',
                $canFinance && $visit->store_id !== null
                    ? $this->customerCollectionContext->context((string) $visit->customer_type, (int) $visit->customer_id, (int) $visit->store_id)
                    : null,
            );

            return $visit;
        }));

        return $this->render($request, 'customers', compact('relationships'));
    }

    public function visits(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAll($user, ['drivers.b2b.view', 'customers.view']);

        $visits = VanVisit::query()->with(['actor', 'noOrderReason'])->orderByDesc('created_at')->paginate(30);
        $visits->setCollection($visits->getCollection()->map(function (VanVisit $visit): VanVisit {
            $visit->setAttribute('customer_display', $this->customerDisplay((string) $visit->customer_type, (int) $visit->customer_id));
            $reason = $visit->noOrderReason;
            if ($reason !== null) {
                $reason->setAttribute(
                    'localized_label',
                    $this->localizedText(
                        $reason->getAttribute('label_ar'),
                        $reason->getAttribute('label_en'),
                    ),
                );
            }

            return $visit;
        }));
        $assignments = $this->registry->effectiveAssignments();
        $reasons = VanNoOrderReason::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->each(function (VanNoOrderReason $reason): void {
                $reason->setAttribute(
                    'localized_label',
                    $this->localizedText($reason->label_ar, $reason->label_en),
                );
            });

        $visitCustomers = DB::table('b2b_customers')
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'email'])
            ->map(static fn (object $row): object => (object) [
                'type' => 'b2b',
                'id' => (int) $row->id,
                'label' => (string) ($row->name ?: $row->email ?: 'B2B customer'),
                'store_id' => null,
            ])
            ->concat(
                DB::table('b2c_customers')
                    ->orderBy('name')
                    ->limit(300)
                    ->get(['id', 'store_id', 'name', 'email'])
                    ->map(static fn (object $row): object => (object) [
                        'type' => 'b2c',
                        'id' => (int) $row->id,
                        'label' => (string) ($row->name ?: $row->email ?: 'B2C customer'),
                        'store_id' => (int) $row->store_id,
                    ]),
            )
            ->values();

        $visitStores = DB::table('stores')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $visitRoutes = VanVisit::query()
            ->get(['metadata'])
            ->flatMap(static function (VanVisit $visit): array {
                $rawMetadata = $visit->getAttribute('metadata');
                $metadata = is_array($rawMetadata) ? $rawMetadata : [];

                return [
                    trim((string) ($metadata['route_key'] ?? '')),
                    trim((string) ($metadata['route_code'] ?? '')),
                    trim((string) ($metadata['route'] ?? '')),
                ];
            })
            ->merge(
                FleetCurrentLocation::query()
                    ->where('actor_type', 'van')
                    ->whereNotNull('route_key')
                    ->pluck('route_key'),
            )
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $visitOrders = DB::table('orders')
            ->orderByDesc('created_at')
            ->limit(300)
            ->get(['id', 'order_number', 'status', 'store_id']);

        return $this->render($request, 'visits', compact(
            'visits',
            'assignments',
            'reasons',
            'visitCustomers',
            'visitStores',
            'visitRoutes',
            'visitOrders',
        ));
    }

    public function storeVisit(Request $request): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAll($user, ['drivers.b2b.manage', 'customers.view']);

        $data = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:van_assignments,id'],
            'customer_type' => ['required', Rule::in(['b2b', 'b2c'])],
            'customer_id' => ['required', 'integer', 'min:1'],
            'planned_at' => ['nullable', 'date'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'route_key' => ['nullable', 'string', 'max:128'],
        ]);

        $assignment = VanAssignment::query()->with('van')->findOrFail((int) $data['assignment_id']);
        abort_unless($assignment->status === 'active', 422, 'Only an active Van assignment can receive a planned visit.');

        $plannedAt = isset($data['planned_at']) ? Carbon::parse((string) $data['planned_at']) : now();
        abort_if($plannedAt->lt($assignment->effective_from), 422, 'Visit is before assignment start.');
        abort_if($assignment->effective_until !== null && $plannedAt->gte($assignment->effective_until), 422, 'Visit is outside assignment window.');

        $actorUserId = $assignment->representative_user_id;
        if ($actorUserId === null && $assignment->driver_id !== null) {
            $actorUserId = DB::table('drivers')->where('id', $assignment->driver_id)->value('user_id');
        }
        if ($actorUserId === null) {
            throw ValidationException::withMessages(['assignment_id' => ['Assignment has no operator user.']]);
        }

        $this->assertCustomerExists((string) $data['customer_type'], (int) $data['customer_id']);
        $storeId = $data['store_id'] ?? (
            (string) $data['customer_type'] === 'b2c'
                ? DB::table('b2c_customers')->where('id', $data['customer_id'])->value('store_id')
                : null
        );

        VanVisit::query()->create([
            'actor_user_id' => (int) $actorUserId,
            'customer_type' => (string) $data['customer_type'],
            'customer_id' => (int) $data['customer_id'],
            'store_id' => $storeId === null ? null : (int) $storeId,
            'status' => 'planned',
            'idempotency_key' => 'admin-'.Str::uuid(),
            'planned_at' => $plannedAt,
            'metadata' => [
                'van_id' => (int) $assignment->van_id,
                'van_assignment_id' => (int) $assignment->id,
                'territory_key' => $assignment->territory_key,
                'route_key' => $data['route_key'] ?? null,
                'planned_by_admin_user_id' => (int) $user->id,
            ],
        ]);

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function transitionVisit(Request $request, VanVisit $visit): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAll($user, ['drivers.b2b.manage', 'customers.view']);

        $data = $request->validate([
            'status' => ['required', 'string'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'no_order_reason_id' => ['nullable', 'integer', 'exists:van_no_order_reasons,id'],
        ]);

        $this->visitLifecycle->transition(
            $visit,
            (string) $data['status'],
            $user,
            isset($data['order_id']) ? (int) $data['order_id'] : null,
            isset($data['no_order_reason_id']) ? (int) $data['no_order_reason_id'] : null,
        );

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function territories(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['territories.manage', 'field_ops.manage']);

        $localizeNode = function (GeographyNode $node): GeographyNode {
            $node->setAttribute(
                'localized_name',
                $this->localizedText($node->name_ar, $node->name_en),
            );
            if ($node->parent !== null) {
                $node->parent->setAttribute(
                    'localized_name',
                    $this->localizedText(
                        $node->parent->getAttribute('name_ar'),
                        $node->parent->getAttribute('name_en'),
                    ),
                );
            }

            return $node;
        };

        // Full lookup collection remains available to the create/edit controls.
        // The visible hierarchy grid itself is server-paginated with its own page key.
        $nodes = GeographyNode::query()
            ->with('parent')
            ->orderBy('country_code')
            ->orderBy('type')
            ->orderBy('name_en')
            ->get()
            ->map($localizeNode);
        $nodeRows = GeographyNode::query()
            ->with('parent')
            ->orderBy('country_code')
            ->orderBy('type')
            ->orderBy('name_en')
            ->paginate(25, ['*'], 'geography_page')
            ->withQueryString();
        $nodeRows->setCollection($nodeRows->getCollection()->map($localizeNode));

        $localizeTerritory = function (ServiceTerritory $territory): ServiceTerritory {
            $territory->setAttribute(
                'localized_name',
                $this->localizedText($territory->name_ar, $territory->name_en),
            );

            return $territory;
        };

        // The map/editor needs the complete geometry set, while the record list is paginated.
        $territories = ServiceTerritory::query()
            ->with(['country', 'geometries'])
            ->orderByDesc('priority')
            ->orderBy('name_en')
            ->get()
            ->map($localizeTerritory);
        $territoryRows = ServiceTerritory::query()
            ->with(['country', 'geometries'])
            ->orderByDesc('priority')
            ->orderBy('name_en')
            ->paginate(25, ['*'], 'territory_page')
            ->withQueryString();
        $territoryRows->setCollection($territoryRows->getCollection()->map($localizeTerritory));

        return $this->render($request, 'territories', compact('nodes', 'nodeRows', 'territories', 'territoryRows'));
    }

    public function storeGeography(Request $request): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['territories.manage', 'field_ops.manage']);

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:geography_nodes,id'],
            'type' => ['required', Rule::in(['country', 'governorate', 'region', 'city', 'markaz', 'district', 'area'])],
            'code' => ['required', 'string', 'max:96'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'min:2', 'max:3'],
        ]);

        GeographyNode::query()->create([...$data, 'country_code' => strtoupper((string) $data['country_code']), 'is_active' => true]);

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function storeTerritory(Request $request): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['territories.manage', 'field_ops.manage']);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:96', 'unique:service_territories,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'country_node_id' => ['required', 'integer', 'exists:geography_nodes,id'],
            'governorate_node_id' => ['nullable', 'integer', 'exists:geography_nodes,id'],
            'city_node_id' => ['nullable', 'integer', 'exists:geography_nodes,id'],
            'district_node_id' => ['nullable', 'integer', 'exists:geography_nodes,id'],
            'default_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['required', Rule::in(['draft', 'active', 'inactive'])],
            'priority' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        ServiceTerritory::query()->create($data);

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function storeGeometry(Request $request, ServiceTerritory $territory): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['territories.manage', 'field_ops.manage']);

        $data = $request->validate([
            'geojson' => ['required', 'json'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
        ]);

        $geojson = json_decode((string) $data['geojson'], true, 512, JSON_THROW_ON_ERROR);
        $this->territoryService->addGeometry($territory, $geojson, $user, $data['effective_from'] ?? null, $data['effective_until'] ?? null);

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function addressQuality(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['customers.view']);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['unmapped', 'confirmed', 'rejected'])],
            'q' => ['nullable', 'string', 'max:150'],
        ]);
        $reviews = AddressQualityReview::query()->with('events')
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['q']), function ($q) use ($filters): void {
                $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
                $q->where(fn ($s) => $s->where('public_id', 'like', $needle)->orWhere('territory_key', 'like', $needle)->orWhere('reason', 'like', $needle));
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();
        $reviewRows = $reviews->getCollection();
        $resolvedByIds = $reviewRows
            ->pluck('resolved_by')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $reviewTerritoryCodes = $reviewRows
            ->pluck('territory_key')
            ->filter()
            ->map(static fn ($code): string => (string) $code)
            ->unique()
            ->values()
            ->all();

        $resolverNames = User::query()
            ->whereIn('id', $resolvedByIds)
            ->pluck('name', 'id');
        $territoryLabels = ServiceTerritory::query()
            ->whereIn('code', $reviewTerritoryCodes)
            ->get(['code', 'name_en', 'name_ar'])
            ->mapWithKeys(fn (ServiceTerritory $territory): array => [
                (string) $territory->code => $this->localizedText($territory->name_ar, $territory->name_en),
            ]);
        $territories = ServiceTerritory::query()
            ->where('status', 'active')
            ->orderBy('name_en')
            ->get(['code', 'name_en', 'name_ar'])
            ->each(function (ServiceTerritory $territory): void {
                $territory->setAttribute(
                    'localized_name',
                    $this->localizedText($territory->name_ar, $territory->name_en),
                );
            });

        return $this->render($request, 'address-quality', compact(
            'reviews',
            'filters',
            'territories',
            'resolverNames',
            'territoryLabels',
        ));
    }

    public function addressAction(Request $request, AddressQualityReview $review, string $action): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['customers.edit']);

        $data = $request->validate([
            'territory_key' => [$action === 'confirm' ? 'required' : 'nullable', 'string', 'max:150'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        match ($action) {
            'confirm' => $this->addressQuality->confirm($user, $review, (string) $data['territory_key'], (string) $data['reason']),
            'reject' => $this->addressQuality->reject($user, $review, (string) $data['reason']),
            'reopen' => $this->addressQuality->reopen($user, $review, (string) $data['reason']),
            default => abort(404),
        };

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function routingPolicies(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['territories.manage', 'field_ops.manage']);

        $policies = RoutingPolicy::query()->with('rules')->orderBy('code')->orderByDesc('version')->paginate(25);

        return $this->render($request, 'routing', compact('policies'));
    }

    public function storeRoutingPolicy(Request $request): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['territories.manage', 'field_ops.manage']);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'mode' => ['required', Rule::in(['MANUAL', 'AUTOMATIC', 'HYBRID'])],
            'rules' => ['required_without:rules_json', 'array', 'min:1'],
            'rules.*.name' => ['required', 'string', 'max:160'],
            'rules.*.condition_key' => ['nullable', 'string', 'max:160'],
            'rules.*.condition_value' => ['nullable', 'string', 'max:1000'],
            'rules.*.action_key' => ['nullable', 'string', 'max:160'],
            'rules.*.action_value' => ['nullable', 'string', 'max:1000'],
            'rules.*.enabled' => ['nullable', Rule::in(['0', '1'])],
            'rules_json' => [Rule::prohibitedIf(! $user->hasRole('SUPER_ADMIN')), 'nullable', 'json'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
        ]);

        $rules = $this->structuredRoutingRules($user, $data);

        $this->routing->createDraft($user, (string) $data['code'], (string) $data['mode'], $rules, $data['reason'] ?? null, $data['effective_from'] ?? null, $data['effective_until'] ?? null);

        return back()->with('status', __('admin.field_operations.saved'));
    }

    public function routingAction(Request $request, RoutingPolicy $routingPolicy, string $action): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['territories.manage', 'field_ops.manage']);

        if ($action === 'publish') {
            $this->routing->publish($user, $routingPolicy);

            return back()->with('status', __('admin.field_operations.saved'));
        }

        if ($action === 'rollback') {
            $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
            $this->routing->rollback($user, $routingPolicy, $data['reason'] ?? null);

            return back()->with('status', __('admin.field_operations.saved'));
        }

        if ($action === 'simulate') {
            $data = $request->validate([
                'input_keys' => ['required', 'array', 'min:1'],
                'input_keys.*' => ['nullable', 'string', 'max:160'],
                'input_values' => ['nullable', 'array'],
                'input_values.*' => ['nullable', 'string', 'max:1000'],
                'scope_keys' => ['nullable', 'array'],
                'scope_keys.*' => ['nullable', 'string', 'max:160'],
                'scope_values' => ['nullable', 'array'],
                'scope_values.*' => ['nullable', 'string', 'max:1000'],
                'at' => ['nullable', 'date'],
            ]);
            $input = $this->routingPairs($data['input_keys'] ?? [], $data['input_values'] ?? []);
            if ($input === []) {
                throw ValidationException::withMessages([
                    'input_keys' => [__('field_operations.simulation_input_required')],
                ]);
            }
            $scope = $this->routingPairs($data['scope_keys'] ?? [], $data['scope_values'] ?? []);
            $result = $this->routing->simulate($routingPolicy, $input, $scope, $data['at'] ?? null);

            return back()->with('simulation_result', $result)->with('simulation_policy', $routingPolicy->id);
        }

        abort(404);
    }

    public function finance(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['finance.view']);

        $filters = $request->validate([
            'ops_tab' => ['nullable', 'string', 'in:wallets,collections,remittances,reconciliation'],
            'ops_q' => ['nullable', 'string', 'max:120'],
            'ops_status' => ['nullable', 'string', 'max:32'],
            'ops_per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'ops_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $storeIds = $this->b2bStoreIds($user);
        $fieldFinance = $this->finance->viewModel($storeIds, $filters);
        $fieldFinance['can_manage'] = $user->hasRole('SUPER_ADMIN') || $user->hasPermission('finance.manage');

        return $this->render($request, 'finance', compact('fieldFinance'));
    }

    public function reviewRemittance(Request $request, Remittance $remittance, string $action): RedirectResponse
    {
        $user = $this->actor($request);
        $this->authorizeAny($user, ['finance.manage']);

        $account = DB::table('collection_accounts')->where('id', $remittance->collection_account_id)->first();
        abort_unless($account !== null && in_array((int) $account->store_id, $this->b2bStoreIds($user), true), 404);

        match ($action) {
            'approve' => $this->custody->approveRemittance($remittance, $user),
            'reject' => $this->custody->rejectRemittance($remittance, $user),
            'reconcile' => $this->custody->reconcileRemittance($remittance, $user),
            default => abort(404),
        };

        return back()->with('status', __('admin.field_operations.saved'));
    }

    private function render(Request $request, string $section, array $data = []): View
    {
        $user = $this->actor($request);

        return view('admin.field-operations', [
            ...$data,
            'user' => $user,
            'section' => $section,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => match ($section) {
                'van-detail' => 'field_ops_vans',
                'address-quality' => 'field_ops_address_quality',
                'routing' => 'field_ops_routing',
                default => 'field_ops_'.str_replace('-', '_', $section),
            },
            'featureFlags' => $this->featureFlags->snapshot(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function structuredRoutingRules(User $user, array $data): array
    {
        $advanced = $user->hasRole('SUPER_ADMIN') ? trim((string) ($data['rules_json'] ?? '')) : '';
        if ($advanced !== '') {
            $decoded = json_decode($advanced, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                throw ValidationException::withMessages([
                    'rules_json' => [__('field_operations.structured_rule_required')],
                ]);
            }

            return array_map(function (mixed $rule): array {
                if (! is_array($rule) || trim((string) ($rule['name'] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        'rules_json' => [__('field_operations.structured_rule_required')],
                    ]);
                }

                return [
                    'name' => trim((string) $rule['name']),
                    'conditions' => is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [],
                    'actions' => is_array($rule['actions'] ?? null) ? $rule['actions'] : [],
                    'enabled' => (bool) ($rule['enabled'] ?? true),
                ];
            }, $decoded);
        }

        return array_map(function (array $rule): array {
            $conditionKey = trim((string) ($rule['condition_key'] ?? ''));
            $actionKey = trim((string) ($rule['action_key'] ?? ''));

            return [
                'name' => trim((string) $rule['name']),
                'conditions' => $conditionKey === '' ? [] : [
                    $conditionKey => $this->routingScalar($rule['condition_value'] ?? null),
                ],
                'actions' => $actionKey === '' ? [] : [
                    $actionKey => $this->routingScalar($rule['action_value'] ?? null),
                ],
                'enabled' => (string) ($rule['enabled'] ?? '1') !== '0',
            ];
        }, array_values($data['rules'] ?? []));
    }

    /** @param array<int, mixed> $keys @param array<int, mixed> $values */
    private function routingPairs(array $keys, array $values): array
    {
        $pairs = [];
        foreach (array_values($keys) as $index => $rawKey) {
            $key = trim((string) $rawKey);
            if ($key === '') {
                continue;
            }
            $pairs[$key] = $this->routingScalar($values[$index] ?? null);
        }

        return $pairs;
    }

    private function routingScalar(mixed $raw): mixed
    {
        $value = trim((string) ($raw ?? ''));
        $lower = strtolower($value);

        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (preg_match('/^-?\\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    private function localizedText(?string $arabic, ?string $english): string
    {
        $primary = app()->getLocale() === 'ar' ? $arabic : $english;
        $fallback = app()->getLocale() === 'ar' ? $english : $arabic;

        return trim((string) ($primary ?: $fallback ?: ''));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function authorizeAny(User $user, array $permissions): void
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return;
            }
        }

        abort(403);
    }

    private function authorizeAll(User $user, array $permissions): void
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission) === false) {
                abort(403);
            }
        }
    }

    private function ensureVanOperatorRole(): Role
    {
        return DB::transaction(function (): Role {
            $now = now();

            DB::table('permissions')->updateOrInsert(
                ['code' => 'van.login'],
                [
                    'name' => 'Use the Van application runtime',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            DB::table('roles')->updateOrInsert(
                ['code' => 'VAN_OPERATOR'],
                [
                    'name' => 'Van App Operator',
                    'scope' => 'global',
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $role = Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail();
            $permissionId = DB::table('permissions')->where('code', 'van.login')->value('id');

            if ($permissionId !== null) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => (int) $permissionId,
                    'role_id' => (int) $role->id,
                ]);
            }

            return $role;
        }, 3);
    }

    private function customerDisplay(string $type, int $id): string
    {
        $table = $type === 'b2b' ? 'b2b_customers' : 'b2c_customers';
        $row = DB::table($table)->where('id', $id)->first(['id', 'name', 'email']);

        return $row === null ? strtoupper($type).' #'.$id : trim((string) ($row->name ?: $row->email ?: strtoupper($type).' #'.$id));
    }

    private function assertCustomerExists(string $type, int $id): void
    {
        $table = $type === 'b2b' ? 'b2b_customers' : 'b2c_customers';
        if (DB::table($table)->where('id', $id)->exists() === false) {
            throw ValidationException::withMessages(['customer_id' => ['Customer does not exist in the selected channel.']]);
        }
    }

    /** @return list<int> */
    private function b2bStoreIds(User $user): array
    {
        $query = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->orderBy('stores.id');

        if ($user->hasRole('SUPER_ADMIN') || $user->hasRole('B2B_ADMIN') || $user->hasRole('FINANCE') || $user->hasRole('OPERATIONS')) {
            return $query->pluck('stores.id')->map(fn ($id) => (int) $id)->all();
        }

        return $query
            ->join('user_store_roles', 'user_store_roles.store_id', '=', 'stores.id')
            ->where('user_store_roles.user_id', $user->id)
            ->pluck('stores.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
