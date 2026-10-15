<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChatbotOrderDraftService
{
    /** @return array{cart_id:int,item_count:int,total:float} */
    public function addProduct(int $conversationId, int $platformCustomerId, int $productId, float $quantity = 1): array
    {
        $quantity = max(1, min(999, $quantity));
        [$legacyCustomerId, $storeId, $channel] = $this->customerContext($conversationId, $platformCustomerId);

        $product = DB::table('products')
            ->join('store_products', 'store_products.product_id', '=', 'products.id')
            ->where('products.id', $productId)
            ->where('products.is_active', true)
            ->where('store_products.store_id', $storeId)
            ->where('store_products.is_active', true)
            ->select('products.id', 'store_products.price')
            ->first();

        if ($product === null || $product->price === null) {
            throw ValidationException::withMessages([
                'product' => [__('chatbot.customer.product_unavailable')],
            ]);
        }

        $cartId = $this->cart($storeId, $legacyCustomerId, $channel);
        DB::transaction(function () use ($cartId, $productId, $quantity, $product): void {
            $existing = DB::table('cart_items')
                ->where('cart_id', $cartId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                DB::table('cart_items')->insert([
                    'cart_id' => $cartId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price_snapshot' => $product->price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('cart_items')->where('id', $existing->id)->update([
                    'quantity' => min(999, (float) $existing->quantity + $quantity),
                    'unit_price_snapshot' => $product->price,
                    'updated_at' => now(),
                ]);
            }

            DB::table('carts')->where('id', $cartId)->update(['updated_at' => now()]);
        }, 3);

        return $this->totals($cartId);
    }

    /** @return array{cart_id:int,item_count:int,total:float,skipped:int} */
    public function repeatLatestOrder(int $conversationId, int $platformCustomerId): array
    {
        [$legacyCustomerId, $storeId, $channel] = $this->customerContext($conversationId, $platformCustomerId);
        $order = DB::table('orders')
            ->where('customer_id', $legacyCustomerId)
            ->orderByDesc('created_at')
            ->first();

        if ($order === null) {
            throw ValidationException::withMessages([
                'order' => [__('chatbot.customer.no_previous_order')],
            ]);
        }

        $cartId = $this->cart($storeId, $legacyCustomerId, $channel);
        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->limit(100)->get();
        $skipped = 0;

        DB::transaction(function () use ($items, $cartId, $storeId, &$skipped): void {
            foreach ($items as $item) {
                $price = DB::table('store_products')
                    ->where('store_id', $storeId)
                    ->where('product_id', $item->product_id)
                    ->where('is_active', true)
                    ->value('price');

                if ($price === null) {
                    $skipped++;

                    continue;
                }

                DB::table('cart_items')->updateOrInsert(
                    ['cart_id' => $cartId, 'product_id' => $item->product_id],
                    [
                        'quantity' => max(1, min(999, (float) $item->quantity)),
                        'unit_price_snapshot' => $price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            DB::table('carts')->where('id', $cartId)->update(['updated_at' => now()]);
        }, 3);

        return $this->totals($cartId) + ['skipped' => $skipped];
    }

    /** @return Collection<int, object> */
    public function items(int $conversationId, int $platformCustomerId): Collection
    {
        [$legacyCustomerId, $storeId, $channel] = $this->customerContext($conversationId, $platformCustomerId);
        $cartId = DB::table('carts')
            ->where('store_id', $storeId)
            ->where('customer_id', $legacyCustomerId)
            ->where('channel', $channel)
            ->orderByDesc('updated_at')
            ->value('id');

        if ($cartId === null) {
            return collect();
        }

        return DB::table('cart_items')
            ->join('products', 'products.id', '=', 'cart_items.product_id')
            ->where('cart_items.cart_id', $cartId)
            ->select(
                'cart_items.product_id',
                'cart_items.quantity',
                'cart_items.unit_price_snapshot',
                'products.name',
                'products.sku',
            )
            ->orderBy('cart_items.id')
            ->get();
    }

    /** @return array{0:int,1:int,2:string} */
    private function customerContext(int $conversationId, int $platformCustomerId): array
    {
        $customer = DB::table('platform_customers')
            ->where('id', $platformCustomerId)
            ->where('is_active', true)
            ->first();

        $conversation = DB::table('whats_app_conversations')
            ->where('id', $conversationId)
            ->where('platform_customer_id', $platformCustomerId)
            ->first();

        if ($customer === null || $conversation === null || $conversation->store_id === null) {
            throw ValidationException::withMessages([
                'customer' => [__('chatbot.customer.identity_required')],
            ]);
        }

        $channel = strtolower((string) (DB::table('customers')->where('id', $customer->legacy_customer_id)->value('type') ?? 'b2b'));
        $channel = in_array($channel, ['b2b', 'b2c'], true) ? $channel : 'b2b';

        return [(int) $customer->legacy_customer_id, (int) $conversation->store_id, $channel];
    }

    private function cart(int $storeId, int $legacyCustomerId, string $channel): int
    {
        $id = DB::table('carts')
            ->where('store_id', $storeId)
            ->where('customer_id', $legacyCustomerId)
            ->where('channel', $channel)
            ->orderByDesc('updated_at')
            ->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('carts')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'guest_token' => null,
            'channel' => $channel,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{cart_id:int,item_count:int,total:float} */
    private function totals(int $cartId): array
    {
        $rows = DB::table('cart_items')->where('cart_id', $cartId)->get();
        $total = 0.0;
        foreach ($rows as $row) {
            $total += ((float) $row->quantity) * ((float) ($row->unit_price_snapshot ?? 0));
        }

        return [
            'cart_id' => $cartId,
            'item_count' => $rows->count(),
            'total' => round($total, 3),
        ];
    }
}
