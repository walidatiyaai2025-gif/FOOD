<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GeographyNode;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Services\TerritoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TerritoryController extends Controller
{
    public function storeGeography(Request $request): JsonResponse
    {
        $actor = $this->authorizeTerritories($request);
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:geography_nodes,id'],
            'type' => ['required', Rule::in(['country', 'governorate', 'region', 'city', 'markaz', 'district', 'area'])],
            'code' => ['required', 'string', 'max:96'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'min:2', 'max:3'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $node = GeographyNode::query()->create([
            ...$data,
            'country_code' => strtoupper($data['country_code']),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['data' => $node, 'actor_id' => $actor->getKey()], 201);
    }

    public function storeTerritory(Request $request): JsonResponse
    {
        $this->authorizeTerritories($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:96', 'unique:service_territories,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'country_node_id' => ['required', 'integer', 'exists:geography_nodes,id'],
            'governorate_node_id' => ['nullable', 'integer', 'exists:geography_nodes,id'],
            'city_node_id' => ['nullable', 'integer', 'exists:geography_nodes,id'],
            'district_node_id' => ['nullable', 'integer', 'exists:geography_nodes,id'],
            'default_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['required', Rule::in(['draft', 'active', 'inactive'])],
            'priority' => ['nullable', 'integer', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'service_calendar' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'capabilities' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $territory = ServiceTerritory::query()->create($data);

        return response()->json(['data' => $territory], 201);
    }

    public function storeGeometry(Request $request, ServiceTerritory $territory, TerritoryService $service): JsonResponse
    {
        $actor = $this->authorizeTerritories($request);
        $data = $request->validate([
            'geojson' => ['required', 'array'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
        ]);

        $geometry = $service->addGeometry(
            $territory,
            $data['geojson'],
            $actor,
            $data['effective_from'] ?? null,
            $data['effective_until'] ?? null,
        );

        return response()->json(['data' => $geometry], 201);
    }

    public function resolve(Request $request, TerritoryService $service): JsonResponse
    {
        $actor = $this->authorizeTerritories($request);
        $data = $request->validate([
            'address_type' => ['required', 'string', 'max:64'],
            'address_key' => ['required', 'string', 'max:128'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'explicit_territory_id' => ['nullable', 'integer', 'exists:service_territories,id'],
            'admin_confirmed_territory_id' => ['nullable', 'integer', 'exists:service_territories,id'],
            'at' => ['nullable', 'date'],
        ]);

        $result = $service->resolveAddress(
            $data['address_type'],
            $data['address_key'],
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            $data['explicit_territory_id'] ?? null,
            $data['admin_confirmed_territory_id'] ?? null,
            $actor,
            $data['at'] ?? null,
        );

        return response()->json(['data' => $result]);
    }

    private function authorizeTerritories(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless(
            $actor->hasPermission('territories.manage') || $actor->hasPermission('field_ops.manage'),
            403,
        );

        return $actor;
    }
}
