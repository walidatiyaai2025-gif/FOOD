<?php

namespace App\Enterprise\Reporting;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ExecutiveKpiRegistry
{
    public function __construct(private readonly ReportRegistry $reports) {}

    /** @return Collection<string, ExecutiveKpiDefinition> */
    public function all(): Collection
    {
        return collect((array) config('enterprise_reporting.executive_kpis', []))
            ->map(function (mixed $definition, string|int $key): ExecutiveKpiDefinition {
                if (is_array($definition) === false) {
                    throw ValidationException::withMessages([
                        'executive_kpi' => ['Executive KPI registry entries must be arrays.'],
                    ]);
                }

                $kpi = ExecutiveKpiDefinition::fromArray($definition, (string) $key);
                $report = $this->reports->require($kpi->reportCode);
                $unknownMeasures = array_values(array_diff($kpi->measures, $report->measures));

                if ($unknownMeasures !== []) {
                    throw ValidationException::withMessages([
                        'executive_kpi' => ['Executive KPI measures must be registered by the source report.'],
                    ]);
                }

                if ($kpi->currencyRequired
                    && in_array('currency', $report->allowedFilters, true) === false) {
                    throw ValidationException::withMessages([
                        'executive_kpi' => ['Currency-aware executive KPIs require a registered currency filter.'],
                    ]);
                }

                return $kpi;
            })
            ->keyBy(static fn (ExecutiveKpiDefinition $kpi): string => $kpi->code);
    }

    public function require(string $code): ExecutiveKpiDefinition
    {
        return $this->all()->get($code)
            ?? throw ValidationException::withMessages([
                'executive_kpi' => ["Unknown executive KPI [{$code}]."],
            ]);
    }
}
