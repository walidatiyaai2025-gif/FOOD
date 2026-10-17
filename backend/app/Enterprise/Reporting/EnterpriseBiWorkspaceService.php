<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Models\User;
use App\Services\EnterpriseSavedViewService;
use App\Support\EnterpriseGridState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EnterpriseBiWorkspaceService
{
    public const SURFACE_KEY = 'enterprise.bi';

    public function __construct(
        private readonly ExecutiveBiService $executive,
        private readonly ReportBuilderExecutor $executor,
        private readonly EnterpriseSavedViewService $savedViews,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $catalog
     * @param  array<string,mixed>|null  $selection
     * @return array<string,mixed>
     */
    public function overview(User $actor, array $catalog, Request $request, ?array $selection): array
    {
        $storeOptions = $this->scopeOptions($catalog, EntitlementScope::STORE);
        $entityOptions = $this->scopeOptions($catalog, EntitlementScope::LEGAL_ENTITY);

        $store = $this->selectedScope($storeOptions, (string) $request->query('dashboard_store', ''));
        $entity = $this->selectedScope($entityOptions, (string) $request->query('dashboard_entity', ''));

        $today = CarbonImmutable::today();
        $from = $this->date((string) $request->query('dashboard_from', $today->subDays(6)->toDateString()), $today->subDays(6));
        $to = $this->date((string) $request->query('dashboard_to', $today->toDateString()), $today);
        if ($from->greaterThan($to) || $from->diffInDays($to) + 1 > 31) {
            $from = $to->subDays(6);
        }

        $currency = strtoupper(trim((string) $request->query('dashboard_currency', 'KWD')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $currency = 'KWD';
        }

        $scopes = array_values(array_filter([
            is_array($store) ? $store['scope'] : null,
            is_array($entity) ? $entity['scope'] : null,
        ], static fn (mixed $scope): bool => $scope instanceof EntitlementScope));

        $snapshot = null;
        $snapshotError = false;
        try {
            $snapshot = $this->executive->snapshot($actor, $scopes, $from, $to, $currency);
        } catch (ValidationException) {
            $snapshotError = true;
        }

        $analytics = [
            'orders' => $this->analytics($actor, 'orders.summary', is_array($store) ? $store['scope'] : null, [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'dimensions' => ['date'],
                'measures' => ['orders', 'gross_total', 'average_order_value'],
                'group_by' => ['date'],
                'per_page' => 31,
            ]),
            'profitability' => $this->analytics($actor, 'commercial.profitability', is_array($store) ? $store['scope'] : null, [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'dimensions' => ['date'],
                'measures' => ['net_revenue', 'cost', 'margin', 'unvalued_lines'],
                'group_by' => ['date'],
                'per_page' => 31,
            ]),
            'ar' => $this->analytics($actor, 'finance.ar_exposure', is_array($entity) ? $entity['scope'] : null, [
                'from' => $to->toDateString(),
                'to' => $to->toDateString(),
                'dimensions' => ['aging_bucket'],
                'measures' => ['documents', 'outstanding_amount'],
                'group_by' => ['aging_bucket'],
                'per_page' => 20,
            ]),
            'ap' => $this->analytics($actor, 'finance.ap_exposure', is_array($entity) ? $entity['scope'] : null, [
                'from' => $to->toDateString(),
                'to' => $to->toDateString(),
                'dimensions' => ['aging_bucket'],
                'measures' => ['documents', 'outstanding_amount'],
                'group_by' => ['aging_bucket'],
                'per_page' => 20,
            ]),
            'stock' => $this->analytics($actor, 'wms.stock_exposure', is_array($store) ? $store['scope'] : null, [
                'from' => $to->toDateString(),
                'to' => $to->toDateString(),
                'dimensions' => ['stock_status'],
                'measures' => ['inventory_rows', 'on_hand', 'reserved', 'available', 'stock_value', 'unvalued_items'],
                'group_by' => ['stock_status'],
                'per_page' => 20,
            ]),
        ];

        return [
            'store_options' => $storeOptions,
            'entity_options' => $entityOptions,
            'selected_store' => $store,
            'selected_entity' => $entity,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $currency,
            'snapshot' => $snapshot,
            'snapshot_error' => $snapshotError,
            'analytics' => $analytics,
            'forecast' => $this->orderForecast(is_array($store) ? $store['scope'] : null, $to),
            'saved_view' => $this->savedViewState($actor, $selection),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $catalog
     * @return list<array{key:string,label:string,scope:EntitlementScope}>
     */
    private function scopeOptions(array $catalog, string $type): array
    {
        return collect($catalog)
            ->filter(fn (array $item): bool => ($item['scope_type'] ?? null) === $type)
            ->flatMap(fn (array $item): array => (array) ($item['scope_options'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['scope'] ?? null) instanceof EntitlementScope)
            ->unique('key')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{key:string,label:string,scope:EntitlementScope}>  $options
     * @return array{key:string,label:string,scope:EntitlementScope}|null
     */
    private function selectedScope(array $options, string $requested): ?array
    {
        $match = collect($options)->firstWhere('key', trim($requested));

        return is_array($match) ? $match : ($options[0] ?? null);
    }

    /** @param array<string,mixed> $input */
    private function analytics(User $actor, string $report, mixed $scope, array $input): ?array
    {
        if (! $scope instanceof EntitlementScope) {
            return null;
        }

        try {
            return $this->executor->execute($actor, $report, $input, $scope);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function orderForecast(mixed $scope, CarbonImmutable $asOf): array
    {
        if (! $scope instanceof EntitlementScope
            || $scope->type !== EntitlementScope::STORE
            || $scope->id === null
            || ! Schema::hasTable('orders')) {
            return ['available' => false, 'reason' => 'scope_unavailable'];
        }

        $from = $asOf->subDays(27);
        $rows = DB::table('orders')
            ->where('store_id', $scope->id)
            ->where('created_at', '>=', $from->startOfDay())
            ->where('created_at', '<', $asOf->addDay()->startOfDay())
            ->selectRaw('DATE(created_at) as report_date, COUNT(*) as order_count')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('report_date')
            ->get();

        if ($rows->count() < 14) {
            return [
                'available' => false,
                'reason' => 'insufficient_history',
                'observed_days' => $rows->count(),
                'required_days' => 14,
            ];
        }

        $counts = $rows->pluck('order_count')->map(static fn (mixed $value): float => (float) $value)->values();
        $recent = $counts->slice(-7);
        $prior = $counts->slice(-14, 7);
        $recentAverage = $recent->avg() ?? 0.0;
        $priorAverage = $prior->avg() ?? 0.0;
        $deltaPercent = abs($priorAverage) < 0.0005
            ? null
            : (($recentAverage - $priorAverage) / abs($priorAverage)) * 100;

        return [
            'available' => true,
            'method' => 'seven_day_moving_average',
            'observed_days' => $rows->count(),
            'recent_daily_average' => round($recentAverage, 2),
            'prior_daily_average' => round($priorAverage, 2),
            'trend_percent' => $deltaPercent === null ? null : round($deltaPercent, 2),
            'next_7_days_orders' => (int) round(max(0, $recentAverage * 7)),
            'as_of' => $asOf->toDateString(),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $selection
     * @return array<string,mixed>|null
     */
    private function savedViewState(User $actor, ?array $selection): ?array
    {
        if (! is_array($selection) || ! ($selection['scope'] ?? null) instanceof EntitlementScope) {
            return null;
        }

        /** @var EntitlementScope $scope */
        $scope = $selection['scope'];
        if ($scope->id === null) {
            return null;
        }

        $dimensions = array_values(array_filter((array) ($selection['dimensions'] ?? []), 'is_string'));
        $measures = array_values(array_filter((array) ($selection['measures'] ?? []), 'is_string'));
        $state = new EnterpriseGridState(
            null,
            [
                'report' => (string) ($selection['report'] ?? ''),
                'breakdown' => (string) ($selection['breakdown'] ?? ''),
                'from' => (string) ($selection['from'] ?? ''),
                'to' => (string) ($selection['to'] ?? ''),
                'dimensions' => implode(',', $dimensions),
                'measures' => implode(',', $measures),
            ],
            null,
            'asc',
            [],
            25,
        );

        try {
            $views = $this->savedViews
                ->visible($actor, self::SURFACE_KEY, $scope->type, $scope->id)
                ->map(fn ($view): array => [
                    'id' => (int) $view->id,
                    'name' => (string) $view->name,
                    'visibility' => (string) $view->visibility,
                    'owned' => (int) $view->user_id === (int) $actor->id,
                    'query' => $this->savedViewQuery($this->savedViews->queryFor($view)),
                ])
                ->values();

            return [
                'surface_key' => self::SURFACE_KEY,
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
                'state_token' => $this->savedViews->stateToken(self::SURFACE_KEY, $scope->type, $scope->id, $state),
                'views' => $views,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function savedViewQuery(array $query): array
    {
        foreach (['dimensions', 'measures'] as $field) {
            if (is_string($query[$field] ?? null)) {
                $query[$field] = collect(explode(',', (string) $query[$field]))
                    ->map(static fn (string $value): string => trim($value))
                    ->filter()
                    ->values()
                    ->all();
            }
        }

        return $query;
    }

    private function date(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return $fallback;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : $fallback;
    }
}
