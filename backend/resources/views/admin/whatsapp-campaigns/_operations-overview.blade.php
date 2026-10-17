@php
    $ov = is_array($overview ?? null) ? $overview : [];
    $metrics = is_array($ov['metrics'] ?? null) ? $ov['metrics'] : [];
    $notification = is_array($ov['notification_campaigns'] ?? null) ? $ov['notification_campaigns'] : [];
    $lastRefresh = isset($ov['as_of']) ? \Illuminate\Support\Carbon::parse($ov['as_of'])->format('Y-m-d H:i') : null;
    $campaignStatuses = is_array($ov['campaign_statuses'] ?? null) ? $ov['campaign_statuses'] : [];
    $providerHealth = is_array($ov['provider_health'] ?? null) ? $ov['provider_health'] : [];
    $inbox = is_array($ov['inbox'] ?? null) ? $ov['inbox'] : [];
    $consent = is_array($ov['consent'] ?? null) ? $ov['consent'] : [];
    $funnel = is_array($ov['delivery_funnel'] ?? null) ? $ov['delivery_funnel'] : [];
    $rates = is_array($ov['rates'] ?? null) ? $ov['rates'] : [];
    $trend = is_array($ov['activity_trend'] ?? null) ? $ov['activity_trend'] : [];
    $suppressionReasons = is_array($ov['suppression_reasons'] ?? null) ? $ov['suppression_reasons'] : [];
    $failureReasons = is_array($ov['failure_reasons'] ?? null) ? $ov['failure_reasons'] : [];
    $notificationTotal = (bool)($notification['visible'] ?? false) ? (int)($notification['total'] ?? 0) : 0;
    $notificationStatuses = is_array($notification['statuses'] ?? null) ? $notification['statuses'] : [];
    $activeCampaigns = (int)($campaignStatuses['running'] ?? 0) + (int)($notificationStatuses['active'] ?? 0);
    $scheduledCampaigns = (int)($campaignStatuses['scheduled'] ?? 0) + (int)($notificationStatuses['scheduled'] ?? 0);
    $completedCampaigns = (int)($campaignStatuses['completed'] ?? 0) + (int)($notificationStatuses['completed'] ?? 0);
    $providerIssues = collect($providerHealth)->reject(fn ($count, $status) => strtoupper((string)$status) === 'READY')->sum();
    $metricCards = [
        [
            'label' => __('whatsapp.campaigns.operations.kpis.campaigns'),
            'value' => number_format((int)($metrics['campaigns'] ?? 0) + $notificationTotal),
            'comparison' => [
                'value' => number_format((int)($metrics['campaigns'] ?? 0)),
                'label' => __('whatsapp.campaigns.operations.kpis.whatsapp_campaigns'),
                'trend' => 'neutral',
            ],
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.active'),
            'value' => number_format($activeCampaigns),
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.scheduled'),
            'value' => number_format($scheduledCampaigns),
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.completed'),
            'value' => number_format($completedCampaigns),
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.recipients'),
            'value' => number_format((int)($metrics['recipients'] ?? 0)),
            'comparison' => [
                'value' => number_format((int)($metrics['eligible'] ?? 0)),
                'label' => __('whatsapp.campaigns.eligible'),
                'trend' => 'neutral',
            ],
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.sent'),
            'value' => number_format((int)($metrics['sent'] ?? 0)),
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.delivered'),
            'value' => number_format((int)($metrics['delivered'] ?? 0)),
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.read'),
            'value' => number_format((int)($metrics['read'] ?? 0)),
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.replied'),
            'value' => number_format((int)($metrics['replied'] ?? 0)),
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.failures'),
            'value' => number_format((int)($metrics['failed'] ?? 0)),
            'status' => [
                'label' => __('whatsapp.campaigns.operations.kpis.provider_confirmed'),
                'state' => ((int)($metrics['failed'] ?? 0)) > 0 ? 'danger' : 'success',
            ],
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.opted_out'),
            'value' => number_format((int)($metrics['opted_out'] ?? 0)),
            'status' => [
                'label' => __('whatsapp.campaigns.operations.kpis.consent_policy'),
                'state' => ((int)($metrics['opted_out'] ?? 0)) > 0 ? 'warning' : 'success',
            ],
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.suppressed'),
            'value' => number_format((int)($metrics['suppressed'] ?? 0)),
            'status' => [
                'label' => __('whatsapp.campaigns.operations.kpis.consent_policy'),
                'state' => ((int)($metrics['suppressed'] ?? 0)) > 0 ? 'warning' : 'success',
            ],
            'drilldown_url' => route('admin.whatsapp-campaigns.index').'#wa-campaign-list',
            'last_refresh' => $lastRefresh,
        ],
        [
            'label' => __('whatsapp.campaigns.operations.kpis.inbox_unresolved'),
            'value' => number_format((int)($ov['inbox_unresolved'] ?? 0)),
            'drilldown_url' => route('admin.whatsapp-inbox.index'),
            'last_refresh' => $lastRefresh,
        ],
    ];
    if (($notification['visible'] ?? false) === true) {
        $metricCards[] = [
            'label' => __('whatsapp.campaigns.operations.kpis.notification_campaigns'),
            'value' => number_format($notificationTotal),
            'status' => [
                'label' => __('whatsapp.campaigns.operations.kpis.failed_runs', ['count' => (int)($notification['failed_runs'] ?? 0)]),
                'state' => ((int)($notification['failed_runs'] ?? 0)) > 0 ? 'danger' : 'success',
            ],
            'drilldown_url' => route('admin.notification-campaigns.index'),
            'last_refresh' => $lastRefresh,
        ];
    }
    if (($ov['provider_visible'] ?? false) === true) {
        $metricCards[] = [
            'label' => __('whatsapp.campaigns.operations.kpis.provider_issues'),
            'value' => number_format((int)$providerIssues),
            'status' => [
                'label' => __('whatsapp.campaigns.operations.kpis.connection_health'),
                'state' => $providerIssues > 0 ? 'warning' : 'success',
            ],
            'drilldown_url' => route('admin.whatsapp.connections.index'),
            'last_refresh' => $lastRefresh,
        ];
    }
    $funnelMax = max(1, ...array_map('intval', array_values($funnel ?: [0])));
    $trendMax = max(1, ...array_map(static fn (array $row): int => (int)($row['sent'] ?? 0), $trend ?: [['sent' => 0]]));
@endphp

<style>
.wa-operations{display:grid;gap:14px}
.wa-operations-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.wa-operations .foodex-module-chart{min-width:0}
.wa-operations-funnel{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;align-items:end;min-height:180px}
.wa-operations-funnel span,.wa-operations-trend span{display:grid;gap:6px;align-content:end;text-align:center;min-width:0}
.wa-operations-funnel i,.wa-operations-trend i{display:block;min-height:4px;border-radius:8px 8px 3px 3px;background:var(--foodex-viz-primary)}
.wa-operations-funnel small,.wa-operations-trend small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--foodex-muted)}
.wa-operations-trend{display:grid;grid-template-columns:repeat(14,minmax(0,1fr));gap:5px;align-items:end;min-height:180px}
.wa-operations-list{display:grid;gap:8px}.wa-operations-list>a,.wa-operations-list>span{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:9px 0;border-bottom:1px solid var(--foodex-border);color:inherit;text-decoration:none}
.wa-operations-actions{display:flex;gap:8px;flex-wrap:wrap}
.wa-operations-note{margin:0;color:var(--foodex-muted)}
@media(max-width:920px){.wa-operations-grid{grid-template-columns:1fr}.wa-operations-trend{overflow:auto;grid-template-columns:repeat(14,34px)}}
@media(max-width:620px){.wa-operations-funnel{grid-template-columns:repeat(3,minmax(0,1fr))}}
</style>

<section class="foodex-module-overview wa-operations" data-foodex-module-overview data-state="current" aria-labelledby="wa-operations-title">
    <div class="foodex-viz-header">
        <div>
            <h2 class="foodex-viz-title" id="wa-operations-title">{{ __('whatsapp.campaigns.operations.title') }}</h2>
            <p class="wa-operations-note">{{ __('whatsapp.campaigns.operations.subtitle') }}</p>
        </div>
        <div class="wa-operations-actions">
            <a class="foodex-action-secondary" href="{{ route('admin.whatsapp-inbox.index') }}">{{ __('whatsapp.campaigns.operations.actions.inbox') }}</a>
            @if(($notification['visible'] ?? false) === true)
                <a class="foodex-action-secondary" href="{{ route('admin.notification-campaigns.index') }}">{{ __('whatsapp.campaigns.operations.actions.notifications') }}</a>
            @endif
            @if(($ov['provider_visible'] ?? false) === true)
                <a class="foodex-action-secondary" href="{{ route('admin.whatsapp.connections.index') }}">{{ __('whatsapp.campaigns.operations.actions.connections') }}</a>
            @endif
        </div>
    </div>

    <p class="foodex-alert info">{{ __('whatsapp.campaigns.operations.provider_truth_note') }}</p>

    <div class="foodex-module-kpis">
        @foreach($metricCards as $metric)
            @include('admin._module-dashboard-kpi', ['metric' => $metric])
        @endforeach
    </div>

    <div class="wa-operations-grid">
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.delivery_funnel') }}</h3></div>
            <div class="wa-operations-funnel" role="img" aria-label="{{ __('whatsapp.campaigns.operations.charts.delivery_funnel') }}">
                @foreach(['selected','eligible','sent','delivered','read','replied'] as $stage)
                    <span>
                        <strong class="foodex-number">{{ number_format((int)($funnel[$stage] ?? 0)) }}</strong>
                        <i style="height:{{ max(4,round(((int)($funnel[$stage] ?? 0)/$funnelMax)*110,1)) }}px"></i>
                        <small>{{ __('whatsapp.campaigns.operations.funnel.'.$stage) }}</small>
                    </span>
                @endforeach
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.activity_trend') }}</h3></div>
            <div class="wa-operations-trend" role="img" aria-label="{{ __('whatsapp.campaigns.operations.charts.activity_trend') }}">
                @foreach($trend as $point)
                    <span title="{{ $point['date'] }} · {{ __('whatsapp.campaigns.sent') }} {{ $point['sent'] }} · {{ __('whatsapp.campaigns.delivered') }} {{ $point['delivered'] }} · {{ __('whatsapp.campaigns.read') }} {{ $point['read'] }} · {{ __('whatsapp.campaigns.replies') }} {{ $point['replied'] }}">
                        <strong class="foodex-number">{{ (int)$point['sent'] }}</strong>
                        <i style="height:{{ max(4,round(((int)$point['sent']/$trendMax)*110,1)) }}px"></i>
                        <small>{{ substr($point['date'],5) }}</small>
                    </span>
                @endforeach
            </div>
            <div class="foodex-module-chart-summary">{{ __('whatsapp.campaigns.operations.charts.activity_hint') }}</div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.response_performance') }}</h3></div>
            <div class="wa-operations-list">
                @foreach(['delivery','read','reply','failure'] as $rate)
                    <span>
                        <span>{{ __('whatsapp.campaigns.operations.rates.'.$rate) }}</span>
                        <strong class="foodex-number">{{ number_format((float)($rates[$rate] ?? 0), 2) }}%</strong>
                    </span>
                @endforeach
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.lifecycle') }}</h3></div>
            <div class="wa-operations-list">
                @forelse($campaignStatuses as $status => $count)
                    <a href="{{ route('admin.whatsapp-campaigns.index') }}#wa-campaign-list">
                        <span>{{ __('whatsapp.campaigns.statuses.'.strtolower((string)$status)) }}</span>
                        <strong class="foodex-number">{{ number_format((int)$count) }}</strong>
                    </a>
                @empty
                    <span>{{ __('whatsapp.campaigns.operations.no_data') }}</span>
                @endforelse
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.consent') }}</h3></div>
            <div class="wa-operations-list">
                @forelse($consent as $state => $count)
                    <span><span>{{ __('whatsapp.campaigns.consent_values.'.strtolower((string)$state)) }}</span><strong class="foodex-number">{{ number_format((int)$count) }}</strong></span>
                @empty
                    <span>{{ __('whatsapp.campaigns.operations.no_data') }}</span>
                @endforelse
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.suppression') }}</h3></div>
            <div class="wa-operations-list">
                @forelse($suppressionReasons as $reason => $count)
                    <span><span>{{ __('whatsapp.campaigns.suppression_reason_values.'.(array_key_exists($reason, __('whatsapp.campaigns.suppression_reason_values')) ? $reason : 'unknown')) }}</span><strong class="foodex-number">{{ number_format((int)$count) }}</strong></span>
                @empty
                    <span>{{ __('whatsapp.campaigns.operations.no_data') }}</span>
                @endforelse
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.failures') }}</h3></div>
            <div class="wa-operations-list">
                @forelse($failureReasons as $reason => $count)
                    <span><span>{{ \Illuminate\Support\Str::limit((string)$reason, 80) }}</span><strong class="foodex-number">{{ number_format((int)$count) }}</strong></span>
                @empty
                    <span>{{ __('whatsapp.campaigns.operations.no_failures') }}</span>
                @endforelse
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.inbox') }}</h3><a href="{{ route('admin.whatsapp-inbox.index') }}">{{ __('whatsapp.campaigns.operations.open') }}</a></div>
            <div class="wa-operations-list">
                @forelse($inbox as $status => $count)
                    <a href="{{ route('admin.whatsapp-inbox.index',['status'=>$status]) }}"><span>{{ __('whatsapp.inbox.conversation_statuses.'.$status) }}</span><strong class="foodex-number">{{ number_format((int)$count) }}</strong></a>
                @empty
                    <span>{{ __('whatsapp.campaigns.operations.no_data') }}</span>
                @endforelse
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.provider_health') }}</h3>@if(($ov['provider_visible'] ?? false) === true)<a href="{{ route('admin.whatsapp.connections.index') }}">{{ __('whatsapp.campaigns.operations.open') }}</a>@endif</div>
            @if(($ov['provider_visible'] ?? false) === true)
                <div class="wa-operations-list">
                    @forelse($providerHealth as $status => $count)
                        <a href="{{ route('admin.whatsapp.connections.index') }}"><span>{{ __('whatsapp.connections.health_states.'.$status) }}</span><strong class="foodex-number">{{ number_format((int)$count) }}</strong></a>
                    @empty
                        <span>{{ __('whatsapp.campaigns.operations.no_data') }}</span>
                    @endforelse
                </div>
            @else
                @include('admin._module-dashboard-state', ['state' => 'permission'])
            @endif
        </article>

        @if(($notification['visible'] ?? false) === true)
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('whatsapp.campaigns.operations.charts.notification_campaigns') }}</h3><a href="{{ route('admin.notification-campaigns.index') }}">{{ __('whatsapp.campaigns.operations.open') }}</a></div>
            <div class="wa-operations-list">
                @forelse(($notification['statuses'] ?? []) as $status => $count)
                    <a href="{{ route('admin.notification-campaigns.index',['status'=>$status]) }}"><span>{{ __('whatsapp.campaigns.operations.notification_statuses.'.strtolower((string)$status)) }}</span><strong class="foodex-number">{{ number_format((int)$count) }}</strong></a>
                @empty
                    <span>{{ __('whatsapp.campaigns.operations.no_data') }}</span>
                @endforelse
            </div>
        </article>
        @endif
    </div>
</section>
