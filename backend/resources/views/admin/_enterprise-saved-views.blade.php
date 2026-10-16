<section class="foodex-enterprise-viewbar" data-enterprise-saved-views="{{ $surfaceKey }}">
    <div class="foodex-enterprise-viewbar__head">
        <div>
            <strong>{{ __('enterprise_grid.saved_views') }}</strong>
            <p>{{ __('enterprise_grid.saved_views_description') }}</p>
        </div>
        <details class="foodex-enterprise-viewbar__save">
            <summary class="foodex-primary">{{ __('enterprise_grid.save_current') }}</summary>
            <form method="post" action="{{ route('admin.enterprise-saved-views.store') }}" class="foodex-enterprise-viewbar__form">
                @csrf
                <input type="hidden" name="surface_key" value="{{ $surfaceKey }}">
                <input type="hidden" name="scope_type" value="{{ $scopeType }}">
                @if($scopeId !== null)<input type="hidden" name="scope_id" value="{{ $scopeId }}">@endif
                <input type="hidden" name="state_token" value="{{ $stateToken }}">
                <label>
                    <span>{{ __('enterprise_grid.view_name') }}</span>
                    <input type="text" name="name" maxlength="120" required autocomplete="off">
                </label>
                <label>
                    <span>{{ __('enterprise_grid.visibility') }}</span>
                    <select name="visibility">
                        <option value="private">{{ __('enterprise_grid.private') }}</option>
                        <option value="shared">{{ __('enterprise_grid.shared') }}</option>
                    </select>
                </label>
                <button class="foodex-primary" type="submit">{{ __('enterprise_grid.save_current') }}</button>
            </form>
        </details>
    </div>

    @if($savedViews->isEmpty())
        <div class="foodex-enterprise-viewbar__empty">{{ __('enterprise_grid.none_saved') }}</div>
    @else
        <div class="foodex-enterprise-viewbar__items">
            @foreach($savedViews as $savedView)
                <div class="foodex-enterprise-viewbar__item">
                    <a href="{{ $applyRoute }}?{{ http_build_query($savedView['query']) }}">{{ $savedView['name'] }}</a>
                    <span class="badge">{{ $savedView['visibility'] === 'shared' ? __('enterprise_grid.shared') : __('enterprise_grid.private') }}</span>
                    @if($savedView['owned'])
                        <span class="badge">{{ __('enterprise_grid.owned') }}</span>
                        <form method="post" action="{{ route('admin.enterprise-saved-views.destroy', ['savedView' => $savedView['id']]) }}">
                            @csrf
                            @method('delete')
                            <button type="submit" class="foodex-enterprise-viewbar__delete">{{ __('enterprise_grid.delete') }}</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
