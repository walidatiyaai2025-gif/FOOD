<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use Illuminate\Validation\ValidationException;

final readonly class ExecutiveKpiDefinition
{
    public const COMPARISON_PERIOD = 'period';

    public const COMPARISON_AS_OF = 'as_of';

    public const COMPARISON_CURRENT_ONLY = 'current_only';

    public const FRESHNESS_LIVE = 'live';

    public const FRESHNESS_PROJECTION = 'projection';

    private const COMPARISONS = [
        self::COMPARISON_PERIOD,
        self::COMPARISON_AS_OF,
        self::COMPARISON_CURRENT_ONLY,
    ];

    private const FRESHNESS_MODES = [
        self::FRESHNESS_LIVE,
        self::FRESHNESS_PROJECTION,
    ];

    private const SCOPE_TYPES = [
        EntitlementScope::STORE,
        EntitlementScope::LEGAL_ENTITY,
    ];

    /**
     * @param  list<string>  $measures
     */
    public function __construct(
        public string $code,
        public string $reportCode,
        public string $scopeType,
        public array $measures,
        public string $comparison,
        public bool $currencyRequired,
        public string $freshnessMode,
    ) {}

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition, string $key): self
    {
        $code = trim((string) ($definition['code'] ?? $key));
        $reportCode = trim((string) ($definition['report'] ?? ''));
        $scopeType = trim((string) ($definition['scope_type'] ?? ''));
        $comparison = trim((string) ($definition['comparison'] ?? ''));
        $freshnessMode = trim((string) ($definition['freshness'] ?? self::FRESHNESS_LIVE));
        $measures = self::stringList($definition['measures'] ?? []);

        if ($code === '' || $reportCode === '') {
            throw ValidationException::withMessages([
                'executive_kpi' => ['Executive KPI code and report are required.'],
            ]);
        }

        if (in_array($scopeType, self::SCOPE_TYPES, true) === false) {
            throw ValidationException::withMessages([
                'executive_kpi' => ["Unsupported executive KPI scope [{$scopeType}]."],
            ]);
        }

        if (in_array($comparison, self::COMPARISONS, true) === false) {
            throw ValidationException::withMessages([
                'executive_kpi' => ["Unsupported executive KPI comparison [{$comparison}]."],
            ]);
        }

        if (in_array($freshnessMode, self::FRESHNESS_MODES, true) === false) {
            throw ValidationException::withMessages([
                'executive_kpi' => ["Unsupported executive KPI freshness mode [{$freshnessMode}]."],
            ]);
        }

        if ($measures === []) {
            throw ValidationException::withMessages([
                'executive_kpi' => ['Executive KPI measures cannot be empty.'],
            ]);
        }

        return new self(
            code: $code,
            reportCode: $reportCode,
            scopeType: $scopeType,
            measures: $measures,
            comparison: $comparison,
            currencyRequired: (bool) ($definition['currency_required'] ?? false),
            freshnessMode: $freshnessMode,
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_array($value) === false || array_is_list($value) === false) {
            throw ValidationException::withMessages([
                'executive_kpi' => ['Executive KPI measures must be a list.'],
            ]);
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) === false || trim($item) === '') {
                throw ValidationException::withMessages([
                    'executive_kpi' => ['Executive KPI measures may only contain non-empty strings.'],
                ]);
            }

            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }
}
