<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ __('whatsapp.campaigns.page_title') }}</title>
@include('admin._brand-components')
<style>
.wa-campaign-shell{display:grid;gap:14px;min-width:0}.wa-campaign-card{padding:0;overflow:hidden}
.wa-campaign-card>header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--foodex-border)}
.wa-campaign-card-body{padding:16px}.wa-builder{display:none}.wa-builder.show{display:block}
.wa-steps{display:grid;gap:14px}.wa-step{border:1px solid var(--foodex-border);border-radius:14px;padding:14px}.wa-step h3{margin:0 0 10px}
.wa-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.wa-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.wa-grid label{display:grid;gap:5px}
.wa-source-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.wa-source-panel{display:none}.wa-source-panel.active{display:block}
.wa-search-results{display:grid;gap:7px;max-height:280px;overflow:auto;border:1px solid var(--foodex-border);border-radius:12px;padding:8px;margin-top:10px}.wa-customer-row{display:flex;gap:10px;align-items:center;padding:8px;border-radius:10px;background:var(--foodex-background)}.wa-customer-row small{color:var(--foodex-muted)}
.wa-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.wa-kpi{padding:12px;border:1px solid var(--foodex-border);border-radius:12px}.wa-kpi strong{display:block;font-size:22px}
.wa-preview-table,.wa-campaign-table{width:100%;border-collapse:collapse;min-width:900px}.wa-preview-table th,.wa-preview-table td,.wa-campaign-table th,.wa-campaign-table td{padding:10px 12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:middle;white-space:nowrap}
.wa-campaign-table-wrap{overflow:auto}.wa-actions{display:flex;gap:6px;flex-wrap:wrap}.wa-actions form{display:inline}.wa-muted{color:var(--foodex-muted);font-size:12px}.wa-status{padding:12px 16px}.wa-badge{display:inline-flex;padding:4px 8px;border-radius:999px;background:var(--foodex-background);font-size:11px;font-weight:800}.wa-badge.good{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.wa-badge.warn{background:var(--foodex-orange-soft);color:var(--foodex-orange)}
.wa-import-preview{display:none;margin-top:12px}.wa-import-preview.show{display:block}.wa-inline{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.wa-inline label{display:grid;gap:4px}.wa-hide{display:none!important}
.wa-template-dialog{border:0;border-radius:16px;padding:0;width:min(680px,calc(100% - 28px));box-shadow:0 24px 80px #0003}.wa-template-dialog::backdrop{background:#0007}.wa-template-dialog-card{padding:20px;display:grid;gap:16px}.wa-template-dialog-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.wa-template-dialog-head h2{margin:0}.wa-template-dialog form{display:grid;gap:14px}.wa-template-dialog textarea{min-height:150px;resize:vertical}.wa-template-dialog input,.wa-template-dialog select,.wa-template-dialog textarea{font-size:16px}
@media(max-width:1050px){.wa-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wa-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.wa-grid,.wa-grid.two,.wa-kpis{grid-template-columns:1fr}.wa-campaign-card>header{align-items:flex-start;flex-direction:column}.wa-template-dialog{width:100%;max-width:none;max-height:94dvh;margin:auto 0 0;border-radius:18px 18px 0 0}.wa-template-dialog-card{padding:16px}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
        <header class="foodex-page-toolbar">
            <div>
                <span class="foodex-subtitle">{{ __('whatsapp.campaigns.nav_title') }}</span>
                <h1>{{ __('whatsapp.campaigns.heading') }}</h1>
                <p>{{ __('whatsapp.campaigns.description') }}</p>
            </div>
            <div class="wa-actions">
                @if($canManage)<button type="button" class="foodex-action-primary" id="wa-create-campaign">＋ {{ __('whatsapp.campaigns.create_campaign') }}</button>@endif
                
            </div>
        </header>

        <div class="wa-campaign-shell">
            @if(session('status'))<div class="foodex-card wa-status" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="foodex-card wa-status" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

            @include('admin.whatsapp-campaigns._operations-overview', ['overview' => $operationsOverview])

            @if($canManage)
            <section class="foodex-card wa-campaign-card wa-builder" id="wa-campaign-builder">
                <header>
                    <div><strong>{{ __('whatsapp.campaigns.builder_title') }}</strong><div class="wa-muted">{{ __('whatsapp.campaigns.builder_hint') }}</div></div>
                    <button type="button" class="foodex-action-secondary" id="wa-close-builder">{{ __('whatsapp.campaigns.close') }}</button>
                </header>
                <div class="wa-campaign-card-body">
                    <form method="post" action="{{ route('admin.whatsapp-campaigns.store') }}" id="wa-campaign-form">
                        @csrf
                        <div class="wa-steps">
                            <section class="wa-step">
                                <h3>1. {{ __('whatsapp.campaigns.step_details') }}</h3>
                                <div class="wa-grid">
                                    <label>{{ __('whatsapp.campaigns.name') }}<input name="name" required maxlength="160"></label>
                                    <label>{{ __('whatsapp.campaigns.template') }}
                                        <select name="template_id" required>
                                            <option value="">{{ __('whatsapp.campaigns.choose_template') }}</option>
                                            @foreach($templates as $template)
                                                <option value="{{ $template->id }}" data-status="{{ strtolower((string)$template->provider_status) }}" data-locale="{{ strtolower((string)$template->locale) }}" data-category="{{ strtolower((string)$template->category) }}" data-body="{{ $template->body_preview }}">{{ $template->name }} · {{ __('whatsapp.campaigns.locales.'.strtolower((string)$template->locale)) }} · {{ __('whatsapp.campaigns.template_statuses.'.strtolower((string)$template->provider_status)) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>{{ __('whatsapp.campaigns.mode') }}
                                        <span><input type="hidden" name="test_mode" value="0"><input type="checkbox" name="test_mode" value="1" checked> {{ __('whatsapp.campaigns.test_mode') }}</span>
                                    </label>
                                    <label>{{ __('whatsapp.campaigns.test_allowlist') }}<textarea name="test_allowlist" rows="2" dir="ltr" placeholder="+965..."></textarea></label>
                                </div>
                                <div class="wa-muted" id="wa-template-preview"></div>
                            </section>

                            <section class="wa-step">
                                <h3>2. {{ __('whatsapp.campaigns.step_audience') }}</h3>
                                <input type="hidden" name="audience_source" id="wa-audience-source" value="existing">
                                <input type="hidden" name="import_batch_id" id="wa-import-batch">
                                <input type="hidden" name="search" id="wa-segment-search">
                                <div id="wa-selected-customer-inputs"></div>
                                <div id="wa-selected-store-inputs"></div>
                                <div id="wa-selected-channel-inputs"></div>
                                <div class="wa-source-tabs">
                                    <button type="button" class="foodex-action-primary wa-source-tab" data-source="existing">{{ __('whatsapp.campaigns.source_existing') }}</button>
                                    <button type="button" class="foodex-action-secondary wa-source-tab" data-source="import">{{ __('whatsapp.campaigns.source_import') }}</button>
                                    <button type="button" class="foodex-action-secondary wa-source-tab" data-source="manual">{{ __('whatsapp.campaigns.source_manual') }}</button>
                                </div>

                                <div class="wa-source-panel active" data-panel="existing">
                                    <div class="wa-grid">
                                        <label>{{ __('whatsapp.campaigns.customer_search') }}<input id="wa-customer-search" placeholder="{{ __('whatsapp.campaigns.customer_search_hint') }}"></label>
                                        <label>{{ __('whatsapp.inbox.store') }}
                                            <select id="wa-existing-store">
                                                <option value="">{{ __('whatsapp.campaigns.all_authorized') }}</option>
                                                @foreach($stores as $store)<option value="{{ $store->id }}" data-channel="{{ $store->channel }}">{{ $store->name }} · {{ __('whatsapp.inbox.channels.'.strtolower((string)$store->channel)) }}</option>@endforeach
                                            </select>
                                        </label>
                                        <label>{{ __('whatsapp.inbox.channel') }}
                                            <select id="wa-existing-channel"><option value="">{{ __('whatsapp.campaigns.all') }}</option><option value="b2b">{{ __('whatsapp.inbox.channels.b2b') }}</option><option value="b2c">{{ __('whatsapp.inbox.channels.b2c') }}</option></select>
                                        </label>
                                        <div class="wa-inline"><button type="button" class="foodex-action-secondary" id="wa-search-customers">{{ __('whatsapp.campaigns.search_customers') }}</button><button type="button" class="foodex-action-secondary" id="wa-select-results">{{ __('whatsapp.campaigns.select_all_matching') }}</button></div>
                                    </div>
                                    <div class="wa-search-results" id="wa-customer-results"><div class="wa-muted">{{ __('whatsapp.campaigns.search_to_select') }}</div></div>
                                    <details style="margin-top:12px">
                                        <summary>{{ __('whatsapp.campaigns.advanced_filters') }}</summary>
                                        <div class="wa-grid" style="margin-top:10px">
                                            <label>{{ __('whatsapp.campaigns.min_value') }}<input type="number" step="0.001" min="0" name="min_value"></label>
                                            <label>{{ __('whatsapp.campaigns.max_value') }}<input type="number" step="0.001" min="0" name="max_value"></label>
                                            <label>{{ __('whatsapp.campaigns.recency_days') }}<input type="number" min="0" max="3650" name="recency_days"></label>
                                            <label>{{ __('whatsapp.campaigns.tags') }}<select name="tag_ids[]" multiple>@foreach($tags as $tag)<option value="{{ $tag->id }}">{{ $tag->name }}</option>@endforeach</select></label>
                                            <label>{{ __('whatsapp.campaigns.agents') }}<select name="agent_ids[]" multiple>@foreach($agents as $agent)<option value="{{ $agent->id }}">{{ $agent->name }}</option>@endforeach</select></label>
                                            <label>{{ __('whatsapp.campaigns.categories') }}<select name="category_ids[]" multiple>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                                        </div>
                                    </details>
                                </div>

                                <div class="wa-source-panel" data-panel="import">
                                    <div class="wa-grid">
                                        <label>{{ __('whatsapp.campaigns.import_store') }}<select id="wa-import-store" required><option value="">{{ __('whatsapp.campaigns.choose_store') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }} · {{ strtoupper($store->channel) }}</option>@endforeach</select></label>
                                        <label>{{ __('whatsapp.campaigns.import_file') }}<input type="file" id="wa-import-file" accept=".csv,.xlsx"></label>
                                        <div class="wa-inline"><button type="button" class="foodex-action-secondary" id="wa-import-preview-btn">{{ __('whatsapp.campaigns.preview_import') }}</button></div>
                                    </div>
                                    <div class="wa-import-preview" id="wa-import-preview">
                                        <div class="wa-grid">
                                            <label>{{ __('whatsapp.campaigns.map_name') }}<select id="wa-map-name"></select></label>
                                            <label>{{ __('whatsapp.campaigns.map_phone') }}<select id="wa-map-phone"></select></label>
                                            <label>{{ __('whatsapp.campaigns.map_email') }}<select id="wa-map-email"><option value="">{{ __('whatsapp.campaigns.none') }}</option></select></label>
                                            <label>{{ __('whatsapp.campaigns.map_consent_state') }}<select id="wa-map-consent-state"><option value="">{{ __('whatsapp.campaigns.none') }}</option></select></label>
                                            <label>{{ __('whatsapp.campaigns.map_consent_source') }}<select id="wa-map-consent-source"><option value="">{{ __('whatsapp.campaigns.none') }}</option></select></label>
                                            <label>{{ __('whatsapp.campaigns.map_consent_evidence') }}<select id="wa-map-consent-evidence"><option value="">{{ __('whatsapp.campaigns.none') }}</option></select></label>
                                            <label>{{ __('whatsapp.campaigns.map_consent_date') }}<select id="wa-map-consent-date"><option value="">{{ __('whatsapp.campaigns.none') }}</option></select></label>
                                        </div>
                                        <div id="wa-import-sample" class="wa-campaign-table-wrap"></div>
                                        @include('admin.whatsapp-campaigns._consent-fields', ['prefix' => 'import'])
                                        <button type="button" class="foodex-action-primary" id="wa-import-commit">{{ __('whatsapp.campaigns.import_contacts') }}</button>
                                        <div class="wa-muted" id="wa-import-status"></div>
                                        <div class="wa-muted" id="wa-import-errors" role="status"></div>
                                    </div>
                                </div>

                                <div class="wa-source-panel" data-panel="manual">
                                    <div class="wa-grid two">
                                        <label>{{ __('whatsapp.campaigns.manual_store') }}<select id="wa-manual-store"><option value="">{{ __('whatsapp.campaigns.choose_store') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }} · {{ strtoupper($store->channel) }}</option>@endforeach</select></label>
                                        <label>{{ __('whatsapp.campaigns.manual_contacts') }}<textarea id="wa-manual-lines" rows="6" placeholder="{{ __('whatsapp.campaigns.manual_format') }}"></textarea></label>
                                    </div>
                                    @include('admin.whatsapp-campaigns._consent-fields', ['prefix' => 'manual'])
                                    <button type="button" class="foodex-action-primary" id="wa-manual-add">{{ __('whatsapp.campaigns.add_contacts') }}</button>
                                    <div class="wa-muted" id="wa-manual-status"></div>
                                </div>

                                <div class="wa-muted" style="margin-top:10px"><strong id="wa-selected-count">0</strong> {{ __('whatsapp.campaigns.customers_selected') }}</div>
                            </section>

                            <section class="wa-step">
                                <h3>3. {{ __('whatsapp.campaigns.step_preview') }}</h3>
                                <button type="button" class="foodex-action-secondary" id="wa-preview-audience">{{ __('whatsapp.campaigns.preview_audience') }}</button>
                                <div id="wa-preview-box" class="wa-hide" style="margin-top:12px">
                                    <div class="wa-kpis">
                                        <div class="wa-kpi"><span>{{ __('whatsapp.campaigns.matched') }}</span><strong id="wa-preview-total">0</strong></div>
                                        <div class="wa-kpi"><span>{{ __('whatsapp.campaigns.eligible') }}</span><strong id="wa-preview-eligible">0</strong></div>
                                        <div class="wa-kpi"><span>{{ __('whatsapp.campaigns.suppressed') }}</span><strong id="wa-preview-suppressed">0</strong></div>
                                        <div class="wa-kpi"><span>{{ __('whatsapp.campaigns.suppression_reasons') }}</span><strong id="wa-preview-reasons">—</strong></div>
                                        <div class="wa-kpi"><span>{{ __('whatsapp.campaigns.first_contact') }}</span><strong id="wa-preview-first-contact">0</strong></div>
                                        <div class="wa-kpi"><span>{{ __('whatsapp.campaigns.existing_contact') }}</span><strong id="wa-preview-existing-contact">0</strong></div>
                                        <div class="wa-kpi"><span>{{ __('whatsapp.campaigns.estimated_batches') }}</span><strong id="wa-preview-batches">0</strong></div>
                                    </div>
                                    <div class="wa-campaign-table-wrap" id="wa-preview-sample"></div>
                                </div>
                            </section>

                            <section class="wa-step">
                                <h3>4. {{ __('whatsapp.campaigns.step_settings') }}</h3>
                                <div class="wa-grid">
                                    <label>{{ __('whatsapp.campaigns.batch_size') }}<input type="number" name="batch_size" min="1" max="1000" value="50"></label>
                                    <label>{{ __('whatsapp.campaigns.per_minute') }}<input type="number" name="per_minute_limit" min="1" max="5000" value="100"></label>
                                    <label>{{ __('whatsapp.campaigns.max_recipients') }}<input type="number" name="max_recipients" min="1" max="100000" value="1000"></label>
                                    <label>{{ __('whatsapp.campaigns.schedule_at') }}<input type="datetime-local" name="scheduled_at"></label>
                                </div>
                            </section>

                            <section class="wa-step">
                                <h3>5. {{ __('whatsapp.campaigns.step_review') }}</h3>
                                <div class="foodex-state" id="wa-review-summary" role="status" style="margin-bottom:12px">
                                    <strong>{{ __('whatsapp.campaigns.review_summary') }}</strong>
                                    <div class="wa-muted" id="wa-review-summary-text">{{ __('whatsapp.campaigns.preview_required_live') }}</div>
                                </div>
                                <div class="wa-inline">
                                    <label>{{ __('whatsapp.campaigns.action') }}
                                        <select name="launch_action">
                                            <option value="draft">{{ __('whatsapp.campaigns.save_draft') }}</option>
                                            @if($canSend)<option value="send_now">{{ __('whatsapp.campaigns.send_now') }}</option><option value="schedule">{{ __('whatsapp.campaigns.schedule') }}</option>@endif
                                        </select>
                                    </label>
                                    <button class="foodex-action-primary" type="submit">{{ __('whatsapp.campaigns.create_campaign') }}</button>
                                </div>
                                <p class="wa-muted">{{ __('whatsapp.campaigns.consent_warning') }}</p>
                            </section>
                        </div>
                    </form>
                </div>
            </section>
            @endif

            <section class="foodex-card wa-campaign-card" id="wa-campaign-list">
                <header><strong>{{ __('whatsapp.campaigns.campaigns') }}</strong></header>
                <div class="wa-campaign-table-wrap">
                    <table class="wa-campaign-table" data-pagination-exempt="bounded-100">
                        <thead><tr>
                            <th>{{ __('whatsapp.campaigns.campaign') }}</th><th>{{ __('whatsapp.campaigns.template') }}</th><th>{{ __('whatsapp.campaigns.status') }}</th><th>{{ __('whatsapp.campaigns.mode') }}</th>
                            <th>{{ __('whatsapp.campaigns.eligible') }}</th><th>{{ __('whatsapp.campaigns.sent') }}</th><th>{{ __('whatsapp.campaigns.delivered') }}</th><th>{{ __('whatsapp.campaigns.read') }}</th><th>{{ __('whatsapp.campaigns.failed') }}</th><th>{{ __('whatsapp.campaigns.replies') }}</th><th>{{ __('whatsapp.campaigns.actions') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse($campaigns as $campaign)
                            <tr>
                                <td><strong>{{ $campaign->name }}</strong><div class="wa-muted">{{ __('whatsapp.campaigns.audience_sources.'.strtolower((string)($campaign->audience_source ?? 'existing'))) }}</div></td>
                                <td>{{ $campaign->template_name }} <span class="wa-badge {{ strtolower((string)$campaign->template_status)==='approved'?'good':'warn' }}">{{ __('whatsapp.campaigns.template_statuses.'.strtolower((string)$campaign->template_status)) }}</span></td>
                                <td>{{ __('whatsapp.campaigns.statuses.'.strtolower((string)$campaign->status)) }}</td>
                                <td>{{ $campaign->test_mode ? __('whatsapp.campaigns.test_mode') : __('whatsapp.campaigns.live_mode') }}</td>
                                <td>{{ $campaign->metrics['eligible'] }}</td><td>{{ $campaign->metrics['sent'] }}</td><td>{{ $campaign->metrics['delivered'] }}</td><td>{{ $campaign->metrics['read'] }}</td><td>{{ $campaign->metrics['failed'] }}</td><td>{{ $campaign->metrics['replied'] }}</td>
                                @php
                                    $campaignLifecycle = (string) $campaign->status;
                                    $campaignCanPrepare = in_array($campaignLifecycle, ['draft', 'paused'], true);
                                    $campaignCanPause = in_array($campaignLifecycle, ['scheduled', 'running'], true);
                                    $campaignCanCancel = ! in_array($campaignLifecycle, ['completed', 'cancelled'], true);
                                @endphp
                                <td><div class="wa-actions">
                                    <a class="foodex-action-secondary" href="{{ route('admin.whatsapp-campaigns.report',['campaign'=>$campaign->id]) }}">{{ __('whatsapp.campaigns.reports') }}</a>
                                    @if($canManage && $campaignCanPrepare)<form method="post" action="{{ route('admin.whatsapp-campaigns.snapshot',['campaign'=>$campaign->id]) }}">@csrf<button class="foodex-action-secondary" type="submit">{{ __('whatsapp.campaigns.rebuild_snapshot') }}</button></form>@endif
                                    @if($canSend && $campaignCanPrepare)<form method="post" action="{{ route('admin.whatsapp-campaigns.schedule',['campaign'=>$campaign->id]) }}">@csrf<button class="foodex-action-primary" type="submit">{{ __('whatsapp.campaigns.send_now') }}</button></form>@endif
                                    @if($canSend && $campaignCanPause)<form method="post" action="{{ route('admin.whatsapp-campaigns.pause',['campaign'=>$campaign->id]) }}">@csrf<button class="foodex-action-secondary" type="submit">{{ __('whatsapp.campaigns.pause') }}</button></form>@endif
                                    @if($canSend && $campaignCanCancel)<form method="post" action="{{ route('admin.whatsapp-campaigns.cancel',['campaign'=>$campaign->id]) }}">@csrf<button class="foodex-action-secondary" type="submit">{{ __('whatsapp.campaigns.cancel') }}</button></form>@endif
                                </div></td>
                            </tr>
                        @empty<tr><td colspan="11" class="wa-campaign-empty">{{ __('whatsapp.campaigns.no_campaigns') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="foodex-card wa-campaign-card">
                <header>
                    <strong>{{ __('whatsapp.campaigns.template_registry') }}</strong>
                    @if($canManage)
                    <div class="wa-actions">
                        <button type="button" class="foodex-action-primary" id="wa-create-template">＋ {{ __('whatsapp.campaigns.create_meta_template') }}</button>
                        <form method="post" action="{{ route('admin.whatsapp-campaigns.templates.meta.sync') }}" id="wa-template-sync-form">@csrf<button type="submit" class="foodex-action-secondary" id="wa-template-sync-submit">{{ __('whatsapp.campaigns.sync_meta_templates') }}</button></form>
                    </div>
                    @endif
                </header>
                <div class="wa-campaign-table-wrap">
                    <table class="wa-campaign-table" data-pagination-exempt="bounded-100">
                        <thead><tr><th>{{ __('whatsapp.campaigns.name') }}</th><th>{{ __('whatsapp.campaigns.provider') }}</th><th>{{ __('whatsapp.campaigns.locale') }}</th><th>{{ __('whatsapp.campaigns.status') }}</th><th>{{ __('whatsapp.campaigns.active') }}</th></tr></thead>
                        <tbody>
                        @forelse($templates as $template)<tr><td>{{ $template->name }}</td><td><code>{{ $template->provider_name }}</code></td><td>{{ strtoupper((string)$template->locale) }}</td><td>{{ __('whatsapp.campaigns.template_statuses.'.strtolower((string)$template->provider_status)) }}</td><td>{{ $template->is_active?__('whatsapp.campaigns.yes'):__('whatsapp.campaigns.no') }}</td></tr>
                        @empty<tr><td colspan="5" class="wa-campaign-empty">{{ __('whatsapp.campaigns.no_templates') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>

@if($canManage)
<dialog class="wa-template-dialog" id="wa-template-dialog" aria-labelledby="wa-template-dialog-title">
    <div class="wa-template-dialog-card">
        <div class="wa-template-dialog-head">
            <div>
                <h2 id="wa-template-dialog-title">{{ __('whatsapp.campaigns.meta_template_dialog_title') }}</h2>
                <p class="wa-muted">{{ __('whatsapp.campaigns.meta_template_hint') }}</p>
            </div>
            <button type="button" class="foodex-action-secondary" id="wa-template-close" aria-label="{{ __('whatsapp.campaigns.close') }}">{{ __('whatsapp.campaigns.close') }}</button>
        </div>
        <form method="post" action="{{ route('admin.whatsapp-campaigns.templates.meta.store') }}" id="wa-template-form">
            @csrf
            <div class="wa-grid two">
                <label>{{ __('whatsapp.campaigns.meta_template_display_name') }}<input name="name" maxlength="120" required></label>
                <label>{{ __('whatsapp.campaigns.meta_template_provider_name') }}<input id="wa-template-provider-name" name="provider_name" maxlength="160" dir="ltr" autocapitalize="none" autocomplete="off" spellcheck="false" required><span class="wa-muted">{{ __('whatsapp.campaigns.meta_template_provider_name_hint') }}</span></label>
                <label>{{ __('whatsapp.campaigns.locale') }}
                    <select name="locale" required>
                        <option value="ar">{{ __('whatsapp.campaigns.locales.ar') }}</option>
                        <option value="en">{{ __('whatsapp.campaigns.locales.en') }}</option>
                    </select>
                </label>
                <label>{{ __('whatsapp.campaigns.template_category') }}
                    <select name="category" required>
                        <option value="marketing">{{ __('whatsapp.campaigns.template_categories.marketing') }}</option>
                        <option value="utility">{{ __('whatsapp.campaigns.template_categories.utility') }}</option>
                    </select>
                </label>
            </div>
            <label>{{ __('whatsapp.campaigns.meta_template_body') }}<textarea name="body_preview" maxlength="1024" required></textarea></label>
            <div class="wa-actions">
                <button type="submit" class="foodex-action-primary" id="wa-template-submit">{{ __('whatsapp.campaigns.create_on_whatsapp') }}</button>
                <button type="button" class="foodex-action-secondary" id="wa-template-cancel">{{ __('whatsapp.campaigns.close') }}</button>
            </div>
        </form>
    </div>
</dialog>

<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const builder = document.getElementById('wa-campaign-builder');
    const form = document.getElementById('wa-campaign-form');
    const templateDialog = document.getElementById('wa-template-dialog');
    const templateForm = document.getElementById('wa-template-form');
    const templateProviderName = document.getElementById('wa-template-provider-name');
    const templateSyncForm = document.getElementById('wa-template-sync-form');
    const templateSyncSubmit = document.getElementById('wa-template-sync-submit');
    const selectedIds = new Set();
    const selectedStores = new Set();
    let importBatch = null;
    let lastPreview = null;

    const urls = {
        customers: @json(route('admin.whatsapp-campaigns.customers')),
        preview: @json(route('admin.whatsapp-campaigns.preview')),
        importPreview: @json(route('admin.whatsapp-campaigns.audience.import.preview')),
        importCommitTemplate: @json(route('admin.whatsapp-campaigns.audience.import.commit',['batch'=>0])),
        manual: @json(route('admin.whatsapp-campaigns.audience.manual')),
    };
    const t = {
        loading: @json(__('whatsapp.campaigns.loading')),
        error: @json(__('whatsapp.campaigns.error')),
        eligible: @json(__('whatsapp.campaigns.eligible')),
        suppressed: @json(__('whatsapp.campaigns.suppressed')),
        noResults: @json(__('whatsapp.campaigns.no_customers')),
        rows: @json(__('whatsapp.campaigns.rows')),
        created: @json(__('whatsapp.campaigns.created_count')),
        matched: @json(__('whatsapp.campaigns.matched_count')),
        updated: @json(__('whatsapp.campaigns.updated_count')),
        skipped: @json(__('whatsapp.campaigns.skipped_count')),
        invalid: @json(__('whatsapp.campaigns.invalid_count')),
        sampleName: @json(__('whatsapp.campaigns.sample_name')),
        samplePhone: @json(__('whatsapp.campaigns.sample_phone')),
        sampleStatus: @json(__('whatsapp.campaigns.sample_status')),
        sampleReason: @json(__('whatsapp.campaigns.sample_reason')),
        contactType: @json(__('whatsapp.campaigns.contact_type')),
        firstContact: @json(__('whatsapp.campaigns.contact_type_values.first_contact')),
        existingContact: @json(__('whatsapp.campaigns.contact_type_values.existing_contact')),
        reviewTemplate: @json(__('whatsapp.campaigns.review_template')),
        templateApproved: @json(__('whatsapp.campaigns.review_template_approved')),
        templateBlocked: @json(__('whatsapp.campaigns.review_template_blocked')),
        previewRequiredLive: @json(__('whatsapp.campaigns.preview_required_live')),
        suppressionReasons: @json(__('whatsapp.campaigns.suppression_reason_values')),
        localeLabel: @json(__('whatsapp.campaigns.locale')),
        categoryLabel: @json(__('whatsapp.campaigns.template_category')),
        row: @json(__('whatsapp.campaigns.row')),
        allMatching: @json(__('whatsapp.campaigns.all_matching_selected')),
        importErrorReasons: {
            invalid_name_phone_or_email: @json(__('whatsapp.campaigns.import_errors.invalid_name_phone_or_email')),
            duplicate_in_file: @json(__('whatsapp.campaigns.import_errors.duplicate_in_file')),
            duplicate_outside_scope: @json(__('whatsapp.campaigns.import_errors.duplicate_outside_scope')),
            invalid_consent_state: @json(__('whatsapp.campaigns.import_errors.invalid_consent_state')),
            consent_evidence_required: @json(__('whatsapp.campaigns.import_errors.consent_evidence_required')),
        },
    };

    const json = async (url, options = {}) => {
        const headers = new Headers(options.headers || {});
        headers.set('Accept','application/json'); headers.set('X-CSRF-TOKEN',csrf);
        if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type','application/json');
        const response = await fetch(url,{...options,headers,credentials:'same-origin'});
        const payload = await response.json().catch(()=>({}));
        if (!response.ok) {
            const first = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
            throw new Error(first || payload.message || t.error);
        }
        return payload;
    };

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const syncSelection = () => {
        document.getElementById('wa-selected-customer-inputs').innerHTML = [...selectedIds].map(id=>'<input type="hidden" name="customer_ids[]" value="'+Number(id)+'">').join('');
        document.getElementById('wa-selected-store-inputs').innerHTML = [...selectedStores].map(id=>'<input type="hidden" name="store_ids[]" value="'+Number(id)+'">').join('');
        const channel=document.getElementById('wa-existing-channel')?.value||'';
        document.getElementById('wa-selected-channel-inputs').innerHTML = channel ? '<input type="hidden" name="channels[]" value="'+escapeHtml(channel)+'">' : '';
        const matchAll=document.getElementById('wa-segment-search').value!=='';
        document.getElementById('wa-selected-count').textContent = matchAll ? t.allMatching : String(selectedIds.size);
    };
    const addIds = ids => { ids.forEach(id=>selectedIds.add(Number(id))); lastPreview=null; syncSelection(); };

    document.getElementById('wa-create-campaign')?.addEventListener('click',()=>{builder.classList.add('show');builder.scrollIntoView({behavior:'smooth',block:'start'});});
    document.getElementById('wa-close-builder')?.addEventListener('click',()=>builder.classList.remove('show'));
    const canonicalTemplateName = value => String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_')
        .replace(/[^a-z0-9_]/g, '')
        .replace(/_+/g, '_')
        .replace(/^_+|_+$/g, '');

    const normalizeTemplateNameField = () => {
        if (!templateProviderName) return '';
        const normalized = canonicalTemplateName(templateProviderName.value);
        if (templateProviderName.value !== normalized) templateProviderName.value = normalized;
        templateProviderName.setCustomValidity(normalized ? '' : @json(__('whatsapp.campaigns.meta_template_name_invalid')));
        return normalized;
    };

    templateProviderName?.addEventListener('input', normalizeTemplateNameField);
    templateProviderName?.addEventListener('blur', normalizeTemplateNameField);

    document.getElementById('wa-create-template')?.addEventListener('click',()=>templateDialog?.showModal());
    document.getElementById('wa-template-close')?.addEventListener('click',()=>templateDialog?.close());
    document.getElementById('wa-template-cancel')?.addEventListener('click',()=>templateDialog?.close());
    templateForm?.addEventListener('submit', event=>{
        const normalized = normalizeTemplateNameField();
        if (!normalized) {
            event.preventDefault();
            templateProviderName?.reportValidity();
            return;
        }
        const button=document.getElementById('wa-template-submit');
        if(button){button.disabled=true;button.setAttribute('aria-busy','true');}
    });

    templateSyncForm?.addEventListener('submit', () => {
        if (!templateSyncSubmit) return;
        templateSyncSubmit.disabled = true;
        templateSyncSubmit.setAttribute('aria-busy', 'true');
    });

    document.querySelectorAll('.wa-source-tab').forEach(button=>button.addEventListener('click',()=>{
        const source=button.dataset.source;
        lastPreview=null;
        document.getElementById('wa-audience-source').value=source;
        document.querySelectorAll('.wa-source-tab').forEach(item=>{item.classList.toggle('foodex-action-primary',item===button);item.classList.toggle('foodex-action-secondary',item!==button);});
        document.querySelectorAll('.wa-source-panel').forEach(panel=>panel.classList.toggle('active',panel.dataset.panel===source));
    }));

    form.template_id?.addEventListener('change',()=>{
        lastPreview=null;
        const option=form.template_id.selectedOptions[0];
        if(!option?.value){document.getElementById('wa-template-preview').textContent='';return;}
        const locale=option.dataset.locale||'';
        const category=option.dataset.category||'';
        const localeMap={
            en:@json(__('whatsapp.campaigns.locales.en')),
            ar:@json(__('whatsapp.campaigns.locales.ar')),
        };
        const categoryMap={
            marketing:@json(__('whatsapp.campaigns.template_categories.marketing')),
            utility:@json(__('whatsapp.campaigns.template_categories.utility')),
            authentication:@json(__('whatsapp.campaigns.template_categories.authentication')),
        };
        document.getElementById('wa-template-preview').textContent=
            t.localeLabel+': '+(localeMap[locale]||locale)+' · '+t.categoryLabel+': '+(categoryMap[category]||category)+' · '+(option.dataset.body||'');
    });

    const searchCustomers = async () => {
        const box=document.getElementById('wa-customer-results'); box.innerHTML='<div class="wa-muted">'+t.loading+'</div>';
        const params=new URLSearchParams();
        const q=document.getElementById('wa-customer-search').value.trim();
        const store=document.getElementById('wa-existing-store').value;
        const channel=document.getElementById('wa-existing-channel').value;
        if(q)params.set('q',q); if(store)params.set('store_id',store); if(channel)params.set('channel',channel);
        try{
            const payload=await json(urls.customers+'?'+params.toString());
            const customers=payload.customers||[];
            box.innerHTML=customers.length?customers.map(c=>'<label class="wa-customer-row"><input type="checkbox" class="wa-customer-check" value="'+c.id+'" data-store="'+(c.store_id||'')+'" '+(selectedIds.has(Number(c.id))?'checked':'')+'><span><strong>'+escapeHtml(c.name)+'</strong><br><small>'+escapeHtml(c.phone)+' · '+escapeHtml(c.store_name||c.channel||'')+' · '+escapeHtml(c.consent)+'</small></span></label>').join(''):'<div class="wa-muted">'+t.noResults+'</div>';
            box.querySelectorAll('.wa-customer-check').forEach(check=>check.addEventListener('change',()=>{lastPreview=null;document.getElementById('wa-segment-search').value='';const id=Number(check.value);if(check.checked){selectedIds.add(id);if(check.dataset.store)selectedStores.add(Number(check.dataset.store));}else selectedIds.delete(id);syncSelection();}));
        }catch(error){box.innerHTML='<div class="wa-muted">'+escapeHtml(error.message)+'</div>';}
    };
    document.getElementById('wa-search-customers')?.addEventListener('click',searchCustomers);
    document.getElementById('wa-customer-search')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();searchCustomers();}});
    document.getElementById('wa-select-results')?.addEventListener('click',()=>{
        selectedIds.clear();
        lastPreview=null;
        const q=document.getElementById('wa-customer-search').value.trim();
        document.getElementById('wa-segment-search').value=q;
        const store=document.getElementById('wa-existing-store').value;
        lastPreview=null;
        if(store) selectedStores.add(Number(store));
        document.querySelectorAll('.wa-customer-check').forEach(check=>{check.checked=false;});
        syncSelection();
    });
    document.getElementById('wa-existing-channel')?.addEventListener('change',()=>{lastPreview=null;syncSelection();});
    document.getElementById('wa-existing-store')?.addEventListener('change',()=>{
        const store=document.getElementById('wa-existing-store').value;
        if(store) selectedStores.add(Number(store));
        syncSelection();
    });

    document.getElementById('wa-import-preview-btn')?.addEventListener('click',async()=>{
        const file=document.getElementById('wa-import-file').files[0],store=document.getElementById('wa-import-store').value;
        const status=document.getElementById('wa-import-status'); if(!file||!store){status.textContent=t.error;return;}
        const body=new FormData(); body.append('file',file);body.append('store_id',store);status.textContent=t.loading;
        try{
            const payload=await json(urls.importPreview,{method:'POST',body});
            const preview=payload.preview; importBatch=preview.batch_id;document.getElementById('wa-import-batch').value=String(importBatch);
            const selectors=[
                ['wa-map-name','name'],
                ['wa-map-phone','phone'],
                ['wa-map-email','email'],
                ['wa-map-consent-state','consent_state'],
                ['wa-map-consent-source','consent_source'],
                ['wa-map-consent-evidence','consent_evidence'],
                ['wa-map-consent-date','consent_date'],
            ];
            selectors.forEach(([id,key])=>{const select=document.getElementById(id);const empty=id==='wa-map-email'?'<option value="">—</option>':'';select.innerHTML=empty+preview.headers.map(h=>'<option value="'+escapeHtml(h)+'" '+(preview.mapping?.[key]===h?'selected':'')+'>'+escapeHtml(h)+'</option>').join('');});
            const sample=preview.sample||[];document.getElementById('wa-import-sample').innerHTML=sample.length?'<table class="wa-preview-table" data-pagination-exempt="bounded-preview"><thead><tr>'+preview.headers.map(h=>'<th>'+escapeHtml(h)+'</th>').join('')+'</tr></thead><tbody>'+sample.slice(0,10).map(row=>'<tr>'+preview.headers.map(h=>'<td>'+escapeHtml(row[h]||'')+'</td>').join('')+'</tr>').join('')+'</tbody></table>':'';
            document.getElementById('wa-import-preview').classList.add('show');status.textContent=String(preview.rows_total)+' '+t.rows;
        }catch(error){status.textContent=error.message;}
    });

    const syncConsentFields = (prefix, reset = false) => {
        const state=document.getElementById('wa-'+prefix+'-consent-state');
        const source=document.getElementById('wa-'+prefix+'-consent-source');
        const evidence=document.getElementById('wa-'+prefix+'-consent-evidence');
        if(!state||!source||!evidence)return;
        if(reset)state.value='';
        const active=(state.value||'')!=='';
        source.disabled=!active;
        evidence.disabled=!active;
        if(!active){
            source.value='';
            evidence.value='';
        }
    };
    const resetConsentFields = prefix => syncConsentFields(prefix,true);
    ['import','manual'].forEach(prefix=>{
        document.getElementById('wa-'+prefix+'-consent-state')?.addEventListener('change',()=>syncConsentFields(prefix));
        resetConsentFields(prefix);
    });

    const consentPayload = prefix => {
        const state=document.getElementById('wa-'+prefix+'-consent-state')?.value||'';
        if(!state)return {consent_state:null};
        return {
            consent_state:state,
            consent_source:document.getElementById('wa-'+prefix+'-consent-source')?.value||null,
            consent_evidence:document.getElementById('wa-'+prefix+'-consent-evidence')?.value||null,
        };
    };

    document.getElementById('wa-import-commit')?.addEventListener('click',async()=>{
        if(!importBatch)return;const status=document.getElementById('wa-import-status');status.textContent=t.loading;
        const store=document.getElementById('wa-import-store').value;
        const payload={store_id:Number(store),mapping:{
            name:document.getElementById('wa-map-name').value,
            phone:document.getElementById('wa-map-phone').value,
            email:document.getElementById('wa-map-email').value||null,
            consent_state:document.getElementById('wa-map-consent-state').value||null,
            consent_source:document.getElementById('wa-map-consent-source').value||null,
            consent_evidence:document.getElementById('wa-map-consent-evidence').value||null,
            consent_date:document.getElementById('wa-map-consent-date').value||null,
        },...consentPayload('import')};
        try{
            const result=await json(urls.importCommitTemplate.replace('/0/commit','/'+importBatch+'/commit'),{method:'POST',body:JSON.stringify(payload)});
            addIds(result.customer_ids||[]);selectedStores.add(Number(store));syncSelection();resetConsentFields('import');status.textContent=t.created+' '+result.created+' · '+t.matched+' '+result.matched+' · '+t.updated+' '+result.updated+' · '+t.skipped+' '+result.skipped+' · '+t.invalid+' '+result.invalid;
            const errors=Array.isArray(result.errors)?result.errors:[];
            document.getElementById('wa-import-errors').innerHTML=errors.slice(0,20).map(item=>escapeHtml(t.row+' '+item.row+': '+(t.importErrorReasons[item.reason]||item.reason))).join('<br>');
        }catch(error){status.textContent=error.message;}
    });

    document.getElementById('wa-manual-add')?.addEventListener('click',async()=>{
        const store=document.getElementById('wa-manual-store').value,status=document.getElementById('wa-manual-status');if(!store){status.textContent=t.error;return;}
        status.textContent=t.loading;
        try{
            const result=await json(urls.manual,{method:'POST',body:JSON.stringify({store_id:Number(store),manual_lines:document.getElementById('wa-manual-lines').value,...consentPayload('manual')})});
            document.getElementById('wa-import-batch').value=String(result.batch_id||'');addIds(result.customer_ids||[]);selectedStores.add(Number(store));syncSelection();resetConsentFields('manual');status.textContent=t.created+' '+result.created+' · '+t.matched+' '+result.matched+' · '+t.updated+' '+result.updated+' · '+t.skipped+' '+result.skipped+' · '+t.invalid+' '+result.invalid;
        }catch(error){status.textContent=error.message;}
    });

    const buildPreviewPayload=()=>{
        const data=new FormData(form),payload={
            search:document.getElementById('wa-segment-search').value||'',
            customer_ids:[...selectedIds],
            store_ids:[...selectedStores],
            channels:[],
            agent_ids:[],
            tag_ids:[],
            category_ids:[],
            template_id:Number(data.get('template_id')||0)||null,
            test_mode:data.get('test_mode')==='1',
            test_allowlist:data.get('test_allowlist')||'',
            max_recipients:Number(data.get('max_recipients')||1000),
            batch_size:Number(data.get('batch_size')||50),
        };
        ['agent_ids[]','tag_ids[]','category_ids[]'].forEach(name=>{const key=name.replace('[]','');payload[key]=data.getAll(name).map(Number).filter(Boolean);});
        ['min_value','max_value','recency_days'].forEach(key=>{const value=data.get(key);if(value!==null&&value!=='')payload[key]=value;});
        const channel=document.getElementById('wa-existing-channel').value;if(channel)payload.channels=[channel];
        const store=document.getElementById('wa-existing-store').value;if(store&&!payload.store_ids.includes(Number(store)))payload.store_ids.push(Number(store));
        return payload;
    };

    document.getElementById('wa-preview-audience')?.addEventListener('click',async()=>{
        try{
            const result=await json(urls.preview,{method:'POST',body:JSON.stringify(buildPreviewPayload())});
            lastPreview=result;
            const sample=result.sample||[];
            document.getElementById('wa-preview-total').textContent=String((result.eligible||0)+(result.suppressed||0));
            document.getElementById('wa-preview-eligible').textContent=String(result.eligible||0);
            document.getElementById('wa-preview-suppressed').textContent=String(result.suppressed||0);
            document.getElementById('wa-preview-first-contact').textContent=String(result.first_contact||0);
            document.getElementById('wa-preview-existing-contact').textContent=String(result.existing_contact||0);
            document.getElementById('wa-preview-batches').textContent=String(result.estimated_batches||0);
            document.getElementById('wa-preview-reasons').textContent=Object.entries(result.suppression_reasons||{}).map(([k,v])=>(t.suppressionReasons[k]||k)+': '+v).join(' · ')||'—';
            document.getElementById('wa-preview-sample').innerHTML=sample.length?'<table class="wa-preview-table" data-pagination-exempt="bounded-preview"><thead><tr><th>'+escapeHtml(t.sampleName)+'</th><th>'+escapeHtml(t.samplePhone)+'</th><th>'+escapeHtml(t.contactType)+'</th><th>'+escapeHtml(t.sampleStatus)+'</th><th>'+escapeHtml(t.sampleReason)+'</th></tr></thead><tbody>'+sample.map(r=>'<tr><td>'+escapeHtml(r.name)+'</td><td>'+escapeHtml(r.phone)+'</td><td>'+escapeHtml(r.contact_type==='first_contact'?t.firstContact:t.existingContact)+'</td><td>'+(r.eligible?t.eligible:t.suppressed)+'</td><td>'+escapeHtml(t.suppressionReasons[r.reason]||r.reason||'')+'</td></tr>').join('')+'</tbody></table>':'';
            const template=result.template;
            const templateState=template?.approved?t.templateApproved:t.templateBlocked;
            document.getElementById('wa-review-summary-text').textContent=
                t.reviewTemplate+': '+(template?.name||'—')+' · '+templateState+
                ' · '+t.firstContact+': '+String(result.first_contact||0)+
                ' · '+t.existingContact+': '+String(result.existing_contact||0)+
                ' · '+t.eligible+': '+String(result.eligible||0)+
                ' · '+t.suppressed+': '+String(result.suppressed||0);
            document.getElementById('wa-preview-box').classList.remove('wa-hide');
        }catch(error){alert(error.message);}
    });

    form?.addEventListener('change',event=>{
        const name=event.target?.name||'';
        if(!['launch_action','scheduled_at','per_minute_limit'].includes(name)) lastPreview=null;
    });

    form?.addEventListener('submit',event=>{
        syncSelection();
        const source=document.getElementById('wa-audience-source').value;
        if(source==='import'){const store=document.getElementById('wa-import-store').value;if(store)selectedStores.add(Number(store));}
        if(source==='manual'){const store=document.getElementById('wa-manual-store').value;if(store)selectedStores.add(Number(store));}
        syncSelection();

        const action=String(new FormData(form).get('launch_action')||'draft');
        if(action!=='draft' && (!lastPreview || lastPreview.template?.approved!==true)){
            event.preventDefault();
            alert(t.previewRequiredLive);
        }
    });
})();
</script>
@endif
</body>
</html>
