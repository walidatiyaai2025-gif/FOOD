@php
$selectedBranch = $guestWorkspace['selected_branch'];
$guestRecords = $guestWorkspace['records'];
@endphp

<section class="so-guest-workspace" data-guest-book-workspace>
    <section class="foodex-card">
        <div class="so-guest-head">
            <div>
                <h2>{{ __('smart_ordering.guest_service.guest_book_title') }}</h2>
                <p>{{ __('smart_ordering.guest_service.guest_book_description') }}</p>
            </div>
        </div>

        <form method="get" class="so-guest-branch-filter">
            <input type="hidden" name="tab" value="guest_book">
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
                        @foreach(['guest','contact','last_seen','reservations','waitlist_visits','customer'] as $column)
                            <th>{{ __('smart_ordering.guest_service.columns.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($guestRecords as $row)
                        <tr>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.guest') }}">
                                <strong>{{ $row['guest_name'] }}</strong>
                            </td>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.contact') }}">
                                <div class="so-guest-person">
                                    @if($row['guest_phone'])<span>{{ $row['guest_phone'] }}</span>@endif
                                    @if($row['guest_email'])<small>{{ $row['guest_email'] }}</small>@endif
                                    @if(! $row['guest_phone'] && ! $row['guest_email'])—@endif
                                </div>
                            </td>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.last_seen') }}">{{ $row['last_seen_display'] }}</td>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.reservations') }}">{{ $row['reservation_count'] }}</td>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.waitlist_visits') }}">{{ $row['waitlist_count'] }}</td>
                            <td data-foodex-label="{{ __('smart_ordering.guest_service.columns.customer') }}">
                                {{ $row['customer_linked'] ? __('smart_ordering.guest_service.customer_linked') : __('smart_ordering.guest_service.restaurant_guest') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="foodex-empty-state">{{ __('smart_ordering.guest_service.no_guest_book') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        @if($guestRecords)
            {{ $guestRecords->links() }}
        @endif
    @endif
</section>
