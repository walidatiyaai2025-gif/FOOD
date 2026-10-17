@php
$selectedBranch = $tableWorkspace['selected_branch'];
$allTableRows = collect($tableWorkspace['tables']);
@endphp

<section class="so-table-workspace">
    <section class="foodex-card">
        <h2>{{ __('smart_ordering.tables.title') }}</h2>
        <p>{{ __('smart_ordering.tables.description') }}</p>
        <form method="get" class="so-table-branch-filter">
            <input type="hidden" name="tab" value="tables">
            <label>
                {{ __('smart_ordering.tables.branch') }}
                <select name="branch" required>
                    <option value="">{{ __('smart_ordering.tables.choose_branch') }}</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->public_id }}" @selected($selectedBranch?->public_id === $branch->public_id)>
                            {{ $branch->name }} · {{ $stores->firstWhere('id', $branch->store_id)?->name }}
                        </option>
                    @endforeach
                </select>
            </label>
            <button class="foodex-primary" type="submit">{{ __('smart_ordering.filters.apply') }}</button>
        </form>
    </section>

    @if(! $selectedBranch)
        <div class="foodex-empty-state">{{ __('smart_ordering.tables.choose_branch') }}</div>
    @else
        @if($tableWorkspace['can_manage'])
            <div class="so-actions">
                <button class="foodex-primary" type="button" data-so-open-dialog="so-create-floor">
                    + {{ __('smart_ordering.tables.add_floor') }}
                </button>
                <button class="foodex-primary" type="button" data-so-open-dialog="so-create-table">
                    + {{ __('smart_ordering.tables.add_table') }}
                </button>
            </div>
        @endif

        <div class="so-floor-stack">
            @forelse($tableWorkspace['floors'] as $floor)
                <section class="foodex-card so-floor-card">
                    <div class="so-floor-head">
                        <div>
                            <h2>{{ $floor['name'] }}</h2>
                            <div class="so-floor-meta">
                                <span class="badge">{{ $floor['is_active'] ? __('smart_ordering.tables.active') : __('smart_ordering.tables.inactive') }}</span>
                                <span>{{ count($floor['tables']) }} {{ __('smart_ordering.tables.label') }}</span>
                            </div>
                        </div>
                        @if($tableWorkspace['can_manage'])
                            <button class="so-table-secondary" type="button" data-so-open-dialog="floor-{{ $floor['public_id'] }}">
                                {{ __('smart_ordering.tables.edit_floor') }}
                            </button>
                        @endif
                    </div>

                    <div class="so-floor-canvas" data-so-floor-canvas aria-label="{{ $floor['name'] }}">
                        @forelse($floor['tables'] as $table)
                            <article
                                class="so-table-tile"
                                data-so-table-tile
                                data-draggable="{{ $table['can_manage'] ? '1' : '0' }}"
                                data-occupancy="{{ $table['occupancy'] }}"
                                data-active="{{ $table['is_active'] ? '1' : '0' }}"
                                data-shape="{{ $table['shape'] }}"
                                style="--so-x:{{ $table['position_x'] }};--so-y:{{ $table['position_y'] }};--so-w:{{ $table['visual_width'] }};--so-h:{{ $table['visual_height'] }}"
                            >
                                <div>
                                    <div class="so-table-name">{{ $table['label'] }}</div>
                                    <div class="so-table-details">
                                        <span>{{ $table['capacity'] }} {{ __('smart_ordering.tables.capacity') }}</span>
                                        <span>·</span>
                                        <span>{{ __('smart_ordering.tables.'.$table['occupancy']) }}</span>
                                    </div>
                                    <div class="so-table-status">
                                        {{ $table['open_orders'] > 0
                                            ? __('smart_ordering.tables.open_orders', ['count' => $table['open_orders']])
                                            : __('smart_ordering.tables.no_open_orders') }}
                                    </div>
                                    @if($table['combination'])
                                        <div class="so-table-status">
                                            {{ __('smart_ordering.tables.combined_with', ['table' => $table['combination']['partner_label']]) }}
                                        </div>
                                    @endif
                                </div>
                                <div class="so-table-tile-actions">
                                    <span class="badge">{{ $table['qr_code_id'] ? __('smart_ordering.tables.qr_bound') : __('smart_ordering.tables.qr_unbound') }}</span>
                                    <button class="so-table-manage" type="button" data-so-open-dialog="table-{{ $table['public_id'] }}">
                                        {{ __('smart_ordering.tables.edit_table') }}
                                    </button>
                                </div>
                                @if($table['can_manage'])
                                    <form method="post" action="{{ route('admin.smart-ordering.tables.position', [$selectedBranch->public_id, $table['public_id']]) }}" data-so-position-form hidden>
                                        @csrf
                                        <input type="hidden" name="position_x" value="{{ $table['position_x'] }}">
                                        <input type="hidden" name="position_y" value="{{ $table['position_y'] }}">
                                    </form>
                                @endif
                            </article>
                        @empty
                            <div class="so-table-empty-floor">{{ __('smart_ordering.tables.no_tables') }}</div>
                        @endforelse
                    </div>
                    <div class="so-floor-meta">
                        <span>{{ __('smart_ordering.tables.drag_hint') }}</span>
                        <span class="so-table-save-status" data-so-save-status role="status"></span>
                    </div>
                </section>

                @if($tableWorkspace['can_manage'])
                    <dialog class="so-dialog" id="floor-{{ $floor['public_id'] }}">
                        <div class="so-dialog-body">
                            <div class="so-dialog-head">
                                <h2>{{ __('smart_ordering.tables.edit_floor') }} · {{ $floor['name'] }}</h2>
                                <button type="button" class="so-close" data-so-close-dialog>×</button>
                            </div>
                            <form method="post" action="{{ route('admin.smart-ordering.tables.floors.update', [$selectedBranch->public_id, $floor['public_id']]) }}" class="so-table-form">
                                @csrf
                                @method('PUT')
                                <label>{{ __('smart_ordering.tables.floor_name_en') }}<input name="name_en" required maxlength="120" value="{{ $floor['name_en'] }}"></label> {{-- localization-gate: allow — bilingual admin edit field renders its persisted English value; visible label is localized --}}
                                <label>{{ __('smart_ordering.tables.floor_name_ar') }}<input name="name_ar" required maxlength="120" value="{{ $floor['name_ar'] }}"></label> {{-- localization-gate: allow — bilingual admin edit field renders its persisted Arabic value; visible label is localized --}}
                                <label>{{ __('smart_ordering.tables.sort_order') }}<input name="sort_order" type="number" min="0" max="999" value="{{ $floor['sort_order'] }}"></label>
                                <label class="so-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($floor['is_active'])> {{ __('smart_ordering.tables.active') }}</label>
                                <div class="so-table-form-actions"><button class="foodex-primary" type="submit">{{ __('smart_ordering.tables.save_floor') }}</button></div>
                            </form>
                            <form method="post" action="{{ route('admin.smart-ordering.tables.floors.destroy', [$selectedBranch->public_id, $floor['public_id']]) }}" onsubmit="return confirm(@js(__('smart_ordering.tables.confirm_delete_floor')))">
                                @csrf
                                @method('DELETE')
                                <button class="so-table-danger" type="submit">{{ __('smart_ordering.tables.delete_floor') }}</button>
                            </form>
                        </div>
                    </dialog>
                @endif
            @empty
                <div class="foodex-empty-state">{{ __('smart_ordering.tables.no_floors') }}</div>
            @endforelse

            @if(count($tableWorkspace['unassigned_tables']))
                <section class="foodex-card so-floor-card">
                    <div class="so-floor-head"><h2>{{ __('smart_ordering.tables.unassigned') }}</h2></div>
                    <div class="so-table-grid-fallback">
                        @foreach($tableWorkspace['unassigned_tables'] as $table)
                            <article class="foodex-card so-table-grid-card">
                                <strong>{{ $table['label'] }}</strong>
                                <div class="so-table-details">
                                    <span>{{ $table['capacity'] }} {{ __('smart_ordering.tables.capacity') }}</span>
                                    <span>{{ __('smart_ordering.tables.'.$table['occupancy']) }}</span>
                                    <span>{{ $table['is_active'] ? __('smart_ordering.tables.active') : __('smart_ordering.tables.inactive') }}</span>
                                </div>
                                <button class="so-table-manage" type="button" data-so-open-dialog="table-{{ $table['public_id'] }}">
                                    {{ __('smart_ordering.tables.edit_table') }}
                                </button>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        @foreach($tableWorkspace['tables'] as $table)
            <dialog class="so-dialog" id="table-{{ $table['public_id'] }}">
                <div class="so-dialog-body">
                    <div class="so-dialog-head">
                        <div>
                            <h2>{{ __('smart_ordering.tables.edit_table') }} · {{ $table['label'] }}</h2>
                            <div class="so-floor-meta">
                                <span class="badge">{{ __('smart_ordering.tables.'.$table['occupancy']) }}</span>
                                <span>{{ $table['open_orders'] > 0 ? __('smart_ordering.tables.open_orders', ['count' => $table['open_orders']]) : __('smart_ordering.tables.no_open_orders') }}</span>
                            </div>
                        </div>
                        <button type="button" class="so-close" data-so-close-dialog>×</button>
                    </div>

                    @if($table['can_manage'])
                        <form method="post" action="{{ route('admin.smart-ordering.tables.update', [$selectedBranch->public_id, $table['public_id']]) }}" class="so-table-form">
                            @csrf
                            @method('PUT')
                            <label>{{ __('smart_ordering.tables.label') }}<input name="label" required maxlength="80" value="{{ $table['label'] }}"></label>
                            <label>{{ __('smart_ordering.tables.floor') }}
                                <select name="floor_public_id">
                                    <option value="">{{ __('smart_ordering.tables.unassigned') }}</option>
                                    @foreach($tableWorkspace['floors'] as $floorOption)
                                        <option value="{{ $floorOption['public_id'] }}" @selected($table['floor_public_id'] === $floorOption['public_id'])>{{ $floorOption['name'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>{{ __('smart_ordering.tables.capacity') }}<input name="capacity" type="number" min="1" max="50" required value="{{ $table['capacity'] }}"></label>
                            <label>{{ __('smart_ordering.tables.shape') }}
                                <select name="shape">
                                    @foreach(['rectangle','round'] as $shape)
                                        <option value="{{ $shape }}" @selected($table['shape'] === $shape)>{{ __('smart_ordering.tables.'.$shape) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>{{ __('smart_ordering.tables.visual_width') }}<input name="visual_width" type="number" min="80" max="500" required value="{{ $table['visual_width'] }}"></label>
                            <label>{{ __('smart_ordering.tables.visual_height') }}<input name="visual_height" type="number" min="70" max="500" required value="{{ $table['visual_height'] }}"></label>
                            <input type="hidden" name="position_x" value="{{ $table['position_x'] }}">
                            <input type="hidden" name="position_y" value="{{ $table['position_y'] }}">
                            <label class="so-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($table['is_active'])> {{ __('smart_ordering.tables.active') }}</label>
                            <div class="so-table-form-actions"><button class="foodex-primary" type="submit">{{ __('smart_ordering.tables.save_table') }}</button></div>
                        </form>

                        <div class="so-table-qr">
                            <strong>{{ $table['qr_code_id'] ? __('smart_ordering.tables.qr_bound') : __('smart_ordering.tables.qr_unbound') }}</strong>
                            @if($table['can_manage_qr'])
                                @if($table['qr_code_id'])
                                    <form method="post" action="{{ route('admin.smart-ordering.tables.qr.unbind', [$selectedBranch->public_id, $table['public_id']]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="so-table-secondary" type="submit">{{ __('smart_ordering.tables.unbind_qr') }}</button>
                                    </form>
                                    <a class="so-table-secondary" href="{{ route('admin.smart-ordering.qr.index', ['q' => $table['label'], 'type' => 'table']) }}">{{ __('smart_ordering.tables.manage_qr') }}</a>
                                @else
                                    <form method="post" action="{{ route('admin.smart-ordering.tables.qr.bind', [$selectedBranch->public_id, $table['public_id']]) }}">
                                        @csrf
                                        <button class="foodex-primary" type="submit">{{ __('smart_ordering.tables.bind_qr') }}</button>
                                    </form>
                                @endif
                            @endif
                        </div>

                        @if($table['combination'])
                            <div class="so-table-qr">
                                <strong>{{ __('smart_ordering.tables.combined_with', ['table' => $table['combination']['partner_label']]) }}</strong>
                                @if($table['combination']['is_primary'])
                                    <form method="post" action="{{ route('admin.smart-ordering.tables.split', [$selectedBranch->public_id, $table['combination']['public_id']]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="so-table-secondary" type="submit">{{ __('smart_ordering.tables.split') }}</button>
                                    </form>
                                @endif
                            </div>
                        @else
                            @php
                                $combineOptions = $allTableRows
                                    ->filter(fn($candidate) => $candidate['public_id'] !== $table['public_id']
                                        && $candidate['is_active']
                                        && ! $candidate['combination']);
                            @endphp
                            @if($table['is_active'] && $combineOptions->isNotEmpty())
                                <form method="post" action="{{ route('admin.smart-ordering.tables.combine', [$selectedBranch->public_id, $table['public_id']]) }}" class="so-table-combine">
                                    @csrf
                                    <label>{{ __('smart_ordering.tables.combine_with') }}
                                        <select name="other_table_public_id" required>
                                            <option value="">{{ __('smart_ordering.tables.combine_with') }}</option>
                                            @foreach($combineOptions as $candidate)
                                                <option value="{{ $candidate['public_id'] }}">{{ $candidate['label'] }} · {{ $candidate['floor_name'] ?: __('smart_ordering.tables.unassigned') }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>{{ __('smart_ordering.tables.combine_note') }}<input name="note" maxlength="500"></label>
                                    <button class="foodex-primary" type="submit">{{ __('smart_ordering.tables.combine') }}</button>
                                </form>
                            @endif
                        @endif

                        <form method="post" action="{{ route('admin.smart-ordering.tables.destroy', [$selectedBranch->public_id, $table['public_id']]) }}" onsubmit="return confirm(@js(__('smart_ordering.tables.confirm_delete_table')))">
                            @csrf
                            @method('DELETE')
                            <button class="so-table-danger" type="submit">{{ __('smart_ordering.tables.delete_table') }}</button>
                        </form>
                    @endif
                </div>
            </dialog>
        @endforeach

        @if($tableWorkspace['can_manage'])
            <dialog class="so-dialog" id="so-create-floor">
                <div class="so-dialog-body">
                    <div class="so-dialog-head"><h2>{{ __('smart_ordering.tables.add_floor') }}</h2><button type="button" class="so-close" data-so-close-dialog>×</button></div>
                    <form method="post" action="{{ route('admin.smart-ordering.tables.floors.store', $selectedBranch->public_id) }}" class="so-table-form">
                        @csrf
                        <label>{{ __('smart_ordering.tables.floor_name_en') }}<input name="name_en" required maxlength="120"></label> {{-- localization-gate: allow — bilingual admin create field key is technical; visible label is localized --}}
                        <label>{{ __('smart_ordering.tables.floor_name_ar') }}<input name="name_ar" required maxlength="120"></label> {{-- localization-gate: allow — bilingual admin create field key is technical; visible label is localized --}}
                        <label>{{ __('smart_ordering.tables.sort_order') }}<input name="sort_order" type="number" min="0" max="999" value="0"></label>
                        <label class="so-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> {{ __('smart_ordering.tables.active') }}</label>
                        <div class="so-table-form-actions"><button class="foodex-primary" type="submit">{{ __('smart_ordering.tables.save_floor') }}</button></div>
                    </form>
                </div>
            </dialog>

            <dialog class="so-dialog" id="so-create-table">
                <div class="so-dialog-body">
                    <div class="so-dialog-head"><h2>{{ __('smart_ordering.tables.add_table') }}</h2><button type="button" class="so-close" data-so-close-dialog>×</button></div>
                    <form method="post" action="{{ route('admin.smart-ordering.tables.store', $selectedBranch->public_id) }}" class="so-table-form">
                        @csrf
                        <label>{{ __('smart_ordering.tables.label') }}<input name="label" required maxlength="80"></label>
                        <label>{{ __('smart_ordering.tables.floor') }}
                            <select name="floor_public_id">
                                <option value="">{{ __('smart_ordering.tables.unassigned') }}</option>
                                @foreach($tableWorkspace['floors'] as $floorOption)
                                    <option value="{{ $floorOption['public_id'] }}">{{ $floorOption['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>{{ __('smart_ordering.tables.capacity') }}<input name="capacity" type="number" min="1" max="50" required value="2"></label>
                        <label>{{ __('smart_ordering.tables.shape') }}
                            <select name="shape"><option value="rectangle">{{ __('smart_ordering.tables.rectangle') }}</option><option value="round">{{ __('smart_ordering.tables.round') }}</option></select>
                        </label>
                        <label>{{ __('smart_ordering.tables.visual_width') }}<input name="visual_width" type="number" min="80" max="500" required value="160"></label>
                        <label>{{ __('smart_ordering.tables.visual_height') }}<input name="visual_height" type="number" min="70" max="500" required value="120"></label>
                        <input type="hidden" name="position_x" value="60">
                        <input type="hidden" name="position_y" value="60">
                        <label class="so-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> {{ __('smart_ordering.tables.active') }}</label>
                        <div class="so-table-form-actions"><button class="foodex-primary" type="submit">{{ __('smart_ordering.tables.save_table') }}</button></div>
                    </form>
                </div>
            </dialog>
        @endif
    @endif
</section>

<script>
window.FoodexRestaurantTables = {
    saving: @json(__('smart_ordering.tables.position_saving')),
    saved: @json(__('smart_ordering.tables.position_saved')),
    error: @json(__('smart_ordering.tables.position_error'))
};
</script>
<script src="{{ asset('assets/admin/smart-ordering-tables.js') }}"></script>
