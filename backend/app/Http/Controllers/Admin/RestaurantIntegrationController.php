<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantIntegrationService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RestaurantIntegrationController extends Controller
{
    public function index(
        Request $request,
        SmartOrderingSite $site,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
    ): View {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');
        $filters = $request->validate([
            'provider' => ['nullable', 'alpha_dash', 'max:80'],
            'capability' => ['nullable', 'string', 'max:80'],
            'direction' => ['nullable', 'in:inbound,outbound'],
            'status' => ['nullable', 'in:received,processed,retry,dead_letter'],
            'webhook_status' => ['nullable', 'in:pending,retry,delivered,dead_letter'],
            'event_type' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:190'],
        ]);

        return view('admin.smart-ordering-integrations', [
            'site' => $site,
            'user' => $actor,
            'branches' => DB::table('smart_ordering_branches')
                ->where('site_id', $site->getKey())
                ->orderByDesc('is_default')
                ->orderBy('name_en')
                ->get(),
            'integrations' => $integrations->integrations($site),
            'webhooks' => $integrations->webhooks($site),
            'apiClients' => $integrations->apiClients($site),
            'events' => $integrations->integrationEvents($site, $filters),
            'deliveries' => $integrations->webhookDeliveries($site, $filters),
            'analytics' => $integrations->analytics($site),
            'health' => $integrations->health($site),
            'filters' => $filters,
            'connectorTypes' => RestaurantIntegrationService::CONNECTOR_TYPES,
            'capabilities' => RestaurantIntegrationService::CAPABILITIES,
        ]);
    }

    public function install(
        Request $request,
        SmartOrderingSite $site,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'connector_type' => ['required', 'string', 'max:40'],
            'provider_key' => ['required', 'alpha_dash', 'max:80'],
            'display_name' => ['required', 'string', 'max:120'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', 'string', 'max:80'],
            'credential_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $integrations->install(
            $actor,
            $site,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            (string) $data['connector_type'],
            (string) $data['provider_key'],
            (string) $data['display_name'],
            (array) $data['capabilities'],
            $data['credential_reference'] ?? null,
        );

        return back()->with('status', __('restaurant_integrations.saved'));
    }

    public function status(
        Request $request,
        SmartOrderingSite $site,
        string $integration,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate(['status' => ['required', 'in:disabled,enabled,degraded,suspended']]);
        $integrations->setStatus($site, $integration, (string) $data['status']);
        $audit->record('restaurant.integration.status_changed', $actor, null, null, [
            'store_id' => (int) $site->store_id,
            'site_id' => (int) $site->getKey(),
            'integration' => $integration,
            'status' => (string) $data['status'],
        ], $request);

        return back()->with('status', __('restaurant_integrations.status_updated'));
    }

    public function apiClient(
        Request $request,
        SmartOrderingSite $site,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'max:80'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $result = $integrations->createApiClient(
            $actor,
            $site,
            (string) $data['name'],
            (array) $data['scopes'],
            isset($data['expires_at']) ? CarbonImmutable::parse((string) $data['expires_at']) : null,
        );

        return back()
            ->with('status', __('restaurant_integrations.api_client_created'))
            ->with('restaurant_api_credential', $result['credential']);
    }

    public function revokeApiClient(
        Request $request,
        SmartOrderingSite $site,
        string $client,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $integrations->revokeApiClient($site, $client);
        $audit->record('restaurant.integration.api_client_revoked', $actor, null, null, [
            'store_id' => (int) $site->store_id,
            'site_id' => (int) $site->getKey(),
            'api_client' => $client,
        ], $request);

        return back()->with('status', __('restaurant_integrations.api_client_revoked'));
    }

    public function webhook(
        Request $request,
        SmartOrderingSite $site,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'target_url' => ['required', 'url', 'max:1000'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['required', 'string', 'max:120'],
        ]);

        $result = $integrations->createWebhook($actor, $site, (string) $data['target_url'], (array) $data['event_types']);

        return back()
            ->with('status', __('restaurant_integrations.webhook_created'))
            ->with('restaurant_webhook_secret', $result['secret']);
    }

    public function webhookStatus(
        Request $request,
        SmartOrderingSite $site,
        string $webhook,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $integrations->setWebhookActive($site, $webhook, (bool) $data['active']);
        $audit->record('restaurant.integration.webhook_status_changed', $actor, null, null, [
            'store_id' => (int) $site->store_id,
            'site_id' => (int) $site->getKey(),
            'webhook' => $webhook,
            'active' => (bool) $data['active'],
        ], $request);

        return back()->with('status', __('restaurant_integrations.webhook_status_updated'));
    }

    public function retryEvent(
        Request $request,
        SmartOrderingSite $site,
        int $event,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $integrations->retryIntegrationEvent($site, $event);
        $audit->record('restaurant.integration.event_requeued', $actor, null, null, [
            'store_id' => (int) $site->store_id,
            'site_id' => (int) $site->getKey(),
            'event_id' => $event,
        ], $request);

        return back()->with('status', __('restaurant_integrations.retry_queued'));
    }

    public function replayDelivery(
        Request $request,
        SmartOrderingSite $site,
        int $delivery,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $integrations->replayWebhookDelivery($site, $delivery);
        $audit->record('restaurant.integration.webhook_requeued', $actor, null, null, [
            'store_id' => (int) $site->store_id,
            'site_id' => (int) $site->getKey(),
            'delivery_id' => $delivery,
        ], $request);

        return back()->with('status', __('restaurant_integrations.retry_queued'));
    }

    public function export(
        Request $request,
        SmartOrderingSite $site,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
    ): StreamedResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');
        $data = $request->validate([
            'report' => ['required', 'in:connector-health,sync-failures,api-usage,webhook-performance,exceptions'],
        ]);
        $report = (string) $data['report'];
        $rows = $integrations->reportRows($site, $report);

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }

            if ($rows !== []) {
                fputcsv($stream, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($stream, array_values($row));
                }
            }
            fclose($stream);
        }, 'restaurant-integration-'.$report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeSite(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
        string $permission,
    ): User {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $tenants->retail(
            $actor,
            (int) $site->store_id,
            $actor->hasRole('SUPER_ADMIN') && $request->boolean('support_access'),
            $request,
        );
        abort_unless($actor->hasPermission($permission, (int) $site->store_id), 403);

        return $actor;
    }
}
