<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use App\Services\AppVersionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AppVersionController extends Controller
{
    public function __invoke(Request $request, AppVersionPolicy $evaluator): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'in:android,ios'],
            'app' => ['required', 'in:customer,driver'],
            'current_version' => ['required', 'string', 'max:64'],
        ]);

        $policy = AppVersion::query()
            ->where('platform', $validated['platform'])
            ->where('app', $validated['app'])
            ->first();

        if ($policy === null) {
            return response()->json(['message' => 'App version policy is not configured.'], 404);
        }

        try {
            return response()->json($evaluator->evaluate($policy, $validated['current_version']));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
