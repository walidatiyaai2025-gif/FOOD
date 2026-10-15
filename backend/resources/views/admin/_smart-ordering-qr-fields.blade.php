@php($editing = isset($editingQr) && $editingQr)
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
