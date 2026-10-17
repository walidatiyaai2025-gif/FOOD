<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RestaurantSaaS\RestaurantControlPlaneService;
use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RestaurantSaaSControlPlaneController extends Controller
{
    public function show(
        Request $request,
        Store $store,
        TenantContextResolver $tenants,
        RestaurantControlPlaneService $controlPlane,
    ): JsonResponse {
        $this->authorizeStore($request, $store, $tenants);

        return response()->json($controlPlane->context($store));
    }

    public function provision(
        Request $request,
        Store $store,
        TenantContextResolver $tenants,
        RestaurantControlPlaneService $controlPlane,
    ): JsonResponse {
        $actor = $this->authorizeStore($request, $store, $tenants);

        return response()->json($controlPlane->provision($store, $actor, $request));
    }

    public function update(
        Request $request,
        Store $store,
        TenantContextResolver $tenants,
        RestaurantControlPlaneService $controlPlane,
    ): JsonResponse {
        $actor = $this->authorizeStore($request, $store, $tenants);
        $values = $request->validate([
            'lifecycle_state' => ['sometimes', 'string', Rule::in(['DRAFT', 'ACTIVE', 'SUSPENDED', 'CLOSED'])],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'business_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'tax_registration_number' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        return response()->json($controlPlane->updateLocationProfile($store, $actor, $values, $request));
    }

    private function authorizeStore(
        Request $request,
        Store $store,
        TenantContextResolver $tenants,
    ): User {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $tenants->retail(
            $actor,
            (int) $store->getKey(),
            $actor->hasRole('SUPER_ADMIN') && $request->boolean('support_access'),
            $request,
        );
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $store->getKey()), 403);
        app(RestaurantSaasStoreAccess::class)->assertStore(
            $actor,
            (int) $store->getKey(),
            'smart_ordering.manage',
            'b2c',
        );

        return $actor;
    }
}
