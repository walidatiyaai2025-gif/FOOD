<?php

namespace App\Support;

use Illuminate\Http\Request;

final readonly class EnterpriseGridState
{
    /**
     * @param  array<string, string>  $filters
     * @param  list<string>  $columns
     */
    public function __construct(
        public ?string $search,
        public array $filters,
        public ?string $sortField,
        public string $sortDirection,
        public array $columns,
        public int $perPage,
    ) {}

    /**
     * @param  list<string>  $allowedFilters
     * @param  list<string>  $allowedSorts
     * @param  list<string>  $allowedColumns
     * @param  list<string>  $defaultColumns
     */
    public static function fromRequest(
        Request $request,
        array $allowedFilters,
        array $allowedSorts,
        array $allowedColumns,
        array $defaultColumns,
        int $defaultPerPage = 25,
        array $allowedFilterValues = [],
    ): self {
        $search = self::boundedText($request->query('q'), 120);
        $filters = [];

        foreach ($allowedFilters as $filter) {
            $value = self::boundedText($request->query($filter), 120);
            if ($value !== null
                && (array_key_exists($filter, $allowedFilterValues) === false
                    || in_array($value, (array) $allowedFilterValues[$filter], true))) {
                $filters[$filter] = $value;
            }
        }

        $sortField = self::identifier($request->query('sort'));
        if ($sortField === null || in_array($sortField, $allowedSorts, true) === false) {
            $sortField = null;
        }

        $direction = strtolower((string) $request->query('direction', 'asc'));
        if (in_array($direction, ['asc', 'desc'], true) === false) {
            $direction = 'asc';
        }

        $columnInput = $request->query('columns');
        if (is_string($columnInput)) {
            $columnInput = explode(',', $columnInput);
        } elseif (is_array($columnInput) === false) {
            $columnInput = [];
        }

        $columns = collect($columnInput)
            ->filter(static fn ($value): bool => is_string($value))
            ->map(static fn (string $column): string => trim($column))
            ->filter(static fn (string $column): bool => in_array($column, $allowedColumns, true))
            ->unique()
            ->take(12)
            ->values()
            ->all();

        if ($columns === []) {
            $columns = collect($defaultColumns)
                ->filter(static fn (string $column): bool => in_array($column, $allowedColumns, true))
                ->unique()
                ->take(12)
                ->values()
                ->all();
        }

        $allowedPerPage = [25, 50, 100];
        $fallbackPerPage = in_array($defaultPerPage, $allowedPerPage, true) ? $defaultPerPage : 25;
        $perPage = (int) $request->query('per_page', $fallbackPerPage);
        if (in_array($perPage, $allowedPerPage, true) === false) {
            $perPage = $fallbackPerPage;
        }

        return new self($search, $filters, $sortField, $direction, $columns, $perPage);
    }

    /**
     * Rehydrates a generic saved state. Page controllers still re-apply their own
     * allowlists when the state is used, so a saved view cannot introduce a new
     * filter, sort or column authority.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromSavedState(array $state): self
    {
        $filters = [];
        foreach ((array) ($state['filters'] ?? []) as $key => $value) {
            $identifier = self::identifier($key);
            $bounded = self::boundedText($value, 120);
            if ($identifier !== null && $bounded !== null && count($filters) < 10) {
                $filters[$identifier] = $bounded;
            }
        }

        $columns = collect((array) ($state['columns'] ?? []))
            ->filter(static fn ($value): bool => is_string($value))
            ->map(static fn (string $column): ?string => self::identifier($column))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();

        $sort = (array) ($state['sort'] ?? []);
        $sortField = self::identifier($sort['field'] ?? null);
        $direction = strtolower((string) ($sort['direction'] ?? 'asc'));
        if (in_array($direction, ['asc', 'desc'], true) === false) {
            $direction = 'asc';
        }

        $perPage = (int) ($state['per_page'] ?? 25);
        if (in_array($perPage, [25, 50, 100], true) === false) {
            $perPage = 25;
        }

        return new self(
            self::boundedText($state['search'] ?? null, 120),
            $filters,
            $sortField,
            $direction,
            $columns,
            $perPage,
        );
    }

    /** @return array<string, mixed> */
    public function toSavedState(): array
    {
        return [
            'search' => $this->search,
            'filters' => $this->filters,
            'sort' => [
                'field' => $this->sortField,
                'direction' => $this->sortDirection,
            ],
            'columns' => $this->columns,
            'per_page' => $this->perPage,
        ];
    }

    /** @return array<string, mixed> */
    public function toQuery(): array
    {
        $query = $this->filters;

        if ($this->search !== null) {
            $query['q'] = $this->search;
        }
        if ($this->sortField !== null) {
            $query['sort'] = $this->sortField;
            $query['direction'] = $this->sortDirection;
        }
        if ($this->columns !== []) {
            $query['columns'] = $this->columns;
        }

        $query['per_page'] = $this->perPage;

        return $query;
    }

    /**
     * Bounded handoff for async export jobs. Pagination is intentionally omitted:
     * exporters consume the same authorized filters/sort/columns in chunks rather
     * than treating a browser page as an unbounded "load all" request.
     *
     * @return array<string, mixed>
     */
    public function toExportHandoff(): array
    {
        return [
            'search' => $this->search,
            'filters' => $this->filters,
            'sort' => [
                'field' => $this->sortField,
                'direction' => $this->sortDirection,
            ],
            'columns' => $this->columns,
        ];
    }

    private static function identifier(mixed $value): ?string
    {
        if (is_string($value) === false) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[a-z][a-z0-9_]{0,79}$/', $value) === 1 ? $value : null;
    }

    private static function boundedText(mixed $value, int $limit): ?string
    {
        if (is_scalar($value) === false) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
