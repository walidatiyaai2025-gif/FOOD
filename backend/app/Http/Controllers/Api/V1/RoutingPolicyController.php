<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RoutingPolicy;
use App\Services\RoutingPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        $data = $request->validate([
            'input' => ['nullable', 'array'],
            'inputs' => ['nullable', 'array'],
            'inputs.*' => ['array'],
            'scope' => ['sometimes', 'array'],
            'scope.*' => ['string'],
            'at' => ['nullable', 'date'],
        ]);

        $hasSingle = array_key_exists('input', $data) && is_array($data['input']);
        $hasBatch = array_key_exists('inputs', $data) && is_array($data['inputs']);

        if ($hasSingle === $hasBatch) {
            throw ValidationException::withMessages([
                'input' => ['Provide exactly one of input or inputs.'],
            ]);
        }

        $scope = $data['scope'] ?? [];
        $at = $data['at'] ?? null;

        $result = $hasBatch
            ? $service->simulateBatch($routingPolicy, $data['inputs'], $scope, $at)
            : $service->simulate($routingPolicy, $data['input'], $scope, $at);

        return response()->json(['data' => $result]);
    }

    public function rollback(Request $request, RoutingPolicy $routingPolicy, RoutingPolicyService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        return response()->json(['data' => $service->rollback($request->user(), $routingPolicy, $data['reason'] ?? null)], 201);
    }
}
