<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LiveAd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LiveAdController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:b2b,b2c'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        $channel = (string) $data['channel'];
        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;

        if ($channel === 'b2c') {
            abort_unless($storeId !== null, 422);
            $enabled = DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.id', $storeId)
                ->where('stores.is_active', true)
                ->where('stores.live_ads_enabled', true)
                ->where('store_types.code', 'B2C')
                ->exists();
            abort_unless($enabled, 404);
        }

        $now = now();
        $ads = LiveAd::query()
            ->where('channel', $channel)
            ->where('is_active', true)
            ->when(
                $channel === 'b2c',
                fn ($query) => $query->where('store_id', $storeId),
                fn ($query) => $query->whereNull('store_id'),
            )
            ->where(function ($query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderBy('priority')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $locale = (string) ($data['locale'] ?? 'ar');

        return response()->json([
            'data' => $ads->map(fn (LiveAd $ad): array => [
                'id' => (int) $ad->id,
                'name' => (string) $ad->name,
                'channel' => (string) $ad->channel,
                'store_id' => $ad->store_id === null ? null : (int) $ad->store_id,
                'title' => $locale === 'en' ? $ad->title_en : $ad->title_ar,
                'body' => $locale === 'en' ? $ad->body_en : $ad->body_ar,
                'image_url' => $this->assetUrl($ad->image_path),
                'cta_label' => $locale === 'en' ? $ad->cta_label_en : $ad->cta_label_ar,
                'cta_target' => $ad->cta_target,
                'frequency' => (string) $ad->frequency,
                'dismissible' => (bool) $ad->is_dismissible,
                'priority' => (int) $ad->priority,
                'starts_at' => $ad->starts_at?->toAtomString(),
                'ends_at' => $ad->ends_at?->toAtomString(),
            ])->values(),
        ]);
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = trim($path);
        if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) {
            return $value;
        }

        return url('/'.ltrim($value, '/'));
    }
}
