@php($workforce = $workforceWorkspace)
<section class="foodex-card so-workforce-head">
    <div>
        <h2>{{ __('smart_ordering.workforce.title') }}</h2>
        <p>{{ __('smart_ordering.workforce.description') }}</p>
    </div>
    <form method="get" class="so-workforce-branch">
        <input type="hidden" name="tab" value="workforce">
        <label>{{ __('smart_ordering.workforce.branch') }}
            <select name="branch" onchange="this.form.submit()">
                @foreach($branches as $branchOption)
                    <option value="{{ $branchOption->public_id }}" @selected($workforce['selected_branch']?->public_id === $branchOption->public_id)>{{ $branchOption->name }}</option>
                @endforeach
            </select>
        </label>
    </form>
</section>

@if(! $workforce['selected_branch'])
    <div class="foodex-empty-state">{{ __('smart_ordering.workforce.no_branch') }}</div>
@else
    @php($workforceBranch = $workforce['selected_branch'])
    <div class="so-workforce-meta">{{ __('smart_ordering.workforce.timezone', ['timezone' => $workforce['timezone']]) }}</div>

    <div class="so-workforce-grid">
        <section class="foodex-card">
            <div class="so-workforce-section-head">
                <div><h3>{{ __('smart_ordering.workforce.staff_title') }}</h3><p>{{ __('smart_ordering.workforce.staff_description') }}</p></div>
            </div>
            @if($workforce['can_manage'] && count($workforce['available_staff']))
                <form method="post" action="{{ route('admin.smart-ordering.workforce.staff.store', $workforceBranch->public_id) }}" class="so-workforce-inline">
                    @csrf
                    <label>{{ __('smart_ordering.workforce.staff_member') }}
                        <select name="user_id" required>
                            <option value="">{{ __('smart_ordering.workforce.choose_staff') }}</option>
                            @foreach($workforce['available_staff'] as $identity)
                                <option value="{{ $identity['user_id'] }}">
                                    {{ $identity['name'] }} · {{ collect($identity['roles'])->pluck('label')->implode(' / ') }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <button class="foodex-primary" type="submit">{{ __('smart_ordering.workforce.assign_staff') }}</button>
                </form>
            @endif

            <div class="so-workforce-list">
                @forelse($workforce['assignments'] as $assignment)
                    <article class="so-workforce-item" data-workforce-user-id="{{ $assignment['user_id'] }}">
                        <div>
                            <strong>{{ $assignment['name'] }}</strong>
                            <div class="so-workforce-muted">{{ $assignment['email'] }}</div>
                            <div class="so-workforce-role">{{ collect($assignment['roles'])->pluck('label')->implode(' / ') }}</div>
                        </div>
                        @if($workforce['can_manage'])
                            <form method="post" action="{{ route('admin.smart-ordering.workforce.staff.destroy', [$workforceBranch->public_id, $assignment['user_id']]) }}">
                                @csrf @method('DELETE')
                                <button class="foodex-secondary" type="submit">{{ __('smart_ordering.workforce.remove_staff') }}</button>
                            </form>
                        @endif
                    </article>
                @empty
                    <div class="foodex-empty-state">{{ __('smart_ordering.workforce.no_staff') }}</div>
                @endforelse
            </div>
        </section>

        <section class="foodex-card">
            <div class="so-workforce-section-head">
                <div><h3>{{ __('smart_ordering.workforce.time_clock_title') }}</h3><p>{{ __('smart_ordering.workforce.time_clock_description') }}</p></div>
            </div>
            @if(! $workforce['actor_assigned'])
                <div class="foodex-empty-state">{{ __('smart_ordering.workforce.not_assigned') }}</div>
            @else
                <div class="so-workforce-clock">
                    @php($clock = $workforce['current_session'])
                    @if(! $clock)
                        <form method="post" action="{{ route('admin.smart-ordering.workforce.clock', $workforceBranch->public_id) }}">
                            @csrf
                            <input type="hidden" name="action" value="clock_in">
                            <button class="foodex-primary" type="submit">{{ __('smart_ordering.workforce.clock_in') }}</button>
                        </form>
                    @elseif($clock->status === 'OPEN')
                        <span class="badge">{{ __('smart_ordering.workforce.clock_statuses.OPEN') }}</span>
                        <form method="post" action="{{ route('admin.smart-ordering.workforce.clock', $workforceBranch->public_id) }}">@csrf<input type="hidden" name="action" value="break_start"><button class="foodex-secondary" type="submit">{{ __('smart_ordering.workforce.break_start') }}</button></form>
                        <form method="post" action="{{ route('admin.smart-ordering.workforce.clock', $workforceBranch->public_id) }}">@csrf<input type="hidden" name="action" value="clock_out"><button class="foodex-primary" type="submit">{{ __('smart_ordering.workforce.clock_out') }}</button></form>
                    @else
                        <span class="badge">{{ __('smart_ordering.workforce.clock_statuses.ON_BREAK') }}</span>
                        <form method="post" action="{{ route('admin.smart-ordering.workforce.clock', $workforceBranch->public_id) }}">@csrf<input type="hidden" name="action" value="break_end"><button class="foodex-primary" type="submit">{{ __('smart_ordering.workforce.break_end') }}</button></form>
                    @endif
                </div>
            @endif
        </section>
    </div>

    <section class="foodex-card">
        <div class="so-workforce-section-head">
            <div><h3>{{ __('smart_ordering.workforce.shifts_title') }}</h3><p>{{ __('smart_ordering.workforce.shifts_description') }}</p></div>
        </div>

        @if($workforce['can_manage'] && count($workforce['assignments']))
            <form method="post" action="{{ route('admin.smart-ordering.workforce.shifts.store', $workforceBranch->public_id) }}" class="so-workforce-form">
                @csrf
                <label>{{ __('smart_ordering.workforce.staff_member') }}
                    <select name="user_id" required>
                        @foreach($workforce['assignments'] as $assignment)<option value="{{ $assignment['user_id'] }}">{{ $assignment['name'] }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('smart_ordering.workforce.starts_at') }}<input type="datetime-local" name="starts_at" required></label>
                <label>{{ __('smart_ordering.workforce.ends_at') }}<input type="datetime-local" name="ends_at" required></label>
                <label class="so-workforce-wide">{{ __('smart_ordering.workforce.notes') }}<input name="notes" maxlength="1000"></label>
                <button class="foodex-primary" type="submit">{{ __('smart_ordering.workforce.create_shift') }}</button>
            </form>
        @endif

        <div class="so-workforce-list">
            @forelse($workforce['shifts'] as $shift)
                <article class="so-workforce-item so-workforce-shift" data-workforce-user-id="{{ $shift['user_id'] }}">
                    <div>
                        <strong>{{ $shift['user_name'] }}</strong>
                        <div>{{ $shift['starts_local'] }} → {{ $shift['ends_local'] }}</div>
                        <span class="badge">{{ __('smart_ordering.workforce.shift_statuses.'.$shift['status']) }}</span>
                        @if($shift['notes'])<div class="so-workforce-muted">{{ $shift['notes'] }}</div>@endif
                    </div>
                    @if($workforce['can_manage'] && $shift['status'] !== 'CANCELLED')
                        <details class="so-row-actions">
                            <summary aria-label="{{ __('smart_ordering.workforce.shift_actions') }}">⋮</summary>
                            <div class="so-menu so-workforce-menu">
                                <form method="post" action="{{ route('admin.smart-ordering.workforce.shifts.update', [$workforceBranch->public_id, $shift['public_id']]) }}">
                                    @csrf @method('PUT')
                                    <label>{{ __('smart_ordering.workforce.staff_member') }}
                                        <select name="user_id" required>
                                            @foreach($workforce['assignments'] as $assignment)<option value="{{ $assignment['user_id'] }}" @selected((int)$shift['user_id'] === (int)$assignment['user_id'])>{{ $assignment['name'] }}</option>@endforeach
                                        </select>
                                    </label>
                                    <label>{{ __('smart_ordering.workforce.starts_at') }}<input type="datetime-local" name="starts_at" value="{{ $shift['starts_input'] }}" required></label>
                                    <label>{{ __('smart_ordering.workforce.ends_at') }}<input type="datetime-local" name="ends_at" value="{{ $shift['ends_input'] }}" required></label>
                                    <label>{{ __('smart_ordering.workforce.notes') }}<input name="notes" maxlength="1000" value="{{ $shift['notes'] }}"></label>
                                    <button class="foodex-primary" type="submit">{{ __('smart_ordering.workforce.save_shift') }}</button>
                                </form>
                                @if($shift['status'] === 'DRAFT')
                                    <form method="post" action="{{ route('admin.smart-ordering.workforce.shifts.transition', [$workforceBranch->public_id, $shift['public_id']]) }}">@csrf<input type="hidden" name="action" value="publish"><button class="foodex-primary" type="submit">{{ __('smart_ordering.workforce.publish_shift') }}</button></form>
                                @endif
                                <form method="post" action="{{ route('admin.smart-ordering.workforce.shifts.transition', [$workforceBranch->public_id, $shift['public_id']]) }}">@csrf<input type="hidden" name="action" value="cancel"><button class="foodex-secondary" type="submit">{{ __('smart_ordering.workforce.cancel_shift') }}</button></form>
                            </div>
                        </details>
                    @endif
                </article>
            @empty
                <div class="foodex-empty-state">{{ __('smart_ordering.workforce.no_shifts') }}</div>
            @endforelse
        </div>
    </section>

    <section class="foodex-card">
        <div class="so-workforce-section-head">
            <div><h3>{{ __('smart_ordering.workforce.sessions_title') }}</h3><p>{{ __('smart_ordering.workforce.sessions_description') }}</p></div>
        </div>
        <div class="so-workforce-list">
            @forelse($workforce['sessions'] as $session)
                <article class="so-workforce-item so-workforce-session" data-workforce-user-id="{{ $session['user_id'] }}">
                    <div>
                        <strong>{{ $session['user_name'] }}</strong>
                        <div>{{ $session['clocked_in_local'] }} → {{ $session['clocked_out_local'] ?: __('smart_ordering.workforce.open_session') }}</div>
                        <span class="badge">{{ __('smart_ordering.workforce.clock_statuses.'.$session['status']) }}</span>
                        <div class="so-workforce-muted">{{ __('smart_ordering.workforce.break_minutes', ['minutes' => $session['total_break_minutes']]) }}</div>
                        @if($session['correction_note'])<div class="so-workforce-muted">{{ __('smart_ordering.workforce.corrected') }} · {{ $session['correction_note'] }}</div>@endif
                    </div>
                    @if($workforce['can_manage'] && $session['status'] === 'CLOSED')
                        <details class="so-row-actions">
                            <summary aria-label="{{ __('smart_ordering.workforce.correct_session') }}">⋮</summary>
                            <div class="so-menu so-workforce-menu">
                                <form method="post" action="{{ route('admin.smart-ordering.workforce.sessions.correct', [$workforceBranch->public_id, $session['public_id']]) }}">
                                    @csrf @method('PUT')
                                    <label>{{ __('smart_ordering.workforce.clocked_in_at') }}<input type="datetime-local" name="clocked_in_at" value="{{ $session['clocked_in_input'] }}" required></label>
                                    <label>{{ __('smart_ordering.workforce.clocked_out_at') }}<input type="datetime-local" name="clocked_out_at" value="{{ $session['clocked_out_input'] }}" required></label>
                                    <label>{{ __('smart_ordering.workforce.total_break_minutes') }}<input type="number" name="total_break_minutes" min="0" max="1440" value="{{ $session['total_break_minutes'] }}" required></label>
                                    <label>{{ __('smart_ordering.workforce.correction_reason') }}<input name="reason" minlength="5" maxlength="1000" required></label>
                                    <button class="foodex-primary" type="submit">{{ __('smart_ordering.workforce.save_correction') }}</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </article>
            @empty
                <div class="foodex-empty-state">{{ __('smart_ordering.workforce.no_sessions') }}</div>
            @endforelse
        </div>
    </section>
@endif
