<?php

namespace App\Http\Controllers\Api;

use App\Domain\SmartOrdering\CartService;
use App\Domain\SmartOrdering\SmartOrderingMerchandisingService;
use App\Http\Controllers\Controller;
use App\Models\SmartOrderingCart;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SmartOrderingMerchandisingController extends Controller
{
    public function placement(
        string $cart,
        Request $request,
        CartService $carts,
        SmartOrderingMerchandisingService $merchandising,
    ): JsonResponse {
        $data = $request->validate([
            'placement' => ['required', Rule::in(SmartOrderingMerchandisingService::PLACEMENTS)],
            'trigger_product_id' => ['nullable', 'integer', 'min:1'],
        ]);

        [$model, $branch, $site, $customerId] = $this->context($cart, $request, $carts);
        $quote = $carts->preview($model);

        return response()->json([
            'data' => $merchandising->placement(
                $site,
                $branch,
                $model,
                $quote,
                (string) $data['placement'],
                $customerId,
                isset($data['trigger_product_id']) ? (int) $data['trigger_product_id'] : null,
            ),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function event(
        string $cart,
        Request $request,
        CartService $carts,
        SmartOrderingMerchandisingService $merchandising,
    ): JsonResponse {
        $data = $request->validate([
            'event_type' => ['required', Rule::in(SmartOrderingMerchandisingService::PUBLIC_EVENTS)],
            'placement' => ['required', Rule::in(SmartOrderingMerchandisingService::PLACEMENTS)],
            'offer_id' => ['nullable', 'uuid', 'required_without:recommendation_id'],
            'recommendation_id' => ['nullable', 'uuid', 'required_without:offer_id'],
            'metadata' => ['nullable', 'array'],
        ]);
        $eventKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if(strlen($eventKey) < 8 || strlen($eventKey) > 100, 422, 'Idempotency-Key is required.');

        [$model, $branch, $site, $customerId] = $this->context($cart, $request, $carts);
        $merchandising->trackPublicEvent(
            $site,
            $branch,
            $model,
            (string) $data['event_type'],
            (string) $data['placement'],
            isset($data['offer_id']) ? (string) $data['offer_id'] : null,
            isset($data['recommendation_id']) ? (string) $data['recommendation_id'] : null,
            $eventKey,
            (array) ($data['metadata'] ?? []),
            $customerId,
        );

        return response()->json(['accepted' => true], 202);
    }

    /** @return array{0:SmartOrderingCart,1:mixed,2:mixed,3:?int} */
    private function context(string $cart, Request $request, CartService $carts): array
    {
        $token = trim((string) $request->header('X-Smart-Cart-Token', ''));
        abort_if($token === '', 401, 'Cart token is required.');

        $model = $carts->authorized($cart, $token);
        $branch = $carts->branchForCart($model);
        $site = $branch->site()->firstOrFail();

        $customerId = null;
        $user = $request->user();
        if ($user instanceof User) {
            $value = DB::table('b2c_customers')
                ->where('store_id', $site->store_id)
                ->where('user_id', $user->id)
                ->value('legacy_customer_id');
            $customerId = $value === null ? null : (int) $value;
        }

        return [$model, $branch, $site, $customerId];
    }
}
