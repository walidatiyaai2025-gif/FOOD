<?php

namespace App\Domain\SmartOrdering\WhatsApp;

use App\Domain\SmartOrdering\LifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SmartOrderingChatbotContextService
{
    public function __construct(private readonly LifecycleService $lifecycle) {}

    /** @return list<array<string,mixed>> */
    public function recentOrders(int $conversationId, ?int $platformCustomerId): array
    {
        if ($platformCustomerId === null
            || ! Schema::hasTable('smart_ordering_order_contexts')
            || ! Schema::hasTable('whats_app_conversations')) {
            return [];
        }

        $conversation = DB::table('whats_app_conversations')
            ->where('id', $conversationId)
            ->where('platform_customer_id', $platformCustomerId)
            ->first(['store_id']);
        if ($conversation === null || $conversation->store_id === null) {
            return [];
        }

        $customer = DB::table('platform_customers')
            ->where('id', $platformCustomerId)
            ->where('is_active', true)
            ->first(['legacy_customer_id']);
        if ($customer === null || $customer->legacy_customer_id === null) {
            return [];
        }

        $references = DB::table('smart_ordering_order_contexts as contexts')
            ->join('orders', 'orders.id', '=', 'contexts.order_id')
            ->where('orders.store_id', (int) $conversation->store_id)
            ->where('orders.customer_id', (int) $customer->legacy_customer_id)
            ->where('orders.channel', 'b2c')
            ->orderByDesc('contexts.created_at')
            ->limit(3)
            ->pluck('contexts.public_reference');

        $orders = [];
        foreach ($references as $reference) {
            try {
                $tracking = $this->lifecycle->tracking((string) $reference);
                $orders[] = [
                    'reference' => (string) $tracking['reference'],
                    'order_number' => (string) $tracking['order_number'],
                    'status' => (string) $tracking['status'],
                    'fulfillment_mode' => (string) $tracking['fulfillment_mode'],
                    'branch' => $tracking['branch'],
                    'scheduled_for' => $tracking['scheduled_for'],
                    'currency' => (string) $tracking['currency'],
                    'total' => (float) $tracking['total'],
                ];
            } catch (Throwable) {
                // A partial read must not cause the chatbot to invent or guess order state.
            }
        }

        return $orders;
    }
}
