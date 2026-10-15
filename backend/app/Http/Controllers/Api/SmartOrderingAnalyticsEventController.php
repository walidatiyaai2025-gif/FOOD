<?php

namespace App\Http\Controllers\Api;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingAnalyticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SmartOrderingAnalyticsEventController extends Controller
{
    public function store(Request $request, SmartOrderingAnalyticsService $analytics): JsonResponse
    {
        $data = $request->validate([
            'site_public_id' => ['required', 'uuid'],
            'branch_public_id' => ['required', 'uuid'],
            'event_name' => ['required', Rule::in(SmartOrderingAnalyticsService::PUBLIC_EVENTS)],
            'event_id' => ['required', 'uuid'],
            'visitor_token' => ['required', 'string', 'min:16', 'max:160'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:64'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'offer_reference' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
            'metadata.placement' => ['nullable', 'string', 'max:160'],
            'metadata.query_length' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'metadata.result_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $site = SmartOrderingSite::query()
            ->where('public_id', (string) $data['site_public_id'])
            ->where('master_enabled', true)
            ->firstOrFail();

        $recorded = $analytics->recordPublic(
            $site,
            (string) $data['branch_public_id'],
            (string) $data['event_name'],
            (string) $data['event_id'],
            (string) $data['visitor_token'],
            [
                'product_id' => $data['product_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'source' => $data['source'] ?? null,
                'campaign' => $data['campaign'] ?? null,
                'offer_reference' => $data['offer_reference'] ?? null,
                'metadata' => (array) ($data['metadata'] ?? []),
            ],
        );

        return response()->json(['recorded' => $recorded], $recorded ? 201 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
