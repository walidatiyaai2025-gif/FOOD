<?php

namespace App\Http\Controllers\Api;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingAnalyticsService;
use App\Http\Controllers\Controller;
use App\Services\SmartOrderingAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SmartOrderingAnalyticsEventController extends Controller
{
    public function store(
        Request $request,
        SmartOrderingAnalyticsService $analytics,
        SmartOrderingAttributionService $attribution,
    ): JsonResponse
    {
        $data = $request->validate([
            'site_public_id' => ['required', 'uuid'],
            'branch_public_id' => ['required', 'uuid'],
            'event_name' => ['required', Rule::in(SmartOrderingAnalyticsService::PUBLIC_EVENTS)],
            'event_id' => ['required', 'uuid'],
            'visitor_token' => ['required', 'string', 'min:16', 'max:160'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'attribution_context' => ['nullable', 'string', 'max:512'],
            'metadata' => ['nullable', 'array'],
            'metadata.placement' => ['nullable', 'string', 'max:160'],
            'metadata.query_length' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'metadata.result_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $site = SmartOrderingSite::query()
            ->where('public_id', (string) $data['site_public_id'])
            ->where('master_enabled', true)
            ->firstOrFail();

        $trustedAttribution = null;
        if (! empty($data['attribution_context'])) {
            $trustedAttribution = $attribution->serverContext(
                (string) $data['attribution_context'],
                (int) $site->store_id,
            );
        }

        $recorded = $analytics->recordPublic(
            $site,
            (string) $data['branch_public_id'],
            (string) $data['event_name'],
            (string) $data['event_id'],
            (string) $data['visitor_token'],
            [
                'product_id' => $data['product_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'qr_public_id' => $trustedAttribution['qr_public_id'] ?? null,
                'source' => $trustedAttribution['source'] ?? null,
                'campaign' => $trustedAttribution['campaign'] ?? null,
                'offer_reference' => isset($trustedAttribution['promotion_id'])
                    ? 'promotion:'.$trustedAttribution['promotion_id']
                    : null,
                'metadata' => (array) ($data['metadata'] ?? []),
            ],
        );

        return response()->json(['recorded' => $recorded], $recorded ? 201 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
