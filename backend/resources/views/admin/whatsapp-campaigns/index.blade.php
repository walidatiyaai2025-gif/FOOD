<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('whatsapp.campaigns.page_title') }}</title>
@include('admin._brand-components')
<style>
.wa-campaign-shell{display:grid;gap:var(--foodex-space-4);min-width:0}
.wa-campaign-shell .foodex-page-header{margin-bottom:0}
.wa-campaign-card{padding:0;overflow:hidden}
.wa-campaign-card>header{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-3);padding:var(--foodex-space-3) var(--foodex-space-4);border-bottom:1px solid var(--foodex-border)}
.wa-campaign-table-wrap{width:100%;overflow:auto;-webkit-overflow-scrolling:touch}
.wa-campaign-table{width:100%;border-collapse:collapse;min-width:860px}
.wa-campaign-table th,.wa-campaign-table td{padding:12px 14px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:middle;white-space:nowrap}
.wa-campaign-table th{background:var(--foodex-background);font-size:var(--foodex-text-xs);color:var(--foodex-muted)}
.wa-campaign-empty{padding:30px!important;text-align:center!important;color:var(--foodex-muted)}
.wa-campaign-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;background:var(--foodex-orange-soft);color:var(--foodex-orange);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold)}
.wa-campaign-status{padding:12px 16px;margin:0}
@media(max-width:767px){.wa-campaign-card>header{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">{{ __('whatsapp.campaigns.nav_title') }}</span>
                <h1>{{ __('whatsapp.campaigns.heading') }}</h1>
                <p>{{ __('whatsapp.campaigns.description') }}</p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <span class="wa-campaign-badge">{{ __('whatsapp.campaigns.dark_launch') }}</span>
                @include('admin._live-notifications',['user'=>auth()->user()])
            </div>
        </header>

        <div class="wa-campaign-shell">
            @if (session('status'))
                <div class="foodex-card wa-campaign-status" role="status">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="foodex-card wa-campaign-status" role="alert">
                    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="foodex-card wa-campaign-card">
                <header><strong>{{ __('whatsapp.campaigns.template_registry') }}</strong></header>
                <div class="wa-campaign-table-wrap">
                    <table class="wa-campaign-table" data-pagination-exempt="bounded-100">
                        <thead>
                            <tr>
                                <th>{{ __('whatsapp.campaigns.name') }}</th>
                                <th>{{ __('whatsapp.campaigns.provider') }}</th>
                                <th>{{ __('whatsapp.campaigns.locale') }}</th>
                                <th>{{ __('whatsapp.campaigns.status') }}</th>
                                <th>{{ __('whatsapp.campaigns.active') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($templates as $template)
                            <tr>
                                <td>{{ $template->name }}</td>
                                <td><code>{{ $template->provider_name }}</code></td>
                                <td>{{ __('whatsapp.campaigns.locales.'.strtolower((string) $template->locale)) }}</td>
                                <td>{{ __('whatsapp.campaigns.template_statuses.'.strtolower((string) $template->provider_status)) }}</td>
                                <td>{{ $template->is_active ? __('whatsapp.campaigns.yes') : __('whatsapp.campaigns.no') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="wa-campaign-empty">{{ __('whatsapp.campaigns.no_templates') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="foodex-card wa-campaign-card">
                <header><strong>{{ __('whatsapp.campaigns.campaigns') }}</strong></header>
                <div class="wa-campaign-table-wrap">
                    <table class="wa-campaign-table" data-pagination-exempt="bounded-100">
                        <thead>
                            <tr>
                                <th>{{ __('whatsapp.campaigns.campaign') }}</th>
                                <th>{{ __('whatsapp.campaigns.template') }}</th>
                                <th>{{ __('whatsapp.campaigns.status') }}</th>
                                <th>{{ __('whatsapp.campaigns.mode') }}</th>
                                <th>{{ __('whatsapp.campaigns.eligible') }}</th>
                                <th>{{ __('whatsapp.campaigns.sent') }}</th>
                                <th>{{ __('whatsapp.campaigns.delivered') }}</th>
                                <th>{{ __('whatsapp.campaigns.read') }}</th>
                                <th>{{ __('whatsapp.campaigns.failed') }}</th>
                                <th>{{ __('whatsapp.campaigns.replies') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($campaigns as $campaign)
                            <tr>
                                <td>{{ $campaign->name }}</td>
                                <td>
                                    {{ $campaign->template_name }}
                                    <small>({{ __('whatsapp.campaigns.template_statuses.'.strtolower((string) $campaign->template_status)) }})</small>
                                </td>
                                <td>{{ __('whatsapp.campaigns.statuses.'.strtolower((string) $campaign->status)) }}</td>
                                <td>{{ $campaign->test_mode ? __('whatsapp.campaigns.test_mode') : __('whatsapp.campaigns.live_mode') }}</td>
                                <td>{{ $campaign->metrics['eligible'] }}</td>
                                <td>{{ $campaign->metrics['sent'] }}</td>
                                <td>{{ $campaign->metrics['delivered'] }}</td>
                                <td>{{ $campaign->metrics['read'] }}</td>
                                <td>{{ $campaign->metrics['failed'] }}</td>
                                <td>{{ $campaign->metrics['replied'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="wa-campaign-empty">{{ __('whatsapp.campaigns.no_campaigns') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
