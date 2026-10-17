<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class StoreChannelOperationsService
{
    private const REPORTS = ['performance', 'readiness', 'availability', 'integrations'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RetailWholesaleAccountService $wholesaleAccounts,
    ) {}

    /** @return array{from:string,to:string,kpis:array<string,mixed>,channels:list<array<string,mixed>>,charts:array<string,list<array<string,mixed>>>,reports:list<array<string,mixed>>} */
    public function dashboard(?string $fromInput = null, ?string $toInput = null): array
    {
        [$from, $to] = $this->window($fromInput, $toInput);
        $stores = $this->retailStores();
        $ids = $stores->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'kpis' => [
                    'active_stores' => 0, 'inactive_stores' => 0, 'sales_today' => [],
                    'sales_period' => [], 'orders_period' => 0, 'availability_issues' => 0,
                    'integration_degradation' => 0, 'ready_stores' => 0,
                ],
                'channels' => [],
                'charts' => [
                    'store_performance' => [], 'channel_performance' => [], 'health_distribution' => [],
                    'store_growth' => [], 'availability' => [], 'integration_trend' => [],
                ],
                'reports' => $this->reportCatalog(),
            ];
        }

        $orders = $this->ordersAggregate($ids, $from, $to);
        $today = $this->ordersAggregate($ids, CarbonImmutable::today(), CarbonImmutable::today());
        $snapshots = $this->snapshotMap($stores);
        $availability = $this->availabilityByStore($ids);
        $degraded = $this->degradedIntegrationsByStore($ids);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpis' => [
                'active_stores' => $stores->where('is_active', true)->count(),
                'inactive_stores' => $stores->where('is_active', false)->count(),
                'sales_today' => $today['by_currency'],
                'sales_period' => $orders['by_currency'],
                'orders_period' => (int) $orders['count'],
                'availability_issues' => array_sum($availability),
                'integration_degradation' => array_sum($degraded),
                'ready_stores' => collect($snapshots)->where('readiness_state', 'ready')->count(),
            ],
            'channels' => $this->channelState($stores, $ids),
            'charts' => [
                'store_performance' => $this->storePerformance($stores, $from, $to),
                'channel_performance' => $this->channelPerformance($ids, $from, $to),
                'health_distribution' => $this->healthDistribution($snapshots),
                'store_growth' => $this->storeGrowth($stores),
                'availability' => $stores->map(fn (object $store): array => [
                    'label' => (string) $store->name,
                    'value' => (int) ($availability[(int) $store->id] ?? 0),
                ])->values()->all(),
                'integration_trend' => $this->integrationTrend($ids, $from, $to),
            ],
            'reports' => $this->reportCatalog(),
        ];
    }

    /** @param Collection<int,Store> $stores */
    public function enrichStores(Collection $stores): Collection
    {
        if ($stores->isEmpty()) {
            return $stores;
        }

        $plain = $stores
            ->map(fn (Store $store): \stdClass => $this->storeSnapshotRow($store))
            ->values();
        $snapshots = $this->snapshotMap($plain);

        return $stores->transform(function (Store $store) use ($snapshots): Store {
            $store->setAttribute('operations_360', $snapshots[(int) $store->id] ?? []);

            return $store;
        });
    }

    /** @return array<string,mixed> */
    public function transition(User $actor, Store $store, string $action, string $reason, Request $request): array
    {
        $action = strtolower(trim($action));
        $reason = trim($reason);
        if (! in_array($action, ['activate', 'suspend', 'close'], true)) {
            throw ValidationException::withMessages(['lifecycle_action' => ['Unsupported store lifecycle action.']]);
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['lifecycle_reason' => ['A reason between 5 and 500 characters is required.']]);
        }

        $snapshot = $this->snapshotForStore($store);
        $beforeState = (string) ($snapshot['lifecycle_state'] ?? ($store->is_active ? 'ACTIVE' : 'SUSPENDED'));
        $beforeActive = (bool) $store->is_active;

        if ($beforeState === 'CLOSED') {
            throw ValidationException::withMessages(['lifecycle_action' => ['A closed store cannot be reactivated or suspended.']]);
        }

        if ($action === 'activate') {
            $failed = collect($snapshot['readiness'] ?? [])->filter(fn (array $row): bool => ! $row['ready'])->pluck('key')->values()->all();
            if ($failed !== []) {
                throw ValidationException::withMessages([
                    'lifecycle_action' => ['Activation is blocked until readiness checks pass: '.implode(', ', $failed).'.'],
                ]);
            }
        }

        if ($action === 'close') {
            $openOrders = Schema::hasTable('orders')
                ? DB::table('orders')
                    ->where('store_id', $store->id)
                    ->whereNotIn('status', ['delivered', 'cancelled', 'completed', 'closed'])
                    ->count()
                : 0;
            if ($openOrders > 0) {
                throw ValidationException::withMessages([
                    'lifecycle_action' => ["Close is blocked while {$openOrders} operational order(s) remain open."],
                ]);
            }
        }

        $afterState = match ($action) {
            'activate' => 'ACTIVE',
            'suspend' => 'SUSPENDED',
            'close' => 'CLOSED',
        };
        $isActive = $afterState === 'ACTIVE';

        DB::transaction(function () use ($actor, $store, $reason, $afterState, $isActive): void {
            $store->update(['is_active' => $isActive]);

            if (Schema::hasTable('store_channel_operational_profiles')) {
                DB::table('store_channel_operational_profiles')->updateOrInsert(
                    ['store_id' => (int) $store->id],
                    [
                        'lifecycle_state' => $afterState,
                        'reason' => $reason,
                        'changed_by' => (int) $actor->id,
                        'changed_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }

            $tierId = Schema::hasTable('retail_wholesale_accounts') && Schema::hasTable('b2b_accounts')
                ? DB::table('retail_wholesale_accounts as links')
                    ->leftJoin('b2b_accounts as accounts', 'accounts.b2b_customer_id', '=', 'links.b2b_customer_id')
                    ->where('links.retail_store_id', $store->id)
                    ->value('accounts.price_tier_id')
                : null;
            if ($tierId !== null) {
                $this->wholesaleAccounts->syncForStore($store->fresh(), (int) $tierId);
            }
        }, 3);

        $after = [
            'store_id' => (int) $store->id,
            'state' => $afterState,
            'reason' => $reason,
            'is_active' => $isActive,
        ];
        $this->audit->record(
            'retail_store.lifecycle_changed',
            $actor,
            $store->fresh(),
            ['store_id' => (int) $store->id, 'state' => $beforeState, 'is_active' => $beforeActive],
            $after,
            $request,
        );

        return $after;
    }

    /** @return list<array<string,mixed>> */
    public function reportRows(string $report, ?string $fromInput = null, ?string $toInput = null): array
    {
        if (! in_array($report, self::REPORTS, true)) {
            throw ValidationException::withMessages(['export' => ['Unknown Stores & Channels report.']]);
        }
        [$from, $to] = $this->window($fromInput, $toInput);
        $stores = $this->retailStores();
        $ids = $stores->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return match ($report) {
            'performance' => array_slice($this->storePerformance($stores, $from, $to), 0, 100),
            'readiness' => array_slice(array_values(array_map(function (array $row): array {
                return [
                    'store' => $row['name'],
                    'code' => $row['code'],
                    'lifecycle_state' => $row['lifecycle_state'],
                    'readiness_state' => $row['readiness_state'],
                    'readiness_score' => $row['readiness_score'],
                    'failed_checks' => implode(', ', collect($row['readiness'])->where('ready', false)->pluck('key')->all()),
                ];
            }, $this->snapshotMap($stores))), 0, 100),
            'availability' => $this->availabilityReport($ids),
            'integrations' => $this->integrationReport($ids),
        };
    }

    /** @return list<array{code:string,label:string}> */
    public function reportCatalog(): array
    {
        return [
            ['code' => 'performance', 'label' => 'store_channels.reports.performance'],
            ['code' => 'readiness', 'label' => 'store_channels.reports.readiness'],
            ['code' => 'availability', 'label' => 'store_channels.reports.availability'],
            ['code' => 'integrations', 'label' => 'store_channels.reports.integrations'],
        ];
    }

    /** @return array{CarbonImmutable,CarbonImmutable} */
    private function window(?string $fromInput, ?string $toInput): array
    {
        try {
            $to = $toInput ? CarbonImmutable::parse($toInput)->startOfDay() : CarbonImmutable::today();
            $from = $fromInput ? CarbonImmutable::parse($fromInput)->startOfDay() : $to->subDays(29);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['from' => ['Invalid report date range.']]);
        }
        if ($from->gt($to) || $from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['from' => ['The report range must be chronological and no longer than 366 days.']]);
        }

        return [$from, $to];
    }

    /** @return Collection<int,\stdClass> */
    private function retailStores(): Collection
    {
        return DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->orderBy('stores.name')
            ->get(['stores.id', 'stores.code', 'stores.name', 'stores.is_active', 'stores.logo_path', 'stores.created_at']);
    }

    /** @param list<int> $ids @return array{count:int,by_currency:array<string,float>} */
    private function ordersAggregate(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($ids === [] || ! Schema::hasTable('orders')) {
            return ['count' => 0, 'by_currency' => []];
        }
        $rows = DB::table('orders')
            ->whereIn('store_id', $ids)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('currency, COUNT(*) as aggregate_count, COALESCE(SUM(grand_total),0) as aggregate_total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        return [
            'count' => (int) $rows->sum(fn (object $row): int => (int) $row->aggregate_count),
            'by_currency' => $rows->mapWithKeys(fn (object $row): array => [(string) $row->currency => (float) $row->aggregate_total])->all(),
        ];
    }

    /** @param Collection<int, \stdClass> $stores @return array<int,array<string,mixed>> */
    private function snapshotMap(Collection $stores): array
    {
        if ($stores->isEmpty()) {
            return [];
        }
        $ids = $stores->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $owners = Schema::hasTable('retail_wholesale_accounts')
            ? DB::table('retail_wholesale_accounts')->whereIn('retail_store_id', $ids)->get()->keyBy('retail_store_id')
            : collect();
        $accounts = (Schema::hasTable('retail_wholesale_accounts') && Schema::hasTable('b2b_accounts'))
            ? DB::table('retail_wholesale_accounts as links')
                ->join('b2b_accounts as accounts', 'accounts.b2b_customer_id', '=', 'links.b2b_customer_id')
                ->whereIn('links.retail_store_id', $ids)
                ->get(['links.retail_store_id', 'accounts.status'])
                ->keyBy('retail_store_id')
            : collect();
        $catalog = Schema::hasTable('store_products')
            ? DB::table('store_products')->whereIn('store_id', $ids)->where('is_active', true)
                ->selectRaw('store_id, COUNT(*) as total')->groupBy('store_id')->pluck('total', 'store_id')
            : collect();
        $inventory = (Schema::hasTable('warehouses') && Schema::hasTable('inventories'))
            ? DB::table('warehouses as w')->join('inventories as i', 'i.warehouse_id', '=', 'w.id')
                ->whereIn('w.store_id', $ids)->where('w.is_active', true)
                ->whereRaw('(i.quantity - i.reserved_quantity) > 0')
                ->selectRaw('w.store_id, COUNT(*) as total')->groupBy('w.store_id')->pluck('total', 'w.store_id')
            : collect();
        $zones = Schema::hasTable('store_service_zones')
            ? DB::table('store_service_zones')->whereIn('store_id', $ids)->where('is_active', true)
                ->selectRaw('store_id, COUNT(*) as total')->groupBy('store_id')->pluck('total', 'store_id')
            : collect();
        $sites = Schema::hasTable('smart_ordering_sites')
            ? DB::table('smart_ordering_sites')->whereIn('store_id', $ids)->get()->keyBy('store_id')
            : collect();
        $profiles = Schema::hasTable('store_channel_operational_profiles')
            ? DB::table('store_channel_operational_profiles')->whereIn('store_id', $ids)->get()->keyBy('store_id')
            : collect();
        $degraded = $this->degradedIntegrationsByStore($ids);
        $integrationTotals = $this->integrationTotalsByStore($ids);
        $auditCounts = Schema::hasTable('audit_logs')
            ? DB::table('audit_logs')->where('auditable_type', Store::class)->whereIn('auditable_id', $ids)
                ->selectRaw('auditable_id, COUNT(*) as total')->groupBy('auditable_id')->pluck('total', 'auditable_id')
            : collect();

        $paymentReady = count((array) config('checkout.payment_methods', ['cash_on_delivery'])) > 0;
        $result = [];
        foreach ($stores as $store) {
            $id = (int) $store->id;
            $owner = $owners->get($id);
            $site = $sites->get($id);
            $profile = $profiles->get($id);
            $checks = [
                ['key' => 'identity', 'ready' => trim((string) $store->code) !== '' && trim((string) $store->name) !== ''],
                ['key' => 'owner', 'ready' => $owner !== null && $owner->owner_user_id !== null],
                ['key' => 'catalog', 'ready' => (int) ($catalog[$id] ?? 0) > 0],
                ['key' => 'inventory', 'ready' => (int) ($inventory[$id] ?? 0) > 0],
                ['key' => 'payment', 'ready' => $paymentReady],
                ['key' => 'delivery', 'ready' => (int) ($zones[$id] ?? 0) > 0],
                ['key' => 'integrations', 'ready' => (int) ($degraded[$id] ?? 0) === 0],
            ];
            $passed = collect($checks)->where('ready', true)->count();
            $score = (int) round(($passed / count($checks)) * 100);
            $lifecycle = (string) ($profile->lifecycle_state ?? ((bool) $store->is_active ? 'ACTIVE' : 'SUSPENDED'));

            $result[$id] = [
                'store_id' => $id,
                'name' => (string) $store->name,
                'code' => (string) $store->code,
                'lifecycle_state' => $lifecycle,
                'readiness_state' => $score === 100 ? 'ready' : 'attention',
                'readiness_score' => $score,
                'readiness' => $checks,
                'channels' => [
                    'b2c' => (bool) $store->is_active ? 'active' : 'inactive',
                    'b2b' => (string) ($accounts->get($id)->status ?? 'not_linked'),
                    'restaurant' => $site === null
                        ? 'not_configured'
                        : ((bool) $site->master_enabled ? strtolower((string) $site->runtime_state) : 'disabled'),
                ],
                'catalog_products' => (int) ($catalog[$id] ?? 0),
                'inventory_positions' => (int) ($inventory[$id] ?? 0),
                'delivery_zones' => (int) ($zones[$id] ?? 0),
                'integrations' => (int) ($integrationTotals[$id] ?? 0),
                'degraded_integrations' => (int) ($degraded[$id] ?? 0),
                'audit_events' => (int) ($auditCounts[$id] ?? 0),
                'last_reason' => $profile?->reason,
                'changed_at' => $profile?->changed_at,
            ];
        }

        return $result;
    }

    private function storeSnapshotRow(Store $store): \stdClass
    {
        $row = new \stdClass();
        $row->id = (int) $store->id;
        $row->name = (string) $store->name;
        $row->code = (string) $store->code;
        $row->is_active = (bool) $store->is_active;
        $row->created_at = $store->created_at;

        return $row;
    }

    /** @return array<string,mixed> */
    private function snapshotForStore(Store $store): array
    {
        $rows = collect([$this->storeSnapshotRow($store)]);

        return $this->snapshotMap($rows)[(int) $store->id] ?? [];
    }

    /** @param list<int> $ids @return array<int,int> */
    private function availabilityByStore(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('warehouses') || ! Schema::hasTable('inventories') || ! Schema::hasTable('store_products')) {
            return [];
        }

        return DB::table('warehouses as w')
            ->join('inventories as i', 'i.warehouse_id', '=', 'w.id')
            ->join('store_products as sp', function ($join): void {
                $join->on('sp.store_id', '=', 'w.store_id')->on('sp.product_id', '=', 'i.product_id');
            })
            ->whereIn('w.store_id', $ids)->where('w.is_active', true)->where('sp.is_active', true)
            ->whereRaw('(i.quantity - i.reserved_quantity) <= 0')
            ->selectRaw('w.store_id, COUNT(DISTINCT i.product_id) as total')
            ->groupBy('w.store_id')->pluck('total', 'w.store_id')->map(fn ($v): int => (int) $v)->all();
    }

    /** @param list<int> $ids @return array<int,int> */
    private function degradedIntegrationsByStore(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('restaurant_saas_integrations') || ! Schema::hasTable('smart_ordering_sites')) {
            return [];
        }

        return DB::table('restaurant_saas_integrations as i')
            ->join('smart_ordering_sites as s', 's.id', '=', 'i.site_id')
            ->whereIn('s.store_id', $ids)->whereIn('i.status', ['degraded', 'suspended'])
            ->selectRaw('s.store_id, COUNT(*) as total')->groupBy('s.store_id')
            ->pluck('total', 's.store_id')->map(fn ($v): int => (int) $v)->all();
    }

    /** @param list<int> $ids @return array<int,int> */
    private function integrationTotalsByStore(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('restaurant_saas_integrations') || ! Schema::hasTable('smart_ordering_sites')) {
            return [];
        }

        return DB::table('restaurant_saas_integrations as i')->join('smart_ordering_sites as s', 's.id', '=', 'i.site_id')
            ->whereIn('s.store_id', $ids)->selectRaw('s.store_id, COUNT(*) as total')->groupBy('s.store_id')
            ->pluck('total', 's.store_id')->map(fn ($v): int => (int) $v)->all();
    }

    /** @param Collection<int, \stdClass> $stores @return list<array<string,mixed>> */
    private function storePerformance(Collection $stores, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $stores->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $rows = Schema::hasTable('orders') && $ids !== []
            ? DB::table('orders')->whereIn('store_id', $ids)
                ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
                ->selectRaw('store_id, currency, COUNT(*) as orders, COALESCE(SUM(grand_total),0) as revenue')
                ->groupBy('store_id', 'currency')->get()
            : collect();

        $storeById = $stores->keyBy('id');

        return $rows->map(function (object $row) use ($storeById): array {
            $store = $storeById->get((int) $row->store_id);

            return [
                'store_id' => (int) $row->store_id,
                'store' => (string) ($store->name ?? ('#'.$row->store_id)),
                'code' => (string) ($store->code ?? ''),
                'currency' => (string) $row->currency,
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
            ];
        })->sortByDesc('revenue')->values()->all();
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function channelPerformance(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($ids === [] || ! Schema::hasTable('orders')) {
            return [];
        }

        return DB::table('orders')->whereIn('store_id', $ids)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('channel, currency, COUNT(*) as orders, COALESCE(SUM(grand_total),0) as revenue')
            ->groupBy('channel', 'currency')->orderByDesc('revenue')->get()
            ->map(fn (object $row): array => [
                'channel' => (string) $row->channel, 'currency' => (string) $row->currency,
                'orders' => (int) $row->orders, 'revenue' => (float) $row->revenue,
            ])->all();
    }

    /** @param array<int,array<string,mixed>> $snapshots @return list<array{label:string,value:int}> */
    private function healthDistribution(array $snapshots): array
    {
        $groups = collect($snapshots)->groupBy(fn (array $row): string => $row['lifecycle_state'] === 'ACTIVE' ? $row['readiness_state'] : strtolower($row['lifecycle_state']));

        return $groups->map(fn (Collection $rows, string $label): array => ['label' => $label, 'value' => $rows->count()])->values()->all();
    }

    /** @param Collection<int, \stdClass> $stores @return list<array{label:string,value:int}> */
    private function storeGrowth(Collection $stores): array
    {
        return $stores->filter(fn (object $store): bool => $store->created_at !== null)
            ->groupBy(fn (object $store): string => CarbonImmutable::parse($store->created_at)->format('Y-m'))
            ->map(fn (Collection $rows, string $month): array => ['label' => $month, 'value' => $rows->count()])
            ->sortBy('label')->values()->all();
    }

    /** @param list<int> $ids @return list<array{label:string,value:int}> */
    private function integrationTrend(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($ids === [] || ! Schema::hasTable('restaurant_saas_integration_events') || ! Schema::hasTable('restaurant_saas_integrations') || ! Schema::hasTable('smart_ordering_sites')) {
            return [];
        }

        return DB::table('restaurant_saas_integration_events as e')
            ->join('restaurant_saas_integrations as i', 'i.id', '=', 'e.integration_id')
            ->join('smart_ordering_sites as s', 's.id', '=', 'i.site_id')
            ->whereIn('s.store_id', $ids)
            ->whereBetween('e.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereIn('e.status', ['failed', 'error', 'dead'])
            ->orderBy('e.created_at')->limit(2000)->get(['e.created_at'])
            ->groupBy(fn (object $row): string => CarbonImmutable::parse($row->created_at)->toDateString())
            ->map(fn (Collection $rows, string $date): array => ['label' => $date, 'value' => $rows->count()])
            ->values()->all();
    }

    /** @param Collection<int, \stdClass> $stores @param list<int> $ids @return list<array<string,mixed>> */
    private function channelState(Collection $stores, array $ids): array
    {
        $b2b = (Schema::hasTable('retail_wholesale_accounts') && Schema::hasTable('b2b_accounts'))
            ? DB::table('retail_wholesale_accounts as links')->join('b2b_accounts as accounts', 'accounts.b2b_customer_id', '=', 'links.b2b_customer_id')
                ->whereIn('links.retail_store_id', $ids)->where('accounts.status', 'active')->count()
            : 0;
        $restaurant = Schema::hasTable('smart_ordering_sites')
            ? DB::table('smart_ordering_sites')->whereIn('store_id', $ids)->where('master_enabled', true)->where('runtime_state', 'ENABLED')->count()
            : 0;

        return [
            ['channel' => 'b2c', 'active' => $stores->where('is_active', true)->count(), 'total' => $stores->count()],
            ['channel' => 'b2b', 'active' => $b2b, 'total' => $stores->count()],
            ['channel' => 'restaurant', 'active' => $restaurant, 'total' => $stores->count()],
        ];
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function availabilityReport(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('warehouses') || ! Schema::hasTable('inventories') || ! Schema::hasTable('store_products') || ! Schema::hasTable('products')) {
            return [];
        }

        return DB::table('warehouses as w')
            ->join('inventories as i', 'i.warehouse_id', '=', 'w.id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('store_products as sp', function ($join): void {
                $join->on('sp.store_id', '=', 'w.store_id')->on('sp.product_id', '=', 'i.product_id');
            })
            ->join('stores as s', 's.id', '=', 'w.store_id')
            ->whereIn('w.store_id', $ids)->where('w.is_active', true)->where('sp.is_active', true)
            ->whereRaw('(i.quantity - i.reserved_quantity) <= 0')
            ->orderBy('s.name')->orderBy('p.name')->limit(100)
            ->get(['s.name as store', 's.code', 'p.sku', 'p.name as product', 'i.quantity', 'i.reserved_quantity'])
            ->map(fn (object $row): array => (array) $row)->all();
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function integrationReport(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('restaurant_saas_integrations') || ! Schema::hasTable('smart_ordering_sites')) {
            return [];
        }

        return DB::table('restaurant_saas_integrations as i')
            ->join('smart_ordering_sites as site', 'site.id', '=', 'i.site_id')
            ->join('stores as s', 's.id', '=', 'site.store_id')
            ->whereIn('s.id', $ids)->orderBy('s.name')->orderBy('i.display_name')->limit(100)
            ->get([
                's.name as store', 's.code', 'i.connector_type', 'i.provider_key', 'i.display_name',
                'i.status', 'i.last_success_at', 'i.last_failure_at', 'i.last_error_code',
            ])->map(fn (object $row): array => (array) $row)->all();
    }
}
