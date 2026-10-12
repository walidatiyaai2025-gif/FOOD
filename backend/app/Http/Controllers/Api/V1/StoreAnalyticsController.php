<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MobileStoreAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreAnalyticsController extends Controller
{
    public function __invoke(
        Request $request,
        MobileStoreAnalyticsService $analytics,
    ): JsonResponse {
        $validated = $request->validate([
            'retail_store_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'seen_insight_version' => ['nullable', 'regex:/\A[a-fA-F0-9]{64}\z/'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'data' => $analytics->forUser(
                $user,
                isset($validated['retail_store_id'])
                    ? (int) $validated['retail_store_id']
                    : null,
                $validated['from'] ?? null,
                $validated['to'] ?? null,
                $validated['seen_insight_version'] ?? null,
            ),
        ]);
    }
}
