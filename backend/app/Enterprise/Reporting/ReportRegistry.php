<?php

namespace App\Enterprise\Reporting;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ReportRegistry
{
    /** @return Collection<string, ReportDefinition> */
    public function all(): Collection
    {
        return collect((array) config('enterprise_reporting.reports', []))
            ->map(static function (mixed $definition, string|int $key): ReportDefinition {
                if (is_array($definition) === false) {
                    throw ValidationException::withMessages([
                        'report' => ['Report registry entries must be arrays.'],
                    ]);
                }

                return ReportDefinition::fromArray($definition, (string) $key);
            })
            ->keyBy(static fn (ReportDefinition $report): string => $report->code);
    }

    public function find(string $code): ?ReportDefinition
    {
        return $this->all()->get($code);
    }

    public function require(string $code): ReportDefinition
    {
        return $this->find($code)
            ?? throw ValidationException::withMessages([
                'report' => ["Unknown report [{$code}]."],
            ]);
    }
}
