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
use Illuminate\Support\Str;

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
            ->map(static function (array $order): array {
                $items = DB::table('order_items')
                    ->where('order_id', (int) $order['order_id'])
                    ->orderBy('id')
                    ->get(['sku_snapshot', 'name_snapshot', 'quantity', 'unit_price', 'line_total'])
                    ->map(static fn ($item): array => [
                        'sku' => (string) $item->sku_snapshot,
                        'name' => (string) $item->name_snapshot,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'line_total' => (float) $item->line_total,
                    ])->values()->all();

                return [
                    'order_number' => (string) $order['order_number'],
                    'status' => (string) $order['status'],
                    'currency' => (string) $order['currency'],
                    'grand_total' => (float) $order['grand_total'],
                    'created_at' => $order['created_at'],
                    'items' => $items,
                ];
            })->values()->all();

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
            'skus' => ['nullable', 'array', 'max:100'],
            'skus.*' => ['string', 'max:191'],
        ]);

        $selectedItemIds = [];
        if (($data['skus'] ?? []) !== []) {
            $skus = array_values(array_unique(array_map(
                static fn ($sku): string => trim((string) $sku),
                (array) $data['skus'],
            )));
            $matchingItems = DB::table('order_items')
                ->where('order_id', (int) $orderId)
                ->whereIn('sku_snapshot', $skus)
                ->get(['id', 'sku_snapshot']);
            $matchedSkus = $matchingItems
                ->pluck('sku_snapshot')
                ->map(static fn ($sku): string => (string) $sku)
                ->unique()
                ->values()
                ->all();
            abort_unless(count($matchedSkus) === count($skus), 422, 'One or more selected SKUs do not belong to this order.');
            $selectedItemIds = $matchingItems
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

        $quote = (array) ($draft['quote'] ?? []);
        $publicItems = collect((array) ($quote['items'] ?? []))
            ->map(static fn (array $line): array => [
                'sku' => $line['sku'] ?? null,
                'name' => $line['name'] ?? null,
                'quantity' => (float) ($line['quantity'] ?? 0),
                'unit_price' => $line['unit_price'] === null ? null : (float) $line['unit_price'],
                'line_total' => $line['line_total'] === null ? null : (float) $line['line_total'],
                'is_available' => (bool) ($line['is_available'] ?? false),
                'availability_state' => $line['availability_state'] ?? null,
                'reason_codes' => array_values((array) ($line['commercial_reason_codes'] ?? [])),
            ])->values()->all();

        return response()->json(['data' => [
            'draft_id' => (string) $draft['draft_id'],
            'expires_at' => $draft['expires_at'],
            'requires_customer_confirmation' => true,
            'pricing_notice' => (string) $draft['pricing_notice'],
            'quote' => [
                'quote_id' => $quote['quote_id'] ?? null,
                'quoted_at' => $quote['quoted_at'] ?? null,
                'currency' => $quote['currency'] ?? null,
                'items' => $publicItems,
                'subtotal' => (float) ($quote['subtotal'] ?? 0),
                'discount_total' => (float) ($quote['discount_total'] ?? 0),
                'delivery_total' => (float) ($quote['delivery_total'] ?? 0),
                'tax_total' => (float) ($quote['tax_total'] ?? 0),
                'grand_total' => (float) ($quote['grand_total'] ?? 0),
                'has_unavailable_items' => (bool) ($quote['has_unavailable_items'] ?? false),
            ],
        ]], 201);
    }

    public function loyalty(
        Request $request,
        LoyaltyService $loyalty,
        LoyaltyAdminService $catalog,
    ): JsonResponse {
        [$site, $customerId] = $this->context($request);
        $balance = $loyalty->balance($site, $customerId);

        $branchReference = trim((string) $request->query('branch', ''));
        $branchId = null;
        if ($branchReference !== '') {
            abort_unless(Str::isUuid($branchReference), 422);
            $branchId = DB::table('smart_ordering_branches')
                ->where('site_id', $site->id)
                ->where('public_id', $branchReference)
                ->where('is_active', true)
                ->value('id');
            abort_unless($branchId !== null, 404);
        }

        $rewards = collect($catalog->rewards($site, $branchId === null ? null : (int) $branchId))
            ->map(static function (array $reward) use ($site): array {
                $branch = $reward['branch_id'] === null
                    ? null
                    : DB::table('smart_ordering_branches')
                        ->where('site_id', $site->id)
                        ->where('id', (int) $reward['branch_id'])
                        ->value('public_id');

                return [
                    'id' => (string) $reward['id'],
                    'branch' => $branch === null ? null : (string) $branch,
                    'name_ar' => $reward['name_ar'],
                    'name_en' => (string) $reward['name_en'],
                    'points_cost' => (int) $reward['points_cost'],
                ];
            })->values()->all();

        return response()->json([
            'data' => [
                ...$balance,
                'rewards' => $rewards,
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
            'branch' => ['nullable', 'uuid'],
        ]);
        $branchId = null;
        if (isset($data['branch'])) {
            $branchId = DB::table('smart_ordering_branches')
                ->where('site_id', $site->id)
                ->where('public_id', (string) $data['branch'])
                ->where('is_active', true)
                ->value('id');
            abort_unless($branchId !== null, 404);
        }

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($idempotencyKey === '', 422, 'Idempotency-Key is required.');

        $result = $loyalty->redeemReward(
            $site,
            $customerId,
            $reward,
            $branchId === null ? null : (int) $branchId,
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
