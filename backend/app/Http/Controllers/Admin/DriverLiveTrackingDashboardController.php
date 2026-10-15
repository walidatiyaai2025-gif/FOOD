<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DriverDeliveryEvidenceService;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use App\Support\DisplayNumber;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DriverLiveTrackingDashboardController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly TenantContextResolver $tenantResolver,
        private readonly OperationalTenantScope $operationalScope,
        private readonly DriverDeliveryEvidenceService $evidence,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->canView($user), 403);

        $storeIds = array_values(array_unique(array_merge(
            $this->operationalScope->allowedStoreIds($user, 'drivers.tracking.view', 'b2b'),
            $this->operationalScope->allowedStoreIds($user, 'drivers.tracking.view', 'b2c'),
        )));
        $trackingStores = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(static fn (object $store): array => [
                'id' => (int) $store->id,
                'name' => (string) $store->name,
                'code' => (string) ($store->code ?? ''),
            ])
            ->all();

        return view('admin.driver-live-tracking', [
            'user' => $user,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => 'driver_live_tracking',
            'feedUrl' => route('admin.driver-live-tracking.feed'),
            'vanFeedUrl' => route('admin.field-operations.fleet.feed'),
            'lookupUrl' => route('admin.driver-live-tracking.lookups'),
            'trackingStores' => $trackingStores,
            'trackingI18n' => [
                'noDrivers' => __('admin.driver_live_tracking.no_drivers'),
                'loading' => __('admin.driver_live_tracking.loading'),
                'failed' => __('admin.driver_live_tracking.load_failed'),
                'ready' => __('admin.driver_live_tracking.ready'),
                'store' => __('admin.driver_live_tracking.store'),
                'status' => __('admin.driver_live_tracking.status'),
                'statuses' => [
                    'online' => __('admin.driver_live_tracking.online'),
                    'stale' => __('admin.driver_live_tracking.stale'),
                    'offline' => __('admin.driver_live_tracking.offline'),
                ],
                'order' => __('admin.driver_live_tracking.order'),
                'accuracy' => __('admin.driver_live_tracking.accuracy'),
                'speed' => __('admin.driver_live_tracking.speed'),
                'lastSeen' => __('admin.driver_live_tracking.last_seen'),
                'driver' => __('admin.driver_live_tracking.driver'),
                'van' => __('admin.driver_live_tracking.van'),
                'entities' => __('admin.driver_live_tracking.entities'),
                'entityType' => __('admin.driver_live_tracking.entity_type'),
                'allEntities' => __('admin.driver_live_tracking.all_entities'),
                'route' => __('admin.driver_live_tracking.route'),
                'assignment' => __('admin.driver_live_tracking.assignment'),
            ],
        ]);
    }

    public function lookups(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->canView($user), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['entity', 'driver', 'van', 'order', 'route'])],
            'q' => ['nullable', 'string', 'max:80'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = trim((string) ($data['q'] ?? ''));
        $scopes = $this->trackingScopes(
            $user,
            isset($data['channel']) ? (string) $data['channel'] : null,
            isset($data['store_id']) ? (int) $data['store_id'] : null,
        );
        $kind = (string) $data['kind'];

        $options = match ($kind) {
            'driver' => $this->driverLookup($scopes, $query, 20),
            'van' => $this->vanLookup($user, $scopes, $query, 20),
            'order' => $this->orderLookup($scopes, $query, 20),
            'route' => $this->routeLookup($user, $scopes, $query, 20),
            'entity' => array_slice([
                ...$this->driverLookup($scopes, $query, 10),
                ...$this->vanLookup($user, $scopes, $query, 10),
            ], 0, 20),
            default => throw new \LogicException('Validated tracking lookup kind is unsupported.'),
        };

        return response()->json([
            'data' => $options,
            'meta' => ['limit' => 20],
        ]);
    }

    public function evidence(Request $request, int $assignment): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'data' => $this->evidence->assignment($user, $assignment),
        ]);
    }

    public function proof(Request $request, int $assignment, int $proof): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $record = $this->evidence->proof($user, $assignment, $proof);
        $path = trim((string) $record->file_path);
        abort_unless($path !== '' && Storage::disk('public')->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = 'delivery-proof-'.$record->getKey().($extension === '' ? '' : '.'.$extension);

        return Storage::disk('public')->response($path, $filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string,list<int>>
     */
    private function trackingScopes(User $user, ?string $channel, ?int $storeId): array
    {
        $channels = $channel === null ? ['b2b', 'b2c'] : [$channel];
        $scopes = [];

        foreach ($channels as $scopeChannel) {
            $storeIds = $this->operationalScope->allowedStoreIds(
                $user,
                'drivers.tracking.view',
                $scopeChannel,
            );
            if ($storeIds !== []) {
                $scopes[$scopeChannel] = $storeIds;
            }
        }

        abort_if($scopes === [], 403);

        if ($storeId !== null) {
            foreach ($scopes as $scopeChannel => $storeIds) {
                if (in_array($storeId, $storeIds, true)) {
                    $scopes[$scopeChannel] = [$storeId];
                } else {
                    unset($scopes[$scopeChannel]);
                }
            }
            abort_if($scopes === [], 404);
        }

        return $scopes;
    }

    /**
     * @param  array<string,list<int>>  $scopes
     * @return list<array<string,mixed>>
     */
    private function driverLookup(array $scopes, string $query, int $limit): array
    {
        return DB::table('driver_current_locations as locations')
            ->join('drivers', 'drivers.id', '=', 'locations.driver_id')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->join('stores', 'stores.id', '=', 'locations.store_id')
            ->where(function ($scope) use ($scopes): void {
                foreach ($scopes as $channel => $storeIds) {
                    $scope->orWhere(function ($row) use ($channel, $storeIds): void {
                        $row->where('locations.channel', $channel)
                            ->whereIn('locations.store_id', $storeIds);
                    });
                }
            })
            ->when($query !== '', function ($builder) use ($query): void {
                $prefix = $this->likePrefix($query);
                $builder->where(function ($search) use ($prefix): void {
                    $search->where('users.name', 'like', $prefix.'%')
                        ->orWhere('users.email', 'like', $prefix.'%');
                });
            })
            ->orderBy('users.name')
            ->limit($limit)
            ->get([
                'drivers.id',
                'users.name',
                'users.email',
                'stores.name as store_name',
                'locations.channel',
            ])
            ->map(static fn (object $row): array => [
                'kind' => 'driver',
                'value' => (int) $row->id,
                'label' => (string) $row->name,
                'context' => implode(' · ', array_values(array_filter([
                    (string) $row->email,
                    (string) $row->store_name,
                    strtoupper((string) $row->channel),
                ], static fn (string $value): bool => $value !== ''))),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,list<int>>  $scopes
     * @return list<array<string,mixed>>
     */
    private function vanLookup(User $user, array $scopes, string $query, int $limit): array
    {
        $includeUnscoped = $user->hasRole('SUPER_ADMIN');

        return DB::table('fleet_current_locations as locations')
            ->join('vans', 'vans.id', '=', 'locations.actor_id')
            ->leftJoin('van_assignments as assignments', 'assignments.id', '=', 'locations.assignment_id')
            ->leftJoin('users as operators', 'operators.id', '=', 'assignments.representative_user_id')
            ->leftJoin('stores', 'stores.id', '=', 'locations.store_id')
            ->where('locations.actor_type', 'van')
            ->where(function ($scope) use ($scopes, $includeUnscoped): void {
                foreach ($scopes as $channel => $storeIds) {
                    $scope->orWhere(function ($row) use ($channel, $storeIds): void {
                        $row->where('locations.channel', $channel)
                            ->whereIn('locations.store_id', $storeIds);
                    });
                }
                if ($includeUnscoped) {
                    $scope->orWhereNull('locations.store_id');
                }
            })
            ->when($query !== '', function ($builder) use ($query): void {
                $prefix = $this->likePrefix($query);
                $builder->where(function ($search) use ($prefix): void {
                    $search->where('vans.code', 'like', $prefix.'%')
                        ->orWhere('vans.plate_number', 'like', $prefix.'%')
                        ->orWhere('operators.name', 'like', $prefix.'%')
                        ->orWhere('operators.email', 'like', $prefix.'%');
                });
            })
            ->orderByDesc('locations.received_at')
            ->limit($limit)
            ->get([
                'vans.id',
                'vans.code',
                'vans.plate_number',
                'operators.name as operator_name',
                'operators.email as operator_email',
                'stores.name as store_name',
                'locations.channel',
            ])
            ->map(static function (object $row): array {
                $label = trim((string) $row->code);
                if ($row->plate_number !== null && trim((string) $row->plate_number) !== '') {
                    $label .= ' · '.trim((string) $row->plate_number);
                }

                return [
                    'kind' => 'van',
                    'value' => (int) $row->id,
                    'label' => $label,
                    'context' => implode(' · ', array_values(array_filter([
                        trim((string) ($row->operator_name ?? '')),
                        trim((string) ($row->operator_email ?? '')),
                        trim((string) ($row->store_name ?? '')),
                        strtoupper(trim((string) ($row->channel ?? ''))),
                    ], static fn (string $value): bool => $value !== ''))),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,list<int>>  $scopes
     * @return list<array<string,mixed>>
     */
    private function orderLookup(array $scopes, string $query, int $limit): array
    {
        $referenceId = DisplayNumber::referenceId($query);

        return DB::table('orders')
            ->leftJoin('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
            ->leftJoin('b2c_customers', 'b2c_customers.id', '=', 'orders.b2c_customer_id')
            ->leftJoin('customers as legacy_customers', 'legacy_customers.id', '=', 'orders.customer_id')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->where(function ($scope) use ($scopes): void {
                foreach ($scopes as $channel => $storeIds) {
                    $scope->orWhere(function ($row) use ($channel, $storeIds): void {
                        $row->where('orders.channel', $channel)
                            ->whereIn('orders.store_id', $storeIds);
                    });
                }
            })
            ->when($query !== '', function ($builder) use ($query, $referenceId): void {
                $prefix = $this->likePrefix($query);
                $builder->where(function ($search) use ($prefix, $referenceId): void {
                    $search->where('orders.order_number', 'like', $prefix.'%');
                    if ($referenceId !== null) {
                        $search->orWhere('orders.id', $referenceId);
                    }
                });
            })
            ->orderByDesc('orders.created_at')
            ->limit($limit)
            ->get([
                'orders.id',
                'orders.order_number',
                'orders.channel',
                'orders.status',
                'b2b_customers.name as b2b_customer_name',
                'b2c_customers.name as b2c_customer_name',
                'legacy_customers.name as legacy_customer_name',
                'stores.name as store_name',
            ])
            ->map(function (object $row): array {
                $customer = strtolower((string) $row->channel) === 'b2b'
                    ? ($row->b2b_customer_name ?? $row->legacy_customer_name)
                    : ($row->b2c_customer_name ?? $row->legacy_customer_name);
                $status = (string) $row->status;

                return [
                    'kind' => 'order',
                    'value' => (int) $row->id,
                    'label' => DisplayNumber::reference($row->id).' · '.(string) $row->order_number,
                    'context' => implode(' · ', array_values(array_filter([
                        trim((string) ($customer ?? '')),
                        trim((string) $row->store_name),
                        strtoupper((string) $row->channel),
                        (string) __('admin.driver_live_tracking.order_statuses.'.$status),
                    ], static fn (string $value): bool => $value !== ''))),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,list<int>>  $scopes
     * @return list<array<string,mixed>>
     */
    private function routeLookup(User $user, array $scopes, string $query, int $limit): array
    {
        $includeUnscoped = $user->hasRole('SUPER_ADMIN');

        return DB::table('fleet_current_locations as locations')
            ->join('vans', 'vans.id', '=', 'locations.actor_id')
            ->leftJoin('van_assignments as assignments', 'assignments.id', '=', 'locations.assignment_id')
            ->leftJoin('users as operators', 'operators.id', '=', 'assignments.representative_user_id')
            ->leftJoin('stores', 'stores.id', '=', 'locations.store_id')
            ->where('locations.actor_type', 'van')
            ->whereNotNull('locations.route_key')
            ->where('locations.route_key', '<>', '')
            ->where(function ($scope) use ($scopes, $includeUnscoped): void {
                foreach ($scopes as $channel => $storeIds) {
                    $scope->orWhere(function ($row) use ($channel, $storeIds): void {
                        $row->where('locations.channel', $channel)
                            ->whereIn('locations.store_id', $storeIds);
                    });
                }
                if ($includeUnscoped) {
                    $scope->orWhereNull('locations.store_id');
                }
            })
            ->when($query !== '', function ($builder) use ($query): void {
                $prefix = $this->likePrefix($query);
                $builder->where(function ($search) use ($prefix): void {
                    $search->where('locations.route_key', 'like', $prefix.'%')
                        ->orWhere('assignments.territory_key', 'like', $prefix.'%')
                        ->orWhere('vans.code', 'like', $prefix.'%')
                        ->orWhere('vans.plate_number', 'like', $prefix.'%')
                        ->orWhere('stores.name', 'like', $prefix.'%');
                });
            })
            ->orderByDesc('locations.received_at')
            ->limit(max($limit * 2, 20))
            ->get([
                'locations.route_key',
                'locations.channel',
                'assignments.territory_key',
                'vans.code as van_code',
                'vans.plate_number',
                'operators.name as operator_name',
                'stores.name as store_name',
            ])
            ->unique(static fn (object $row): string => (string) $row->route_key)
            ->take($limit)
            ->map(static function (object $row): array {
                $routeKey = trim((string) $row->route_key);
                $territory = trim((string) ($row->territory_key ?? ''));
                $label = $territory !== '' ? $territory : $routeKey;

                return [
                    'kind' => 'route',
                    'value' => $routeKey,
                    'label' => $label,
                    'context' => implode(' · ', array_values(array_filter([
                        $territory !== '' && $territory !== $routeKey ? $routeKey : '',
                        trim((string) ($row->van_code ?? '')),
                        trim((string) ($row->plate_number ?? '')),
                        trim((string) ($row->operator_name ?? '')),
                        trim((string) ($row->store_name ?? '')),
                        strtoupper(trim((string) ($row->channel ?? ''))),
                    ], static fn (string $value): bool => $value !== ''))),
                ];
            })
            ->values()
            ->all();
    }

    private function likePrefix(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function canView(User $user): bool
    {
        if ($user->hasPermission('drivers.tracking.view')) {
            return true;
        }

        $retailStoreIds = $this->tenantResolver->retailStoreIds($user);
        if ($retailStoreIds === []) {
            return false;
        }

        return $user->storeRoleAssignments()
            ->whereIn('store_id', $retailStoreIds)
            ->whereHas('store', fn ($query) => $query->where('stores.is_active', true))
            ->whereHas('role', fn ($query) => $query
                ->where('roles.is_active', true)
                ->where('roles.scope', 'store')
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permissions.code', 'drivers.tracking.view')))
            ->exists();
    }
}
