<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SmartOrdering\FeedbackService;
use App\Domain\SmartOrdering\LoyaltyAdminService;
use App\Domain\SmartOrdering\LoyaltyService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\RepeatOrderService;
use App\Domain\SmartOrdering\RestaurantCustomerService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SmartOrderingCustomerController extends Controller
{
    public function orders(
        Request $request,
        RestaurantCustomerService $customers,
        SmartOrderingRuntimePolicy $runtime,
    ): JsonResponse {
        [$site, $customerId] = $this->context($request);
        $runtime->assertRequest($site, Module::ORDERING, $request);

        $orders = collect($customers->orderHistory($site, $customerId, $request->integer('limit', 20)))
            ->map(static fn (array $order): array => [
                'order_number' => (string) $order['order_number'],
                'status' => (string) $order['status'],
                'currency' => (string) $order['currency'],
                'grand_total' => (float) $order['grand_total'],
                'created_at' => $order['created_at'],
            ])->values()->all();

        return response()->json(['data' => $orders]);
    }

    public function repeat(
        Request $request,
        string $orderNumber,
        RepeatOrderService $repeat,
        SmartOrderingRuntimePolicy $runtime,
    ): JsonResponse {
        [$site, $customerId] = $this->context($request);
        $runtime->assertRequest($site, Module::ORDERING, $request);

        $orderId = DB::table('orders')
            ->where('store_id', $site->store_id)
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(channel) = ?', ['b2c'])
            ->where('order_number', $orderNumber)
            ->value('id');
        abort_unless($orderId !== null, 404);

        $data = $request->validate([
            'product_ids' => ['nullable', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'min:1'],
        ]);

        $selectedItemIds = [];
        if (($data['product_ids'] ?? []) !== []) {
            $selectedItemIds = DB::table('order_items')
                ->where('order_id', (int) $orderId)
                ->whereIn('product_id', array_values(array_unique(array_map('intval', (array) $data['product_ids']))))
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $draft = $repeat->createDraft(
            $site,
            $customerId,
            (int) $orderId,
            $selectedItemIds,
        );

        unset($draft['source_order_id']);

        return response()->json(['data' => $draft], 201);
    }

    public function loyalty(
        Request $request,
        LoyaltyService $loyalty,
        LoyaltyAdminService $catalog,
    ): JsonResponse {
        [$site, $customerId] = $this->context($request);
        $balance = $loyalty->balance($site, $customerId);

        return response()->json([
            'data' => [
                ...$balance,
                'rewards' => $catalog->rewards($site),
            ],
        ]);
    }

    public function redeem(
        Request $request,
        string $reward,
        LoyaltyService $loyalty,
    ): JsonResponse {
        [$site, $customerId] = $this->context($request);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($idempotencyKey === '', 422, 'Idempotency-Key is required.');

        $result = $loyalty->redeemReward(
            $site,
            $customerId,
            $reward,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            $idempotencyKey,
        );

        return response()->json(['data' => [
            'points' => $result['points'],
            'balance' => $result['balance'],
            'idempotent' => $result['idempotent'],
        ]]);
    }

    public function feedback(
        Request $request,
        string $orderNumber,
        FeedbackService $feedback,
    ): JsonResponse {
        [$site, $customerId] = $this->context($request);
        $orderId = DB::table('orders')
            ->where('store_id', $site->store_id)
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(channel) = ?', ['b2c'])
            ->where('order_number', $orderNumber)
            ->value('id');
        abort_unless($orderId !== null, 404);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $result = $feedback->submit(
            $site,
            $customerId,
            (int) $orderId,
            (int) $data['rating'],
            $data['feedback'] ?? null,
        );

        return response()->json(['data' => $result], 201);
    }

    /** @return array{0:SmartOrderingSite,1:int} */
    private function context(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $site = $request->attributes->get('smart_ordering.site');
        abort_unless($site instanceof SmartOrderingSite, 404);

        $customerId = DB::table('b2c_customers')
            ->where('store_id', $site->store_id)
            ->where('user_id', $user->getKey())
            ->value('legacy_customer_id');

        abort_unless($customerId !== null, 404);

        return [$site, (int) $customerId];
    }
}
