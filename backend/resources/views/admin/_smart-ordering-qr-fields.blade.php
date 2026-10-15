@php($editing = $editingQr ?? null)

@if($editing)
<label>{{ __('smart_ordering_qr.type') }}
    <select name="type" required>
        @foreach($types as $type)
            <option value="{{ $type }}" @selected(($editing?->type??old('type','generic'))===$type)>{{ __('smart_ordering_qr.types.'.$type) }}</option>
        @endforeach
    </select>
</label>
<label>{{ __('smart_ordering_qr.label') }}
    <input name="label" required maxlength="160" value="{{ $editing?->label??old('label') }}">
</label>
<label class="so-qr-span-2">{{ __('smart_ordering_qr.target_path') }}
    <input name="target_path" required maxlength="1024" value="{{ $editing?->target_path??old('target_path','/menu') }}">
    <small class="so-qr-help">{{ __('smart_ordering_qr.target_help') }}</small>
</label>
<label>{{ __('smart_ordering_qr.branch_reference') }}
    <input name="branch_reference" maxlength="120" value="{{ $editing?->branch_reference??old('branch_reference') }}">
</label>
<label>{{ __('smart_ordering_qr.table_reference') }}
    <input name="table_reference" maxlength="120" value="{{ $editing?->table_reference??old('table_reference') }}">
</label>
<label>{{ __('smart_ordering_qr.source') }}
    <input name="source" maxlength="64" value="{{ $editing?->source??old('source','qr') }}">
</label>
<label>{{ __('smart_ordering_qr.medium') }}
    <input name="medium" maxlength="64" value="{{ $editing?->medium??old('medium','offline') }}">
</label>
<label>{{ __('smart_ordering_qr.campaign') }}
    <input name="campaign" maxlength="120" value="{{ $editing?->campaign??old('campaign') }}">
</label>
<label>{{ __('smart_ordering_qr.promotion') }}
    <select name="promotion_id">
        <option value="">{{ __('smart_ordering_qr.no_promotion') }}</option>
        @foreach($promotions as $promotion)
            <option value="{{ $promotion->id }}" @selected((string)($editing?->promotion_id??old('promotion_id',''))===(string)$promotion->id)>
                {{ $promotion->name }}@if($promotion->store_name) · {{ $promotion->store_name }}@endif
            </option>
        @endforeach
    </select>
    <small class="so-qr-help">{{ __('smart_ordering_qr.promotion_help') }}</small>
</label>
<label>{{ __('smart_ordering_qr.valid_from') }}
    <input type="datetime-local" name="valid_from" value="{{ $editing?->valid_from?->format('Y-m-d\TH:i')??old('valid_from') }}">
</label>
<label>{{ __('smart_ordering_qr.valid_until') }}
    <input type="datetime-local" name="valid_until" value="{{ $editing?->valid_until?->format('Y-m-d\TH:i')??old('valid_until') }}">
</label>
<label class="so-qr-span-2">{{ __('smart_ordering_qr.notes') }}
    <textarea name="notes" maxlength="4000">{{ $editing?->notes??old('notes') }}</textarea>
</label>
@else
<div class="so-qr-span-2 so-qr-quick-setup" data-qr-quick-setup>
    <label>{{ __('smart_ordering_qr.quick_setup') }}
        <select data-qr-preset>
            <option value="generic">{{ __('smart_ordering_qr.presets.menu') }}</option>
            <option value="table">{{ __('smart_ordering_qr.presets.table') }}</option>
            <option value="campaign">{{ __('smart_ordering_qr.presets.campaign') }}</option>
        </select>
    </label>
    <input type="hidden" name="type" value="generic" data-qr-type>
    <p class="so-qr-help">{{ __('smart_ordering_qr.quick_setup_help') }}</p>
</div>

<label class="so-qr-span-2">{{ __('smart_ordering_qr.label') }}
    <input name="label" required maxlength="160" value="{{ old('label') }}" placeholder="{{ __('smart_ordering_qr.label_placeholder') }}">
</label>

<div class="so-qr-span-2 so-qr-context-box" data-qr-table-fields hidden>
    <label>{{ __('smart_ordering_qr.table_reference') }}
        <input name="table_reference" maxlength="120" value="{{ old('table_reference') }}" data-qr-table-input>
    </label>
</div>

<div class="so-qr-span-2 so-qr-context-box" data-qr-campaign-fields hidden>
    <label>{{ __('smart_ordering_qr.campaign') }}
        <input name="campaign" maxlength="120" value="{{ old('campaign') }}" data-qr-campaign-input>
    </label>
    <label>{{ __('smart_ordering_qr.promotion') }}
        <select name="promotion_id">
            <option value="">{{ __('smart_ordering_qr.no_promotion') }}</option>
            @foreach($promotions as $promotion)
                <option value="{{ $promotion->id }}" @selected((string)old('promotion_id','')===(string)$promotion->id)>
                    {{ $promotion->name }}@if($promotion->store_name) · {{ $promotion->store_name }}@endif
                </option>
            @endforeach
        </select>
        <small class="so-qr-help">{{ __('smart_ordering_qr.promotion_help') }}</small>
    </label>
</div>

<div class="so-qr-span-2 so-qr-create-summary" data-qr-create-summary>{{ __('smart_ordering_qr.summary_menu') }}</div>

<details class="so-qr-span-2 so-qr-advanced">
    <summary>{{ __('smart_ordering_qr.advanced') }}</summary>
    <p class="so-qr-help">{{ __('smart_ordering_qr.advanced_help') }}</p>
    <div class="so-qr-form-grid">
        <label>{{ __('smart_ordering_qr.advanced_type') }}
            <select data-qr-advanced-type>
                @foreach($types as $type)
                    <option value="{{ $type }}" @selected($type==='generic')>{{ __('smart_ordering_qr.types.'.$type) }}</option>
                @endforeach
            </select>
        </label>
        <label>{{ __('smart_ordering_qr.branch_reference') }}
            <input name="branch_reference" maxlength="120" value="{{ old('branch_reference') }}">
        </label>
        <label class="so-qr-span-2">{{ __('smart_ordering_qr.target_path') }}
            <input name="target_path" required maxlength="1024" value="{{ old('target_path','/menu') }}">
            <small class="so-qr-help">{{ __('smart_ordering_qr.target_help') }}</small>
        </label>
        <label>{{ __('smart_ordering_qr.source') }}
            <input name="source" maxlength="64" value="{{ old('source','qr') }}">
        </label>
        <label>{{ __('smart_ordering_qr.medium') }}
            <input name="medium" maxlength="64" value="{{ old('medium','offline') }}">
        </label>
        <label>{{ __('smart_ordering_qr.valid_from') }}
            <input type="datetime-local" name="valid_from" value="{{ old('valid_from') }}">
        </label>
        <label>{{ __('smart_ordering_qr.valid_until') }}
            <input type="datetime-local" name="valid_until" value="{{ old('valid_until') }}">
        </label>
        <label class="so-qr-span-2">{{ __('smart_ordering_qr.notes') }}
            <textarea name="notes" maxlength="4000">{{ old('notes') }}</textarea>
        </label>
    </div>
</details>
@endif
