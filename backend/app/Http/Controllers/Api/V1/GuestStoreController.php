<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuestStoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $stores = Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->orderBy('stores.id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($stores->items())
                ->map(static fn (Store $store): array => [
                    'id' => (int) $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'store_type' => 'B2C',
                    'storefront_kind' => (string) ($store->storefront_kind ?: 'grocery'),
                    'storefront_config' => is_array($store->storefront_config) ? $store->storefront_config : [],
                    'logo_url' => $this->assetUrl($store->getAttribute('logo_path')),
                    'is_active' => (bool) $store->is_active,
                ])
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ],
        ]);
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = trim($path);

        return str_starts_with($value, 'https://') || str_starts_with($value, 'http://')
            ? $value
            : url('/'.ltrim($value, '/'));
    }
}
