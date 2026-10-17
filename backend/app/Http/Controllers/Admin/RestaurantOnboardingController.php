<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantControlPlaneService;
use App\Domain\RestaurantSaaS\RestaurantMenuImportPreviewService;
use App\Domain\RestaurantSaaS\RestaurantOnboardingService;
use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RestaurantOnboardingController extends Controller
{
    public function profile(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
        RestaurantControlPlaneService $controlPlane,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $store = $this->store($site);
        $values = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['required', 'string', 'max:160'],
            'tax_registration_number' => ['nullable', 'string', 'max:80'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
        ]);

        $controlPlane->provision($store, $actor, $request);
        $controlPlane->updateLocationProfile($store, $actor, $values, $request);

        return $this->backToSettings($site, $actor)
            ->with('status', __('restaurant_onboarding.profile_saved'));
    }

    public function import(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
        RestaurantMenuImportPreviewService $imports,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $validated = $request->validate([
            'menu_import' => ['required', 'file', 'max:5120'],
        ]);

        $imports->stage($this->store($site), $actor, $validated['menu_import']);

        return $this->backToSettings($site, $actor)
            ->with('status', __('restaurant_onboarding.import_staged'));
    }

    public function launch(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
        RestaurantOnboardingService $onboarding,
    ): RedirectResponse {
        $actor = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.publish');
        $onboarding->launch($this->store($site), $actor, $request);

        return $this->backToSettings($site, $actor)
            ->with('status', __('restaurant_onboarding.launched'));
    }

    public function template(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
    ): StreamedResponse {
        $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');

        $headers = [
            'menu_name_ar',
            'menu_name_en',
            'category_name_ar',
            'category_name_en',
            'item_name_ar',
            'item_name_en',
            'sku',
            'price',
            'description_ar',
            'description_en',
        ];

        return response()->streamDownload(function () use ($headers): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers);
            fclose($stream);
        }, 'foodex-restaurant-menu-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizeSite(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
        string $permission,
    ): User {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        app(RestaurantSaasStoreAccess::class)->assertStore(
            $actor,
            (int) $site->store_id,
            $permission,
            'b2c',
        );

        return $actor;
    }

    private function store(SmartOrderingSite $site): Store
    {
        $store = $site->store()->first();
        abort_unless($store instanceof Store, 404);

        return $store;
    }

    private function backToSettings(SmartOrderingSite $site, User $actor): RedirectResponse
    {
        return redirect()->route('admin.smart-ordering.index', [
            'store_id' => $site->store_id,
            ...($actor->hasRole('SUPER_ADMIN') ? ['support_access' => 1] : []),
        ]);
    }
}
