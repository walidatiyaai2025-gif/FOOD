<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ExecutiveBiService
{
    public function __construct(
        private readonly ExecutiveKpiRegistry $kpis,
        private readonly ReportRegistry $reports,
        private readonly ReportAccessResolver $access,
        private readonly ReportBuilderExecutor $executor,
    ) {}

    /**
     * @param  list<EntitlementScope>  $scopes
     * @return array<string, mixed>
     */
    public function snapshot(
        User $actor,
        array $scopes,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $currency = null,
    ): array {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $this->assertPeriod($from, $to);
        $currency = $this->currency($currency);
        $scopeMap = $this->scopeMap($scopes);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $currency,
            'cards' => $this->kpis->all()
                ->map(fn (ExecutiveKpiDefinition $kpi): array => $this->card(
                    $actor,
                    $kpi,
                    $scopeMap,
                    $from,
                    $to,
                    $currency,
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, EntitlementScope>  $scopeMap
     * @return array<string, mixed>
     */
    private function card(
        User $actor,
        ExecutiveKpiDefinition $kpi,
        array $scopeMap,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $currency,
    ): array {
        $scope = $scopeMap[$kpi->scopeType] ?? null;
        if (! $scope instanceof EntitlementScope) {
            return $this->unavailableCard($kpi, null, 'scope_unavailable', false, false);
        }

        $report = $this->reports->require($kpi->reportCode);
        if ($this->access->allowsView($actor, $report, $scope) === false) {
            return $this->unavailableCard(
                $kpi,
                $scope,
                'not_authorized_or_entitled',
                false,
                false,
            );
        }

        if ($kpi->currencyRequired && $currency === null) {
            return $this->unavailableCard($kpi, $scope, 'currency_required', true, true);
        }

        if ($kpi->comparison === ExecutiveKpiDefinition::COMPARISON_CURRENT_ONLY
            && $to->isSameDay(CarbonImmutable::today()) === false) {
            return $this->unavailableCard(
                $kpi,
                $scope,
                'current_snapshot_only',
                true,
                true,
            );
        }

        try {
            [$currentFrom, $currentTo] = $this->currentWindow($kpi, $from, $to);
            $currentResult = $this->execute(
                $actor,
                $kpi,
                $scope,
                $currentFrom,
                $currentTo,
                $currency,
            );
            $currentRow = $currentResult['rows'][0] ?? [];
            $current = $this->values($currentRow, $kpi->measures);

            $previous = null;
            if ($kpi->comparison !== ExecutiveKpiDefinition::COMPARISON_CURRENT_ONLY) {
                [$previousFrom, $previousTo] = $this->previousWindow($kpi, $from, $to);
                $previousResult = $this->execute(
                    $actor,
                    $kpi,
                    $scope,
                    $previousFrom,
                    $previousTo,
                    $currency,
                );
                $previous = $this->values(
                    $previousResult['rows'][0] ?? [],
                    $kpi->measures,
                );
            }

            return [
                'code' => $kpi->code,
                'report' => $kpi->reportCode,
                'visible' => true,
                'authorized' => true,
                'available' => true,
                'reason' => null,
                'scope' => $this->scopeSnapshot($scope),
                'current' => $current,
                'previous' => $previous,
                'trend' => $this->trend($current, $previous, $kpi->measures),
                'drill_down' => is_array($currentRow['drill_down'] ?? null)
                    ? $currentRow['drill_down']
                    : null,
                'freshness' => $this->freshness($kpi, $scope),
            ];
        } catch (AuthorizationException|ValidationException) {
            return $this->unavailableCard($kpi, $scope, 'source_unavailable', true, true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function execute(
        User $actor,
        ExecutiveKpiDefinition $kpi,
        EntitlementScope $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $currency,
    ): array {
        $filters = [];
        if ($kpi->currencyRequired && $currency !== null) {
            $filters['currency'] = $currency;
        }

        return $this->executor->execute(
            $actor,
            $kpi->reportCode,
            [
                'filters' => $filters,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'dimensions' => [],
                'measures' => $kpi->measures,
                'group_by' => [],
                'per_page' => 1,
            ],
            $scope,
        );
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function currentWindow(
        ExecutiveKpiDefinition $kpi,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        if ($kpi->comparison === ExecutiveKpiDefinition::COMPARISON_AS_OF
            || $kpi->comparison === ExecutiveKpiDefinition::COMPARISON_CURRENT_ONLY) {
            return [$to, $to];
        }

        return [$from, $to];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function previousWindow(
        ExecutiveKpiDefinition $kpi,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $previousTo = $from->subDay();

        if ($kpi->comparison === ExecutiveKpiDefinition::COMPARISON_AS_OF) {
            return [$previousTo, $previousTo];
        }

        $days = (int) $from->diffInDays($to) + 1;

        return [$previousTo->subDays($days - 1), $previousTo];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $measures
     * @return array<string, int|float|string|null>
     */
    private function values(array $row, array $measures): array
    {
        $values = [];

        foreach ($measures as $measure) {
            $value = $row[$measure] ?? null;
            $values[$measure] = is_int($value) || is_float($value) || is_string($value)
                ? $value
                : null;
        }

        return $values;
    }

    /**
     * @param  array<string, int|float|string|null>  $current
     * @param  array<string, int|float|string|null>|null  $previous
     * @param  list<string>  $measures
     * @return array<string, array{delta:string|null, percent:string|null}>|null
     */
    private function trend(array $current, ?array $previous, array $measures): ?array
    {
        if ($previous === null) {
            return null;
        }

        $trend = [];
        foreach ($measures as $measure) {
            $currentValue = $current[$measure] ?? null;
            $previousValue = $previous[$measure] ?? null;

            if (! is_numeric($currentValue) || ! is_numeric($previousValue)) {
                $trend[$measure] = ['delta' => null, 'percent' => null];

                continue;
            }

            $currentNumber = (float) $currentValue;
            $previousNumber = (float) $previousValue;
            $delta = $currentNumber - $previousNumber;

            $trend[$measure] = [
                'delta' => self::decimal($delta),
                'percent' => abs($previousNumber) < 0.0005
                    ? null
                    : self::decimal(($delta / abs($previousNumber)) * 100),
            ];
        }

        return $trend;
    }

    /** @return array<string, mixed> */
    private function freshness(
        ExecutiveKpiDefinition $kpi,
        EntitlementScope $scope,
    ): array {
        if ($kpi->freshnessMode !== ExecutiveKpiDefinition::FRESHNESS_PROJECTION) {
            return [
                'state' => 'live',
                'stale' => false,
                'refreshed_at' => null,
                'refresh_mode' => 'source_authority',
            ];
        }

        $source = config('enterprise_reporting.sources.profitability_facts');
        if (is_string($source) === false
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $source) !== 1
            || $scope->type !== EntitlementScope::STORE
            || $scope->id === null
            || Schema::hasTable($source) === false) {
            return [
                'state' => 'unavailable',
                'stale' => true,
                'refreshed_at' => null,
                'refresh_mode' => 'queued_projection',
            ];
        }

        $raw = DB::table($source)
            ->where('store_id', $scope->id)
            ->max('updated_at');

        if ($raw === null) {
            return [
                'state' => 'empty',
                'stale' => true,
                'refreshed_at' => null,
                'refresh_mode' => 'queued_projection',
            ];
        }

        $refreshedAt = CarbonImmutable::parse((string) $raw)->utc();
        $threshold = max(
            1,
            (int) config('enterprise_reporting.executive_bi.projection_stale_after_minutes', 30),
        );
        $stale = $refreshedAt->lessThan(CarbonImmutable::now('UTC')->subMinutes($threshold));

        return [
            'state' => $stale ? 'stale' : 'fresh',
            'stale' => $stale,
            'refreshed_at' => $refreshedAt->toIso8601String(),
            'refresh_mode' => 'queued_projection',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableCard(
        ExecutiveKpiDefinition $kpi,
        ?EntitlementScope $scope,
        string $reason,
        bool $visible,
        bool $authorized,
    ): array {
        return [
            'code' => $kpi->code,
            'report' => $kpi->reportCode,
            'visible' => $visible,
            'authorized' => $authorized,
            'available' => false,
            'reason' => $reason,
            'scope' => $scope instanceof EntitlementScope
                ? $this->scopeSnapshot($scope)
                : null,
            'current' => null,
            'previous' => null,
            'trend' => null,
            'drill_down' => null,
            'freshness' => [
                'state' => 'not_evaluated',
                'stale' => null,
                'refreshed_at' => null,
                'refresh_mode' => null,
            ],
        ];
    }

    /** @return array{type:string,id:int|null} */
    private function scopeSnapshot(EntitlementScope $scope): array
    {
        return ['type' => $scope->type, 'id' => $scope->id];
    }

    /**
     * @param  list<EntitlementScope>  $scopes
     * @return array<string, EntitlementScope>
     */
    private function scopeMap(array $scopes): array
    {
        $map = [];

        foreach ($scopes as $scope) {
            if (! $scope instanceof EntitlementScope) {
                throw ValidationException::withMessages([
                    'scope' => ['Executive BI scopes must use EntitlementScope values.'],
                ]);
            }

            if (array_key_exists($scope->type, $map)) {
                throw ValidationException::withMessages([
                    'scope' => ["Executive BI scope [{$scope->type}] was supplied more than once."],
                ]);
            }

            $map[$scope->type] = $scope;
        }

        return $map;
    }

    private function assertPeriod(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'date_range' => ['Executive BI from date must not be after to date.'],
            ]);
        }

        $maxDays = max(1, (int) config('enterprise_reporting.executive_bi.max_period_days', 31));
        if ((int) $from->diffInDays($to) + 1 > $maxDays) {
            throw ValidationException::withMessages([
                'date_range' => ['Executive BI date range exceeds the configured bound.'],
            ]);
        }
    }

    private function currency(?string $currency): ?string
    {
        if ($currency === null || trim($currency) === '') {
            return null;
        }

        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw ValidationException::withMessages([
                'currency' => ['Executive BI currency must be a three-letter code.'],
            ]);
        }

        return $currency;
    }

    private static function decimal(float $value): string
    {
        return number_format(round($value, 3), 3, '.', '');
    }
}
