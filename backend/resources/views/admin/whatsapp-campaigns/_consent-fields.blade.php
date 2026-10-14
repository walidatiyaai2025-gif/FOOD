<div class="wa-grid" style="margin:12px 0">
    <label>{{ __('whatsapp.campaigns.consent_state') }}
        <select id="wa-{{ $prefix }}-consent-state">
            <option value="">{{ __('whatsapp.campaigns.consent_do_not_record') }}</option>
            <option value="granted">{{ __('whatsapp.campaigns.consent_granted') }}</option>
            <option value="denied">{{ __('whatsapp.campaigns.consent_denied') }}</option>
            <option value="opted_out">{{ __('whatsapp.campaigns.consent_opted_out') }}</option>
        </select>
    </label>
    <label>{{ __('whatsapp.campaigns.consent_source') }}<input id="wa-{{ $prefix }}-consent-source" maxlength="64" placeholder="admin_verified"></label>
    <label style="grid-column:span 2">{{ __('whatsapp.campaigns.consent_evidence') }}<input id="wa-{{ $prefix }}-consent-evidence" maxlength="1000" placeholder="{{ __('whatsapp.campaigns.consent_evidence_hint') }}"></label>
</div>
