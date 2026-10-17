@php
    $moduleDashboardFilters = is_array($moduleDashboardFilters ?? null) ? $moduleDashboardFilters : [];
    $filterAction = $moduleDashboardFilters['action'] ?? url()->current();
    $scopeName = $moduleDashboardFilters['scope_name'] ?? 'scope';
    $scopeValue = (string) ($moduleDashboardFilters['scope_value'] ?? '');
    $scopeOptions = is_array($moduleDashboardFilters['scope_options'] ?? null) ? $moduleDashboardFilters['scope_options'] : [];
    $fromName = $moduleDashboardFilters['from_name'] ?? 'from';
    $toName = $moduleDashboardFilters['to_name'] ?? 'to';
    $fromValue = $moduleDashboardFilters['from_value'] ?? '';
    $toValue = $moduleDashboardFilters['to_value'] ?? '';
    $hiddenFilters = is_array($moduleDashboardFilters['hidden'] ?? null) ? $moduleDashboardFilters['hidden'] : [];
    $resetUrl = $moduleDashboardFilters['reset_url'] ?? null;
    $exportUrl = $moduleDashboardFilters['export_url'] ?? null;
@endphp

<form
    class="foodex-module-toolbar"
    data-foodex-module-filters
    method="get"
    action="{{ $filterAction }}"
>
    @foreach($hiddenFilters as $hiddenName => $hiddenValue)
        @if(is_scalar($hiddenValue))
            <input type="hidden" name="{{ $hiddenName }}" value="{{ $hiddenValue }}">
        @endif
    @endforeach

    @if($scopeOptions !== [])
        <label class="foodex-module-filter-group foodex-module-scope">
            <span>{{ __('module_dashboard.filters.scope') }}</span>
            <select name="{{ $scopeName }}">
                @foreach($scopeOptions as $optionValue => $optionLabel)
                    @php
                        $resolvedValue = is_array($optionLabel) ? ($optionLabel['value'] ?? $optionValue) : $optionValue;
                        $resolvedLabel = is_array($optionLabel) ? ($optionLabel['label'] ?? $resolvedValue) : $optionLabel;
                    @endphp
                    <option value="{{ $resolvedValue }}" @selected((string) $resolvedValue === $scopeValue)>{{ $resolvedLabel }}</option>
                @endforeach
            </select>
        </label>
    @endif

    <div class="foodex-module-date-range">
        <label class="foodex-module-filter-group">
            <span>{{ __('module_dashboard.filters.from') }}</span>
            <input type="date" name="{{ $fromName }}" value="{{ $fromValue }}">
        </label>
        <label class="foodex-module-filter-group">
            <span>{{ __('module_dashboard.filters.to') }}</span>
            <input type="date" name="{{ $toName }}" value="{{ $toValue }}">
        </label>
        <button class="foodex-filter-action" type="submit">{{ __('module_dashboard.filters.apply') }}</button>
    </div>

    <div class="foodex-module-toolbar-actions">
        @if($resetUrl)
            <a class="foodex-action-secondary button secondary" href="{{ $resetUrl }}">{{ __('module_dashboard.filters.reset') }}</a>
        @endif
        @if($exportUrl)
            <a class="foodex-action-secondary button secondary foodex-module-export" data-foodex-module-export href="{{ $exportUrl }}">
                {{ __('module_dashboard.actions.export') }}
            </a>
        @endif
    </div>
</form>
