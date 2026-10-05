<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Services\ServiceTerritoryResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GeographyController extends Controller
{
    public function nodes(Request $request): JsonResponse
    {
        Gate::authorize('platform.manage');

        $query = DB::table('geography_nodes')->orderBy('country_code')->orderBy('level')->orderBy('code');

        if ($request->filled('country_code')) {
            $query->where('country_code', strtoupper((string) $request->string('country_code')));
        }

        if ($request->filled('level')) {
            $query->where('level', (string) $request->string('level'));
        }

        return response()->json($query->paginate(min(max($request->integer('per_page', 50), 1), 100)));
    }

    public function territories(Request $request): JsonResponse
    {
        Gate::authorize('platform.manage');

        $query = DB::table('service_territories')->orderByDesc('priority')->orderBy('code');

        if ($request->filled('country_code')) {
            $query->where('country_code', strtoupper((string) $request->string('country_code')));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        return response()->json($query->paginate(min(max($request->integer('per_page', 50), 1), 100)));
    }

    public function storeTerritory(Request $request): JsonResponse
    {
        Gate::authorize('platform.manage');

        $data = $request->validate([
            'geography_node_id' => ['nullable', 'integer', 'exists:geography_nodes,id'],
            'country_code' => ['required', 'string', 'size:2'],
            'code' => ['required', 'string', 'max:96', 'unique:service_territories,code'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', Rule::in(['Polygon', 'MultiPolygon'])],
            'geometry.coordinates' => ['required', 'array', 'min:1'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'priority' => ['sometimes', 'integer', 'between:-100000,100000'],
            'default_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'service_calendar_code' => ['nullable', 'string', 'max:96'],
            'tags' => ['nullable', 'array'],
            'capabilities' => ['nullable', 'array'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);

        $id = DB::table('service_territories')->insertGetId([
            ...$data,
            'country_code' => strtoupper($data['country_code']),
            'geometry' => json_encode($data['geometry'], JSON_THROW_ON_ERROR),
            'tags' => isset($data['tags']) ? json_encode($data['tags'], JSON_THROW_ON_ERROR) : null,
            'capabilities' => isset($data['capabilities']) ? json_encode($data['capabilities'], JSON_THROW_ON_ERROR) : null,
            'status' => $data['status'] ?? 'active',
            'priority' => $data['priority'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => (int) $id], 201);
    }

    public function storeOverride(Request $request): JsonResponse
    {
        Gate::authorize('platform.manage');

        $data = $request->validate([
            'address_id' => ['required', 'integer', 'exists:addresses,id'],
            'service_territory_id' => ['required', 'integer', 'exists:service_territories,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);

        $id = DB::table('address_territory_overrides')->insertGetId([
            ...$data,
            'status' => 'active',
            'actor_user_id' => $request->user()?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => (int) $id], 201);
    }

    public function storeAdminMapping(Request $request): JsonResponse
    {
        Gate::authorize('platform.manage');

        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'service_territory_id' => ['required', 'integer', 'exists:service_territories,id'],
            'priority' => ['sometimes', 'integer', 'between:-100000,100000'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);

        $id = DB::table('territory_admin_mappings')->insertGetId([
            ...$data,
            'country_code' => strtoupper($data['country_code']),
            'status' => 'active',
            'priority' => $data['priority'] ?? 0,
            'actor_user_id' => $request->user()?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => (int) $id], 201);
    }

    public function resolveAddress(Address $address, ServiceTerritoryResolutionService $resolver): JsonResponse
    {
        Gate::authorize('platform.manage');

        return response()->json(['data' => $resolver->resolve($address)]);
    }
}
