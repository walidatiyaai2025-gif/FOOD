<?php

namespace App\Domain\SmartOrdering\WhatsApp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SmartOrderingConversationContextService
{
    /**
     * @param  list<int>  $allowedStoreIds
     * @return array{orders:list<array<string,mixed>>,timeline:Collection<int,array<string,mixed>>}
     */
    public function forConversation(int $conversationId, array $allowedStoreIds): array
    {
        if (! Schema::hasTable('whats_app_smart_ordering_links')) {
            return ['orders' => [], 'timeline' => collect()];
        }

        $conversation = DB::table('whats_app_conversations')->where('id', $conversationId)->first();
        if ($conversation === null
            || $conversation->store_id === null
            || ! in_array((int) $conversation->store_id, $allowedStoreIds, true)) {
            return ['orders' => [], 'timeline' => collect()];
        }

        $storeId = (int) $conversation->store_id;
        $trustedCustomerId = $conversation->platform_customer_id === null
            ? null
            : (int) DB::table('platform_customers')
                ->where('id', (int) $conversation->platform_customer_id)
                ->where('is_active', true)
                ->value('id');

        $links = DB::table('whats_app_smart_ordering_links as links')
            ->where('links.store_id', $storeId)
            ->where(function ($query) use ($conversationId, $trustedCustomerId): void {
                $query->where('links.conversation_id', $conversationId);
                if ($trustedCustomerId !== null) {
                    $query->orWhere('links.platform_customer_id', $trustedCustomerId);
                }
            })
            ->orderByDesc('links.updated_at')
            ->limit(8)
            ->get();

        $orders = $links->map(function (object $link): array {
            $snapshot = $this->decode((string) $link->snapshot);

            return [
                'order_id' => (int) $link->order_id,
                'reference' => (string) $link->public_reference,
                'status' => (string) $link->lifecycle_status,
                'fulfillment_mode' => (string) $link->fulfillment_mode,
                'snapshot' => $snapshot,
                'order_url' => route('admin.operations.orders.index', [
                    'channel' => 'b2c',
                    'order' => (int) $link->order_id,
                ]),
                'last_event_name' => $link->last_event_name,
                'last_event_status' => $link->last_event_status,
                'updated_at' => $link->updated_at,
            ];
        })->values()->all();

        $contextIds = $links->pluck('order_context_id')->map(fn ($id): int => (int) $id)->all();
        if ($contextIds === []) {
            return ['orders' => $orders, 'timeline' => collect()];
        }

        $timeline = DB::table('smart_ordering_order_events as events')
            ->join('smart_ordering_order_contexts as contexts', 'contexts.id', '=', 'events.order_context_id')
            ->join('orders', 'orders.id', '=', 'contexts.order_id')
            ->whereIn('events.order_context_id', $contextIds)
            ->where('orders.store_id', $storeId)
            ->orderBy('events.created_at')
            ->get([
                'events.id',
                'events.to_status',
                'events.created_at',
                'contexts.public_reference',
                'contexts.fulfillment_mode',
                'orders.id as order_id',
                'orders.order_number',
            ])
            ->map(static fn (object $event): array => [
                'kind' => 'smart_ordering',
                'id' => 'smart-ordering-'.(int) $event->id,
                'direction' => 'system',
                'actor' => __('whatsapp.smart_ordering.timeline_actor'),
                'body' => __('whatsapp.smart_ordering.timeline_status', [
                    'order' => (string) $event->order_number,
                    'status' => (string) $event->to_status,
                ]),
                'delivery_status' => null,
                'timestamp' => $event->created_at,
                'attachment' => null,
                'smart_ordering' => [
                    'reference' => (string) $event->public_reference,
                    'order_url' => route('admin.operations.orders.index', [
                        'channel' => 'b2c',
                        'order' => (int) $event->order_id,
                    ]),
                ],
            ]);

        return ['orders' => $orders, 'timeline' => $timeline];
    }

    /** @return array<string,mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
