<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RoutingPolicy;
use App\Services\RoutingPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoutingPolicyController extends Controller
{
    public function store(Request $request, RoutingPolicyService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'mode' => ['required', 'string'],
            'rules' => ['required', 'array'],
            'rules.*.name' => ['required', 'string', 'max:150'],
            'rules.*.conditions' => ['required', 'array'],
            'rules.*.actions' => ['required', 'array'],
            'rules.*.enabled' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
        ]);

        $policy = $service->createDraft(
            $request->user(),
            $data['code'],
            $data['mode'],
            $data['rules'],
            $data['reason'] ?? null,
            $data['effective_from'] ?? null,
            $data['effective_until'] ?? null,
        );

        return response()->json(['data' => $policy], 201);
    }

    public function publish(Request $request, RoutingPolicy $routingPolicy, RoutingPolicyService $service): JsonResponse
    {
        return response()->json(['data' => $service->publish($request->user(), $routingPolicy)]);
    }

    public function simulate(Request $request, RoutingPolicy $routingPolicy, RoutingPolicyService $service): JsonResponse
    {
        $data = $request->validate(['input' => ['required', 'array']]);

        return response()->json(['data' => $service->simulate($routingPolicy, $data['input'])]);
    }

    public function rollback(Request $request, RoutingPolicy $routingPolicy, RoutingPolicyService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        return response()->json(['data' => $service->rollback($request->user(), $routingPolicy, $data['reason'] ?? null)], 201);
    }
}
