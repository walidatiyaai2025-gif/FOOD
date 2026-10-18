@php
    $orderFilterStoreId = $selectedStoreId ?? null;
    $orderFilterChannel = $selectedChannel ?? 'b2b';
    $orderFilterStores = $availableStores ?? [];
    $orderFilterReset = [
        'channel' => $orderFilterChannel,
        'action_required' => ($customerServiceProfile ?? false) ? 1 : 0,
    ];
    if ($orderFilterStoreId !== null) {
        $orderFilterReset['store_id'] = $orderFilterStoreId;
    }
@endphp
<style id="order-operations-order-filter-style">
.order-grid-filter-controls{display:flex;align-items:center;justify-content:flex-end;gap:5px;min-width:0;white-space:nowrap}
.order-grid-filter-form{display:flex;align-items:center;gap:5px;min-width:0;padding:3px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface);box-shadow:0 4px 14px rgba(15,23,42,.035)}
.order-grid-filter-form input,.order-grid-filter-form select{height:34px!important;min-height:34px!important;border-radius:8px!important;padding-inline:8px!important;font-size:.66rem!important;background:var(--foodex-surface)!important}
.order-grid-filter-form input[name="order_number"]{width:132px}
.order-grid-filter-form select[name="status"]{width:118px}
.order-grid-filter-form select[name="driver_id"]{width:120px}
.order-grid-filter-form select[name="exception"]{width:118px}
.order-grid-filter-form .foodex-filter-action,.order-grid-filter-form .foodex-action-secondary,.order-grid-new-order{height:34px!important;min-height:34px!important;padding-inline:10px!important;border-radius:8px!important;font-size:.66rem!important;line-height:1!important}
.order-grid-filter-label{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.order-grid-filter-more{position:relative}
.order-grid-filter-more>summary{list-style:none;width:34px;height:34px;display:grid;place-items:center;border:1px solid var(--foodex-border);border-radius:8px;background:var(--foodex-surface);color:var(--foodex-green-dark);cursor:pointer;font-weight:900}
.order-grid-filter-more>summary::-webkit-details-marker{display:none}
.order-grid-filter-popover{position:absolute;z-index:12050;top:calc(100% + 7px);inset-inline-end:0;width:min(90vw,430px);display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;padding:10px;border:1px solid var(--foodex-border);border-radius:13px;background:var(--foodex-surface);box-shadow:0 16px 42px rgba(15,23,42,.16)}
.order-grid-filter-popover label{display:grid;gap:4px;font-size:.68rem;font-weight:800}
.order-grid-filter-popover input,.order-grid-filter-popover select{width:100%}
.order-grid-new-order{white-space:nowrap}
@media(max-width:1350px){
    .order-grid-filter-form select[name="driver_id"],.order-grid-filter-form select[name="exception"]{display:none}
}
@media(max-width:1100px){
    .order-grid-filter-form input[name="order_number"],.order-grid-filter-form select[name="status"]{display:none}
}
@media(max-width:767px){
    .order-grid-filter-controls{gap:4px}
    .order-grid-filter-form{padding:2px}
    .order-grid-filter-popover{position:fixed;top:96px;inset-inline:12px;width:auto}
    .order-grid-new-order{padding-inline:8px!important}
}
</style>

<div class="order-grid-filter-controls" data-order-grid-filter-controls>
    <form class="order-grid-filter-form"
          data-order-grid-filter
          method="get"
          action="{{ route('admin.operations.orders.index') }}#order-tab-orders">
        <input type="hidden" name="channel" value="{{ $orderFilterChannel }}">
        @if(count($orderFilterStores) <= 1 && $orderFilterStoreId !== null)
            <input type="hidden" name="store_id" value="{{ $orderFilterStoreId }}">
        @endif
        <input type="hidden" name="action_required" value="{{ ($selectedActionRequired ?? false) ? 1 : 0 }}">

        <label>
            <span class="order-grid-filter-label">{{ __('order_operations.filters.order_number') }}</span>
            <input type="search"
                   name="order_number"
                   value="{{ request('order_number') }}"
                   placeholder="{{ __('order_operations.filters.order_number') }}"
                   aria-label="{{ __('order_operations.filters.order_number') }}">
        </label>

        <label>
            <span class="order-grid-filter-label">{{ __('order_operations.filters.status') }}</span>
            <select name="status" aria-label="{{ __('order_operations.filters.status') }}">
                <option value="">{{ __('order_operations.all') }}</option>
                @foreach($statusOptions as $status)
                    <option value="{{ $status['code'] }}" @selected(request('status')===$status['code'])>{{ $status['label'] }}</option>
                @endforeach
            </select>
        </label>

        <label>
            <span class="order-grid-filter-label">{{ __('order_operations.filters.driver') }}</span>
            <select name="driver_id" aria-label="{{ __('order_operations.filters.driver') }}">
                <option value="">{{ __('order_operations.all') }}</option>
                @foreach($drivers as $driver)
                    <option value="{{ $driver->id }}" @selected((string)request('driver_id')===(string)$driver->id)>{{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>
                @endforeach
            </select>
        </label>

        <label>
            <span class="order-grid-filter-label">{{ __('order_operations.filters.exception') }}</span>
            <select name="exception" aria-label="{{ __('order_operations.filters.exception') }}">
                <option value="">{{ __('order_operations.all') }}</option>
                @foreach($exceptionOptions as $exceptionOption)
                    <option value="{{ $exceptionOption }}" @selected($selectedException===$exceptionOption)>{{ __('order_operations.dashboard.exceptions.'.$exceptionOption) }}</option>
                @endforeach
            </select>
        </label>

        <button class="foodex-filter-action" type="submit">{{ __('order_operations.filters.apply') }}</button>
        <a class="foodex-action-secondary button secondary"
           href="{{ route('admin.operations.orders.index', $orderFilterReset) }}#order-tab-orders"
           aria-label="{{ __('order_operations.filters.reset') }}"
           title="{{ __('order_operations.filters.reset') }}">×</a>

        <details class="order-grid-filter-more">
            <summary aria-label="{{ $isAr ? 'فلاتر إضافية' : 'More filters' }}" title="{{ $isAr ? 'فلاتر إضافية' : 'More filters' }}">•••</summary>
            <div class="order-grid-filter-popover">
                @if(count($orderFilterStores) > 1)
                    <label>{{ __('order_operations.filters.store') }}
                        <select name="store_id">
                            <option value="">{{ __('order_operations.filters.all_authorized') }}</option>
                            @foreach($orderFilterStores as $storeOption)
                                <option value="{{ $storeOption['id'] }}" @selected((int)$orderFilterStoreId === (int)$storeOption['id'])>{{ $storeOption['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label>{{ __('order_operations.filters.from') }}<input type="date" name="from" value="{{ request('from') }}"></label>
                <label>{{ __('order_operations.filters.to') }}<input type="date" name="to" value="{{ request('to') }}"></label>
                <label>{{ __('order_operations.filters.dispatch_status') }}
                    <select name="dispatch_status">
                        <option value="">{{ __('order_operations.all') }}</option>
                        <option value="awaiting_dispatch" @selected(request('dispatch_status')==='awaiting_dispatch')>{{ __('order_operations.dispatch.awaiting_queue') }}</option>
                        <option value="assigned" @selected(request('dispatch_status')==='assigned')>{{ $businessLabel('assigned') }}</option>
                    </select>
                </label>
            </div>
        </details>
    </form>

    @if(count($newOrderWizard['channels'] ?? []))
        <button type="button" class="foodex-primary order-grid-new-order" data-new-order-open>+ {{ __('order_operations.new_order') }}</button>
    @endif
</div>
