@extends('layouts.admin')

@section('title', __('whatsapp.campaigns.page_title'))

@section('content')
<div class="container-fluid py-3 foodex-admin-page">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">{{ __('whatsapp.campaigns.heading') }}</h1>
            <p class="text-muted mb-0">{{ __('whatsapp.campaigns.description') }}</p>
        </div>
        <span class="badge bg-warning text-dark">{{ __('whatsapp.campaigns.dark_launch') }}</span>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card mb-4">
        <div class="card-header"><strong>{{ __('whatsapp.campaigns.template_registry') }}</strong></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" data-pagination-exempt="bounded-100">
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
                    <tr><td colspan="5" class="text-muted">{{ __('whatsapp.campaigns.no_templates') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>{{ __('whatsapp.campaigns.campaigns') }}</strong></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" data-pagination-exempt="bounded-100">
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
                            <small class="text-muted">({{ __('whatsapp.campaigns.template_statuses.'.strtolower((string) $campaign->template_status)) }})</small>
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
                    <tr><td colspan="10" class="text-muted">{{ __('whatsapp.campaigns.no_campaigns') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
