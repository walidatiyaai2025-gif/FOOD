<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantIntegrationService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class RestaurantIntegrationController extends Controller
{
    public function index(
        Request $request,
        SmartOrderingSite $site,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
    ): View {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');

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
            'health' => $integrations->health($site),
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
    ): RedirectResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate(['status' => ['required', 'in:disabled,enabled,degraded,suspended']]);
        $integrations->setStatus($site, $integration, (string) $data['status']);

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

    public function webhook(
        Request $request,
        SmartOrderingSite $site,
        RestaurantIntegrationService $integrations,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'target_url' => ['required', 'url:https', 'max:1000'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['required', 'string', 'max:120'],
        ]);

        $result = $integrations->createWebhook($actor, $site, (string) $data['target_url'], (array) $data['event_types']);

        return back()
            ->with('status', __('restaurant_integrations.webhook_created'))
            ->with('restaurant_webhook_secret', $result['secret']);
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
