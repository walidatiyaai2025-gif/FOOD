<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\B2cCustomer;
use App\Models\User;
use App\Services\CommerceQuoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RepeatOrderService
{
    public function __construct(
        private readonly CommerceQuoteService $quotes,
    ) {}

    /**
     * Build a repeat-order draft from historical quantities and live server pricing.
     *
     * @param list<int> $selectedOrderItemIds
     * @return array<string,mixed>
     */
    public function createDraft(
        SmartOrderingSite $site,
        int $customerId,
        int $sourceOrderId,
        array $selectedOrderItemIds = [],
    ): array
    {
        $order = DB::table('orders')
            ->where('id', $sourceOrderId)
            ->where('store_id', $site->store_id)
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(channel) = ?', ['b2c'])
            ->first(['id', 'status']);

        abort_unless($order !== null, 404);
        abort_unless(in_array(strtolower((string) $order->status), ['completed', 'delivered'], true), 409, 'Only completed orders can be repeated.');

        $itemsQuery = DB::table('order_items')
            ->where('order_id', $sourceOrderId)
            ->orderBy('id');

        if ($selectedOrderItemIds !== []) {
            $ids = array_values(array_unique(array_map('intval', $selectedOrderItemIds)));
            $itemsQuery->whereIn('id', $ids);
        }

        $historicalItems = $itemsQuery->get(['id', 'product_id', 'quantity', 'unit_price', 'name_snapshot']);
        if ($historicalItems->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Select at least one historical order item.']]);
        }

        $b2c = B2cCustomer::query()
            ->where('store_id', $site->store_id)
            ->where('legacy_customer_id', $customerId)
            ->first();

        $user = $b2c?->user_id === null ? null : User::query()->find((int) $b2c->user_id);
        $quoteLines = $historicalItems->map(static fn ($item): array => [
            'product_id' => (int) $item->product_id,
            'quantity' => (float) $item->quantity,
        ])->values()->all();

        $quote = $this->quotes->quote(
            'b2c',
            (int) $site->store_id,
            $b2c,
            $quoteLines,
            $user,
            null,
            null,
            false,
        );

        $selected = $historicalItems->map(static fn ($item): array => [
            'source_order_item_id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'quantity' => (float) $item->quantity,
            'historical_unit_price' => (float) $item->unit_price,
            'historical_name' => (string) $item->name_snapshot,
        ])->values()->all();

        $ttl = max(5, (int) config('smart_ordering.repeat_order.draft_ttl_minutes', 30));
        $publicId = (string) Str::uuid();
        $expiresAt = now()->addMinutes($ttl);

        DB::table('smart_ordering_repeat_drafts')->insert([
            'public_id' => $publicId,
            'site_id' => $site->id,
            'customer_id' => $customerId,
            'source_order_id' => $sourceOrderId,
            'selected_items' => json_encode($selected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'quote' => json_encode($quote, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => 'DRAFT',
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unavailable = collect((array) ($quote['items'] ?? []))
            ->filter(static fn (array $line): bool => (bool) ($line['is_available'] ?? false) === false)
            ->map(static fn (array $line): array => [
                'product_id' => (int) ($line['product_id'] ?? 0),
                'name' => $line['name'] ?? null,
                'reason_codes' => array_values((array) ($line['commercial_reason_codes'] ?? [])),
            ])->values()->all();

        return [
            'draft_id' => $publicId,
            'source_order_id' => $sourceOrderId,
            'expires_at' => $expiresAt->toAtomString(),
            'quote' => $quote,
            'has_unavailable_items' => $unavailable !== [],
            'unavailable_items' => $unavailable,
            'requires_customer_confirmation' => true,
            'pricing_notice' => 'LIVE_PRICE_AND_AVAILABILITY_REVALIDATED',
        ];
    }
}
