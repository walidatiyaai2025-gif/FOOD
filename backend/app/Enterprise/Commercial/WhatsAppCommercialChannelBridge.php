<?php

namespace App\Enterprise\Commercial;

use App\Domain\WhatsApp\MarketingConsentService;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Facades\DB;

final class WhatsAppCommercialChannelBridge
{
    public const CONTRACT_VERSION = 1;

    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly CommercialContextGuard $commercial,
        private readonly CommercialEventFactory $events,
        private readonly MarketingConsentService $consent,
        private readonly RuntimePolicy $runtime,
    ) {}

    /**
     * @return array{
     *     contract_version:int,
     *     medium:string,
     *     store_id:int,
     *     customer_channel:string,
     *     customer:array{platform_customer_id:int,legacy_customer_id:int},
     *     conversation:array{id:int,public_id:string,status:string,deep_link:string},
     *     order:array{id:int,order_number:string,channel:string,status:string}|null
     * }
     */
    public function reference(User $actor, int $conversationId, ?int $orderId = null): array
    {
        $row = DB::table('whats_app_conversations as c')
            ->join('platform_customers as pc', 'pc.id', '=', 'c.platform_customer_id')
            ->where('c.id', $conversationId)
            ->where('c.channel', 'whatsapp')
            ->where('pc.is_active', true)
            ->first([
                'c.id as conversation_id',
                'c.public_id as conversation_public_id',
                'c.status as conversation_status',
                'c.store_id',
                'pc.id as platform_customer_id',
                'pc.legacy_customer_id',
            ]);

        abort_unless(
            $row !== null
                && $row->store_id !== null
                && $row->legacy_customer_id !== null,
            404,
        );

        $storeId = (int) $row->store_id;
        $channel = $this->scope->assertStore(
            $actor,
            $storeId,
            'whatsapp.conversations.view',
        );

        $orderReference = null;
        if ($orderId !== null) {
            $order = $this->commercial->order($actor, $orderId, 'orders.view');
            abort_unless(
                (int) $order->store_id === $storeId
                    && (int) $order->customer_id === (int) $row->legacy_customer_id
                    && strtolower((string) $order->channel) === $channel,
                404,
            );

            $orderReference = [
                'id' => (int) $order->getKey(),
                'order_number' => (string) $order->order_number,
                'channel' => strtolower((string) $order->channel),
                'status' => (string) $order->status,
            ];
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'medium' => 'whatsapp',
            'store_id' => $storeId,
            'customer_channel' => $channel,
            'customer' => [
                'platform_customer_id' => (int) $row->platform_customer_id,
                'legacy_customer_id' => (int) $row->legacy_customer_id,
            ],
            'conversation' => [
                'id' => (int) $row->conversation_id,
                'public_id' => (string) $row->conversation_public_id,
                'status' => (string) $row->conversation_status,
                'deep_link' => route(
                    'admin.whatsapp-inbox.index',
                    ['conversation' => (int) $row->conversation_id],
                    false,
                ),
            ],
            'order' => $orderReference,
        ];
    }

    /**
     * @return array{
     *     consent_state:string,
     *     marketing_eligible:bool,
     *     agent_inbox_eligible:bool,
     *     transactional_eligible:bool
     * }
     */
    public function eligibility(User $actor, int $conversationId): array
    {
        $reference = $this->reference($actor, $conversationId);
        $customerId = $reference['customer']['platform_customer_id'];
        $phone = (string) (DB::table('platform_customers')
            ->where('id', $customerId)
            ->value('phone') ?? '');

        return [
            'consent_state' => $this->consent->state($customerId),
            'marketing_eligible' => $this->consent->isEligible($customerId, $phone),
            'agent_inbox_eligible' => $this->runtime->allowsAgentInbox(),
            'transactional_eligible' => $this->runtime->allowsTransactionalNotifications($phone),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{idempotency_key:string,event:CommercialEventEnvelope,reference:array<string,mixed>}
     */
    public function event(
        User $actor,
        int $conversationId,
        string $fact,
        ?int $orderId = null,
        array $payload = [],
    ): array {
        $fact = strtolower(trim($fact));
        abort_unless(in_array($fact, ['customer', 'order'], true), 404);
        abort_if($fact === 'order' && $orderId === null, 404);

        $reference = $this->reference(
            $actor,
            $conversationId,
            $fact === 'order' ? $orderId : null,
        );

        $aggregateType = $fact === 'order' ? 'order' : 'platform_customer';
        if ($fact === 'order') {
            abort_unless(is_array($reference['order']), 404);
            $aggregateId = (int) $reference['order']['id'];
        } else {
            $aggregateId = (int) $reference['customer']['platform_customer_id'];
        }

        $idempotencyKey = hash('sha256', implode(':', [
            'foodex-whatsapp-commercial',
            (string) self::CONTRACT_VERSION,
            $fact,
            (string) $reference['store_id'],
            (string) $conversationId,
            (string) $aggregateId,
        ]));

        $event = $this->events->make(
            $fact,
            (string) $reference['customer_channel'],
            (int) $reference['store_id'],
            $aggregateType,
            $aggregateId,
            $payload + [
                'whatsapp_reference' => $reference,
                'idempotency_key' => $idempotencyKey,
            ],
        );

        return [
            'idempotency_key' => $idempotencyKey,
            'event' => $event,
            'reference' => $reference,
        ];
    }

    /**
     * @return list<array{
     *     id:int,
     *     public_id:string,
     *     status:string,
     *     unread_count:int,
     *     updated_at:string,
     *     deep_link:string
     * }>
     */
    public function recentConversationReferences(
        User $actor,
        int $platformCustomerId,
        int $storeId,
        int $limit = 25,
    ): array {
        $this->scope->assertStore(
            $actor,
            $storeId,
            'whatsapp.conversations.view',
        );

        $customer = DB::table('platform_customers')
            ->where('id', $platformCustomerId)
            ->where('is_active', true)
            ->first(['id', 'legacy_customer_id', 'origin_store_id']);
        abort_unless($customer !== null, 404);

        $visible = (int) ($customer->origin_store_id ?? 0) === $storeId
            || DB::table('whats_app_conversations')
                ->where('platform_customer_id', $platformCustomerId)
                ->where('store_id', $storeId)
                ->exists()
            || ($customer->legacy_customer_id !== null
                && DB::table('orders')
                    ->where('customer_id', (int) $customer->legacy_customer_id)
                    ->where('store_id', $storeId)
                    ->exists());
        abort_unless($visible, 404);

        $limit = max(1, min(100, $limit));

        return DB::table('whats_app_conversations')
            ->where('platform_customer_id', $platformCustomerId)
            ->where('store_id', $storeId)
            ->where('channel', 'whatsapp')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'public_id', 'status', 'unread_count', 'updated_at'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'public_id' => (string) $row->public_id,
                'status' => (string) $row->status,
                'unread_count' => (int) $row->unread_count,
                'updated_at' => (string) $row->updated_at,
                'deep_link' => route(
                    'admin.whatsapp-inbox.index',
                    ['conversation' => (int) $row->id],
                    false,
                ),
            ])
            ->values()
            ->all();
    }
}
