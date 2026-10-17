@php
$selectedBranch = $guestWorkspace['selected_branch'];
$guestRecords = $guestWorkspace['records'];
$isWaitlist = $tab === 'waitlist';
@endphp

<section class="so-guest-workspace">
    <section class="foodex-card">
        <div class="so-guest-head">
            <div>
                <h2>{{ $isWaitlist ? __('smart_ordering.guest_service.waitlist_title') : __('smart_ordering.guest_service.reservations_title') }}</h2>
                <p>{{ $isWaitlist ? __('smart_ordering.guest_service.waitlist_description') : __('smart_ordering.guest_service.reservations_description') }}</p>
            </div>
            @if($selectedBranch && $guestWorkspace['can_manage'])
                <button class="foodex-primary" type="button" onclick="document.getElementById('{{ $isWaitlist ? 'so-create-waitlist' : 'so-create-reservation' }}').showModal()">
                    + {{ $isWaitlist ? __('smart_ordering.guest_service.add_waitlist') : __('smart_ordering.guest_service.add_reservation') }}
                </button>
            @endif
        </div>

        <form method="get" class="so-guest-branch-filter">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <label>
                {{ __('smart_ordering.guest_service.branch') }}
                <select name="branch" required>
                    <option value="">{{ __('smart_ordering.guest_service.choose_branch') }}</option>
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
        <div class="foodex-empty-state">{{ __('smart_ordering.guest_service.choose_branch') }}</div>
    @elseif(! ($guestWorkspace['module_enabled'] ?? false))
        <div class="foodex-state" role="status" data-guest-service-disabled>
            <strong>{{ __('smart_ordering.guest_service.module_disabled_title') }}</strong>
            <div>{{ __('smart_ordering.guest_service.module_disabled_description') }}</div>
        </div>
    @else
        <section class="foodex-card so-guest-table-wrap">
            <table class="foodex-table" data-pagination-required>
                <thead>
                    <tr>
                        @if($isWaitlist)
                            @foreach(['guest','party','arrival','quoted_wait','priority','status','table','customer','actions'] as $column)
                                <th>{{ __('smart_ordering.guest_service.columns.'.$column) }}</th>
                            @endforeach
                        @else
                            @foreach(['guest','party','service_time','status','table','customer','actions'] as $column)
                                <th>{{ __('smart_ordering.guest_service.columns.'.$column) }}</th>
                            @endforeach
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($guestRecords as $row)
                        <tr id="{{ $isWaitlist ? 'waitlist-' : 'reservation-' }}{{ $row['public_id'] }}">
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.guest') }}">
                                <div class="so-guest-person">
                                    <strong>{{ $row['guest_name'] }}</strong>
                                    @if($row['guest_phone'])<small>{{ $row['guest_phone'] }}</small>@endif
                                    @if($row['guest_email'])<small>{{ $row['guest_email'] }}</small>@endif
                                </div>
                            </td>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.party') }}">{{ $row['party_size'] }}</td>
                            @if($isWaitlist)
                                <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.arrival') }}">{{ $row['arrival_display'] }}</td>
                                <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.quoted_wait') }}">
                                    {{ $row['quoted_wait_minutes'] === null ? '—' : __('smart_ordering.guest_service.minutes',['count'=>$row['quoted_wait_minutes']]) }}
                                </td>
                                <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.priority') }}">
                                    <span class="so-guest-priority">{{ $row['priority'] }}</span>
                                    @if($row['priority_reason'])<div class="so-guest-meta">{{ $row['priority_reason'] }}</div>@endif
                                </td>
                            @else
                                <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.service_time') }}">{{ $row['service_display'] }}</td>
                            @endif
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.status') }}">
                                <span class="badge">{{ __('smart_ordering.guest_service.statuses.'.$row['status']) }}</span>
                            </td>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.table') }}">{{ $row['table_label'] ?: __('smart_ordering.guest_service.unassigned') }}</td>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.customer') }}">
                                {{ $row['customer_linked'] ? __('smart_ordering.guest_service.customer_linked') : __('smart_ordering.guest_service.restaurant_guest') }}
                            </td>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.actions') }}">
                                @if($guestWorkspace['can_manage'])
                                    <details class="so-row-actions">
                                        <summary aria-label="{{ __('smart_ordering.guest_service.manage') }}">⋮</summary>
                                        <div class="so-menu">
                                            <button class="foodex-primary so-guest-record-link" type="button" onclick="document.getElementById('{{ $isWaitlist ? 'manage-waitlist-' : 'manage-reservation-' }}{{ $row['public_id'] }}').showModal()">
                                                {{ __('smart_ordering.guest_service.manage') }}
                                            </button>
                                        </div>
                                    </details>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>

                        @if($guestWorkspace['can_manage'])
                            <dialog class="so-dialog" id="{{ $isWaitlist ? 'manage-waitlist-' : 'manage-reservation-' }}{{ $row['public_id'] }}">
                                <div class="so-dialog-body">
                                    <div class="so-dialog-head">
                                        <div>
                                            <h2>{{ $row['guest_name'] }}</h2>
                                            <div class="so-guest-meta">{{ __('smart_ordering.guest_service.statuses.'.$row['status']) }}</div>
                                        </div>
                                        <button class="so-close" type="button" aria-label="{{ __('smart_ordering.guest_service.close') }}" onclick="this.closest('dialog').close()">×</button>
                                    </div>

                                    @if($isWaitlist)
                                        @if(! in_array($row['status'], ['CONVERTED','CANCELLED'], true))
                                            <form method="post" action="{{ route('admin.smart-ordering.waitlist.update',[$selectedBranch->public_id,$row['public_id']]) }}" class="so-guest-form-grid">
                                                @csrf
                                                @method('PUT')
                                                <label>{{ __('smart_ordering.guest_service.fields.guest_name') }}<input name="guest_name" required maxlength="160" value="{{ $row['guest_name'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.party_size') }}<input name="party_size" type="number" min="1" max="50" required value="{{ $row['party_size'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.phone') }}<input name="guest_phone" maxlength="60" value="{{ $row['guest_phone'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.email') }}<input name="guest_email" type="email" maxlength="190" value="{{ $row['guest_email'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.quoted_wait') }}<input name="quoted_wait_minutes" type="number" min="0" max="1440" value="{{ $row['quoted_wait_minutes'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.priority') }}<input name="priority" type="number" min="0" max="10" required value="{{ $row['priority'] }}"></label>
                                                <label class="so-guest-span">{{ __('smart_ordering.guest_service.fields.priority_reason') }}<input name="priority_reason" maxlength="255" value="{{ $row['priority_reason'] }}"></label>
                                                <label class="so-guest-span">{{ __('smart_ordering.guest_service.fields.notes') }}<textarea name="notes" maxlength="1000">{{ $row['notes'] }}</textarea></label>
                                                <div class="so-guest-span"><button class="foodex-primary" type="submit">{{ __('smart_ordering.guest_service.save') }}</button></div>
                                            </form>

                                            <div class="so-guest-status-actions">
                                                @foreach($row['allowed_targets'] as $target)
                                                    <form method="post" action="{{ route('admin.smart-ordering.waitlist.transition',[$selectedBranch->public_id,$row['public_id']]) }}" @if($target==='CANCELLED') onsubmit="return confirm(@js(__('smart_ordering.guest_service.confirm_cancel_waitlist')))" @endif>
                                                        @csrf
                                                        <input type="hidden" name="status" value="{{ $target }}">
                                                        <button class="foodex-primary" type="submit">{{ __('smart_ordering.guest_service.statuses.'.$target) }}</button>
                                                    </form>
                                                @endforeach
                                            </div>

                                            <form method="post" action="{{ route('admin.smart-ordering.waitlist.convert',[$selectedBranch->public_id,$row['public_id']]) }}" class="so-guest-convert">
                                                @csrf
                                                <label>{{ __('smart_ordering.guest_service.fields.table') }}
                                                    <select name="table_public_id">
                                                        <option value="">{{ __('smart_ordering.guest_service.unassigned') }}</option>
                                                        @foreach($guestWorkspace['tables'] as $table)
                                                            <option value="{{ $table['public_id'] }}">{{ $table['label'] }} · {{ __('smart_ordering.guest_service.seats',['count'=>$table['capacity']]) }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="so-guest-check"><input type="hidden" name="seat_now" value="0"><input type="checkbox" name="seat_now" value="1"> {{ __('smart_ordering.guest_service.seat_now') }}</label>
                                                <button class="foodex-primary" type="submit">{{ __('smart_ordering.guest_service.convert') }}</button>
                                            </form>
                                        @elseif($row['reservation_public_id'])
                                            <div class="foodex-state">{{ __('smart_ordering.guest_service.converted_record') }}</div>
                                        @endif
                                    @else
                                        @if(! in_array($row['status'], ['COMPLETED','CANCELLED','NO_SHOW'], true))
                                            <form method="post" action="{{ route('admin.smart-ordering.reservations.update',[$selectedBranch->public_id,$row['public_id']]) }}" class="so-guest-form-grid">
                                                @csrf
                                                @method('PUT')
                                                <label>{{ __('smart_ordering.guest_service.fields.guest_name') }}<input name="guest_name" required maxlength="160" value="{{ $row['guest_name'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.party_size') }}<input name="party_size" type="number" min="1" max="50" required value="{{ $row['party_size'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.phone') }}<input name="guest_phone" maxlength="60" value="{{ $row['guest_phone'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.email') }}<input name="guest_email" type="email" maxlength="190" value="{{ $row['guest_email'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.service_time') }}<input name="service_at" type="datetime-local" required value="{{ $row['service_input'] }}"></label>
                                                <label>{{ __('smart_ordering.guest_service.fields.table') }}
                                                    <select name="table_public_id">
                                                        <option value="">{{ __('smart_ordering.guest_service.unassigned') }}</option>
                                                        @foreach($guestWorkspace['tables'] as $table)
                                                            <option value="{{ $table['public_id'] }}" @selected($row['table_public_id']===$table['public_id'])>{{ $table['label'] }} · {{ __('smart_ordering.guest_service.seats',['count'=>$table['capacity']]) }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="so-guest-span">{{ __('smart_ordering.guest_service.fields.notes') }}<textarea name="notes" maxlength="1000">{{ $row['notes'] }}</textarea></label>
                                                <div class="so-guest-span"><button class="foodex-primary" type="submit">{{ __('smart_ordering.guest_service.save') }}</button></div>
                                            </form>

                                            <div class="so-guest-status-actions">
                                                @foreach($row['allowed_targets'] as $target)
                                                    <form method="post" action="{{ route('admin.smart-ordering.reservations.transition',[$selectedBranch->public_id,$row['public_id']]) }}" @if(in_array($target,['CANCELLED','NO_SHOW'],true)) onsubmit="return confirm(@js(__('smart_ordering.guest_service.confirm_transition',['status'=>__('smart_ordering.guest_service.statuses.'.$target)])))" @endif>
                                                        @csrf
                                                        <input type="hidden" name="status" value="{{ $target }}">
                                                        <button class="foodex-primary" type="submit">{{ __('smart_ordering.guest_service.statuses.'.$target) }}</button>
                                                    </form>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </dialog>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ $isWaitlist ? 9 : 7 }}"><div class="foodex-empty-state">{{ $isWaitlist ? __('smart_ordering.guest_service.no_waitlist') : __('smart_ordering.guest_service.no_reservations') }}</div></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        @if($guestRecords)
            {{ $guestRecords->links() }}
        @endif

        @if($guestWorkspace['can_manage'])
            @if($isWaitlist)
                <dialog class="so-dialog" id="so-create-waitlist">
                    <div class="so-dialog-body">
                        <div class="so-dialog-head">
                            <h2>{{ __('smart_ordering.guest_service.add_waitlist') }}</h2>
                            <button class="so-close" type="button" aria-label="{{ __('smart_ordering.guest_service.close') }}" onclick="this.closest('dialog').close()">×</button>
                        </div>
                        <form method="post" action="{{ route('admin.smart-ordering.waitlist.store',$selectedBranch->public_id) }}" class="so-guest-form-grid">
                            @csrf
                            <label>{{ __('smart_ordering.guest_service.fields.guest_name') }}<input name="guest_name" required maxlength="160"></label>
                            <label>{{ __('smart_ordering.guest_service.fields.party_size') }}<input name="party_size" type="number" min="1" max="50" required value="2"></label>
                            <label>{{ __('smart_ordering.guest_service.fields.phone') }}<input name="guest_phone" maxlength="60"></label>
                            <label>{{ __('smart_ordering.guest_service.fields.email') }}<input name="guest_email" type="email" maxlength="190"></label>
                            <label>{{ __('smart_ordering.guest_service.fields.quoted_wait') }}<input name="quoted_wait_minutes" type="number" min="0" max="1440" value="15"></label>
                            <label>{{ __('smart_ordering.guest_service.fields.priority') }}<input name="priority" type="number" min="0" max="10" required value="0"></label>
                            <label class="so-guest-span">{{ __('smart_ordering.guest_service.fields.priority_reason') }}<input name="priority_reason" maxlength="255"></label>
                            <label class="so-guest-span">{{ __('smart_ordering.guest_service.fields.notes') }}<textarea name="notes" maxlength="1000"></textarea></label>
                            <div class="so-guest-span"><button class="foodex-primary" type="submit">{{ __('smart_ordering.guest_service.add_waitlist') }}</button></div>
                        </form>
                    </div>
                </dialog>
            @else
                <dialog class="so-dialog" id="so-create-reservation">
                    <div class="so-dialog-body">
                        <div class="so-dialog-head">
                            <h2>{{ __('smart_ordering.guest_service.add_reservation') }}</h2>
                            <button class="so-close" type="button" aria-label="{{ __('smart_ordering.guest_service.close') }}" onclick="this.closest('dialog').close()">×</button>
                        </div>
                        <form method="post" action="{{ route('admin.smart-ordering.reservations.store',$selectedBranch->public_id) }}" class="so-guest-form-grid">
                            @csrf
                            <label>{{ __('smart_ordering.guest_service.fields.guest_name') }}<input name="guest_name" required maxlength="160"></label>
                            <label>{{ __('smart_ordering.guest_service.fields.party_size') }}<input name="party_size" type="number" min="1" max="50" required value="2"></label>
                            <label>{{ __('smart_ordering.guest_service.fields.phone') }}<input name="guest_phone" maxlength="60"></label>
                            <label>{{ __('smart_ordering.guest_service.fields.email') }}<input name="guest_email" type="email" maxlength="190"></label>
                            <label>{{ __('smart_ordering.guest_service.fields.service_time') }}<input name="service_at" type="datetime-local" required></label>
                            <label>{{ __('smart_ordering.guest_service.fields.table') }}
                                <select name="table_public_id">
                                    <option value="">{{ __('smart_ordering.guest_service.unassigned') }}</option>
                                    @foreach($guestWorkspace['tables'] as $table)
                                        <option value="{{ $table['public_id'] }}">{{ $table['label'] }} · {{ __('smart_ordering.guest_service.seats',['count'=>$table['capacity']]) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="so-guest-span">{{ __('smart_ordering.guest_service.fields.notes') }}<textarea name="notes" maxlength="1000"></textarea></label>
                            <div class="so-guest-span"><button class="foodex-primary" type="submit">{{ __('smart_ordering.guest_service.add_reservation') }}</button></div>
                        </form>
                    </div>
                </dialog>
            @endif
        @endif
    @endif
</section>
