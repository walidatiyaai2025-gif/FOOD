<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Van;
use App\Services\VanRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VanRegistryController extends Controller
{
    public function store(Request $request, VanRegistryService $service): JsonResponse
    {
        Gate::authorize('drivers.b2b.manage');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:vans,code'],
            'plate_number' => ['nullable', 'string', 'max:100', 'unique:vans,plate_number'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'capacity_units' => ['nullable', 'integer', 'min:0'],
            'capacity_weight' => ['nullable', 'numeric', 'min:0'],
            'capabilities' => ['sometimes', 'array'],
            'home_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $service->createVan($data)], 201);
    }

    public function assign(Request $request, Van $van, VanRegistryService $service): JsonResponse
    {
        Gate::authorize('drivers.b2b.manage');

        $data = $request->validate([
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'representative_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'territory_key' => ['nullable', 'string', 'max:150'],
            'van_pool_key' => ['nullable', 'string', 'max:150'],
            'assignment_type' => ['sometimes', 'in:primary,backup'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date'],
            'loaded_work_count' => ['sometimes', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $service->assign($request->user(), $van, $data)], 201);
    }

    public function suspend(Request $request, Van $van, VanRegistryService $service): JsonResponse
    {
        Gate::authorize('drivers.b2b.manage');

        $data = $request->validate([
            'transfer_target_van_id' => ['nullable', 'integer', 'exists:vans,id', 'different:van'],
            'reason' => ['nullable', 'string'],
        ]);
        $target = isset($data['transfer_target_van_id'])
            ? Van::query()->findOrFail($data['transfer_target_van_id'])
            : null;

        return response()->json(['data' => $service->suspend($van, $target, $data['reason'] ?? null)]);
    }
}
