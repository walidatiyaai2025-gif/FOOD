<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RestaurantSaaS\RestaurantIntegrationService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RestaurantIntegrationApiController extends Controller
{
    public function context(Request $request, string $site, RestaurantIntegrationService $integrations): JsonResponse
    {
        [$model] = $this->authorizedSite($request, $site, $integrations, 'restaurant.read');

        return response()->json(['data' => [
            'site_id' => (string) $model->public_id,
            'slug' => (string) $model->slug,
            'runtime_state' => (string) $model->runtime_state,
            'branches' => DB::table('smart_ordering_branches')
                ->where('site_id', $model->getKey())
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name_en')
                ->limit(100)
                ->get(['public_id', 'code', 'name_ar', 'name_en', 'timezone', 'accepting_orders'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ]]);
    }

    public function orders(Request $request, string $site, RestaurantIntegrationService $integrations): JsonResponse
    {
        [$model] = $this->authorizedSite($request, $site, $integrations, 'orders.read');
        $limit = max(1, min(100, $request->integer('limit', 50)));

        $rows = DB::table('smart_ordering_order_contexts as c')
            ->join('smart_ordering_branches as b', 'b.id', '=', 'c.branch_id')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->where('b.site_id', $model->getKey())
            ->when($request->filled('branch'), fn ($q) => $q->where('b.public_id', $request->string('branch')->toString()))
            ->orderByDesc('c.id')
            ->limit($limit)
            ->get([
                'c.public_reference as reference', 'b.public_id as branch_id', 'o.order_number',
                'c.fulfillment_mode', 'c.lifecycle_status as status', 'o.currency', 'o.grand_total', 'o.created_at',
            ]);

        return response()->json(['data' => $rows, 'meta' => ['limit' => $limit]]);
    }

    public function ingest(
        Request $request,
        string $site,
        string $integration,
        RestaurantIntegrationService $integrations,
    ): JsonResponse {
        [$model] = $this->authorizedSite($request, $site, $integrations, 'integrations.write');
        $data = $request->validate([
            'external_event_key' => ['required', 'string', 'max:190'],
            'event_type' => ['required', 'string', 'max:120'],
            'payload' => ['required', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $result = $integrations->ingest(
            $model,
            $integration,
            (string) $data['external_event_key'],
            (string) $data['event_type'],
            (array) $data['payload'],
            isset($data['occurred_at']) ? new \DateTimeImmutable((string) $data['occurred_at']) : null,
        );

        return response()->json(['data' => $result], $result['duplicate'] ? 200 : 202);
    }

    /** @return array{0:SmartOrderingSite,1:array{id:int,site_id:int,scopes:list<string>}} */
    private function authorizedSite(
        Request $request,
        string $site,
        RestaurantIntegrationService $integrations,
        string $scope,
    ): array {
        $credential = (string) $request->header('X-Restaurant-App-Key', '');
        $client = $integrations->authorizeCredential($credential, $scope);
        $model = SmartOrderingSite::query()->where('public_id', $site)->first();
        abort_unless($model instanceof SmartOrderingSite && (int) $model->getKey() === (int) $client['site_id'], 404);

        return [$model, $client];
    }
}
