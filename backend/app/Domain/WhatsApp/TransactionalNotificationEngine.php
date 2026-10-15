<?php

namespace App\Domain\WhatsApp;

use App\Jobs\ProcessWhatsAppTransactionalEvent;
use App\Models\Order;
use App\Models\WhatsAppConnectionProfile;
use App\Support\DisplayNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class TransactionalNotificationEngine
{
    public const EVENTS = [
        'order.created',
        'order.confirmed',
        'order.preparing',
        'order.ready_for_pickup',
        'order.scheduled_reminder',
        'order.dispatched',
        'order.delivered',
        'order.cancelled',
        'invoice.issued',
        'payment.received',
        'payment.failed',
        'refund.processed',
    ];

    public function __construct(
        private readonly RuntimePolicy $policy,
        private readonly OutboundMessageDispatcher $dispatcher,
        private readonly ConversationService $conversations,
    ) {}

    public static function orderEventForStatus(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            'confirmed', 'accepted' => 'order.confirmed',
            'preparing', 'processing', 'ready' => 'order.preparing',
            'dispatched', 'shipped', 'in_transit', 'out_for_delivery' => 'order.dispatched',
            'delivered' => 'order.delivered',
            'cancelled', 'canceled' => 'order.cancelled',
            default => null,
        };
    }

    public static function paymentEventForStatus(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            'paid', 'received', 'completed', 'succeeded', 'success' => 'payment.received',
            'failed', 'declined', 'error' => 'payment.failed',
            'refunded', 'refund', 'refund_processed', 'partially_refunded' => 'refund.processed',
            default => null,
        };
    }

    /** @param array<string, mixed> $context */
    public function forOrder(Order $order, string $eventName, string $eventKey, array $context = []): ?int
    {
        if (! in_array($eventName, self::EVENTS, true)) {
            return null;
        }

        $orderId = (int) $order->getKey();
        if ($orderId <= 0) {
            return null;
        }

        if (DB::transactionLevel() > 0) {
            try {
                DB::afterCommit(function () use ($orderId, $eventName, $eventKey, $context): void {
                    try {
                        $fresh = Order::query()->find($orderId);
                        if ($fresh instanceof Order) {
                            $this->record($fresh, $eventName, $eventKey, $context);
                        }
                    } catch (Throwable) {
                        // WhatsApp is optional and must never fail the originating FOOD transaction.
                    }
                });
            } catch (Throwable) {
                // Registration itself is fail-open.
            }

            return null;
        }

        try {
            return $this->record($order, $eventName, $eventKey, $context);
        } catch (Throwable) {
            return null;
        }
    }

    public function process(int $eventId): void
    {
        try {
            $messageId = DB::transaction(function () use ($eventId): ?int {
                $event = DB::table('whats_app_transactional_events')
                    ->where('id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($event === null || $event->status !== 'queued') {
                    return null;
                }

                DB::table('whats_app_transactional_events')->where('id', $eventId)->update([
                    'status' => 'projecting',
                    'updated_at' => now(),
                ]);

                $payload = $this->decode((string) $event->payload);
                $conversationId = $this->conversations->findOrCreateForSystem(
                    $event->platform_customer_id === null ? null : (int) $event->platform_customer_id,
                    $event->store_id === null ? null : (int) $event->store_id,
                    (string) $event->recipient,
                );
                $now = now();
                $messageId = (int) DB::table('whats_app_messages')->insertGetId([
                    'conversation_id' => $conversationId,
                    'connection_profile_id' => null,
                    'direction' => 'outbound',
                    'content_type' => 'template',
                    'actor_type' => 'system',
                    'actor_user_id' => null,
                    'body' => (string) ($payload['rendered_body'] ?? ''),
                    'attachment_metadata' => json_encode([
                        'purpose' => 'transactional',
                        'template_name' => (string) $event->template_name,
                        'language' => $event->locale === 'ar' ? 'ar' : 'en_US',
                        'transactional_event' => (string) $event->event_name,
                        'transactional_event_id' => (int) $event->id,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'provider_message_ref' => null,
                    'delivery_status' => 'pending_transport',
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('whats_app_conversations')->where('id', $conversationId)->update([
                    'status' => 'WAITING_FOR_CUSTOMER',
                    'last_outbound_at' => $now,
                    'waiting_since' => null,
                    'updated_at' => $now,
                ]);

                DB::table('whats_app_transactional_events')->where('id', $eventId)->update([
                    'message_id' => $messageId,
                    'status' => 'projected',
                    'processed_at' => $now,
                    'updated_at' => $now,
                ]);

                return $messageId;
            }, 3);

            if ($messageId === null) {
                return;
            }

            $dispatchId = $this->dispatcher->queue($messageId);
            $dispatchStatus = $dispatchId === null
                ? 'skipped_transport'
                : (string) (DB::table('whats_app_outbound_dispatches')->where('id', $dispatchId)->value('status') ?? 'queued_transport');

            DB::table('whats_app_transactional_events')
                ->where('id', $eventId)
                ->update([
                    'dispatch_id' => $dispatchId,
                    'status' => $dispatchStatus,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            try {
                DB::table('whats_app_transactional_events')->where('id', $eventId)->update([
                    'status' => 'projection_failed',
                    'updated_at' => now(),
                ]);
            } catch (Throwable) {
                // Keep optional WhatsApp failures isolated from FOOD.
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function record(Order $order, string $eventName, string $eventKey, array $context): ?int
    {
        $identity = $this->customerIdentity($order);
        $storeId = (int) $order->store_id;
        $channel = strtolower((string) $order->channel);
        if (! in_array($channel, ['b2b', 'b2c'], true) || $storeId <= 0) {
            return null;
        }

        $preference = $this->preference($eventName, $storeId, $channel);
        $locale = $identity['locale'];
        $templateName = $this->templateName($eventName, $locale, $preference);
        $payload = [
            ...$context,
            'order_id' => (int) $order->getKey(),
            'order_number' => (string) ($order->order_number ?? ''),
            'order_reference' => DisplayNumber::reference($order->getKey()),
            'store_id' => $storeId,
            'customer_channel' => $channel,
            'customer_name' => $identity['name'],
            'recipient' => $identity['phone'],
            'locale' => $locale,
        ];
        $payload['rendered_body'] = $this->renderBody($eventName, $locale, $payload);

        $reason = null;
        if ($preference === null) {
            $reason = 'toggle_unconfigured';
        } elseif (! (bool) $preference->enabled) {
            $reason = 'toggle_disabled';
        } elseif ($identity['phone'] === null) {
            $reason = 'recipient_unavailable';
        } elseif (! $this->policy->allowsTransactionalNotifications($identity['phone'])) {
            $reason = 'policy_blocked';
        } elseif (! $this->hasReadyProfile()) {
            $reason = 'transport_unconfigured';
        } elseif (! $this->templateAllowed($templateName, $locale)) {
            $reason = 'template_unavailable';
        }

        $status = $reason === null ? 'queued' : 'skipped_'.$reason;
        $idempotencyKey = hash('sha256', implode('|', [
            $eventName,
            $eventKey,
            'store:'.$storeId,
            'channel:'.$channel,
            'customer:'.($identity['platform_customer_id'] ?? 'guest'),
            'recipient:'.($identity['phone'] ?? 'none'),
        ]));
        $now = now();

        $created = DB::table('whats_app_transactional_events')->insertOrIgnore([
            'event_name' => $eventName,
            'event_key' => mb_substr($eventKey, 0, 191),
            'idempotency_key' => $idempotencyKey,
            'store_id' => $storeId,
            'customer_channel' => $channel,
            'delivery_channel' => 'whatsapp',
            'platform_customer_id' => $identity['platform_customer_id'],
            'recipient' => $identity['phone'],
            'locale' => $locale,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'template_name' => $templateName,
            'status' => $status,
            'message_id' => null,
            'dispatch_id' => null,
            'skipped_reason' => $reason,
            'queued_at' => $reason === null ? $now : null,
            'processed_at' => $reason === null ? null : $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $eventId = DB::table('whats_app_transactional_events')
            ->where('idempotency_key', $idempotencyKey)
            ->value('id');

        if ($created === 1 && $reason === null && $eventId !== null) {
            ProcessWhatsAppTransactionalEvent::dispatch((int) $eventId)
                ->onQueue((string) config('whatsapp.transport.queue', 'whatsapp'));
        }

        return $eventId === null ? null : (int) $eventId;
    }

    /** @return array{platform_customer_id:?int,name:?string,phone:?string,locale:string} */
    private function customerIdentity(Order $order): array
    {
        $channel = strtolower((string) $order->channel);
        $customerId = $channel === 'b2b' ? (int) $order->b2b_customer_id : (int) $order->b2c_customer_id;
        $table = $channel === 'b2b' ? 'b2b_customers' : 'b2c_customers';
        $customer = $customerId > 0
            ? DB::table($table)->where('id', $customerId)->first(['user_id', 'name', 'phone'])
            : null;

        $userId = $customer?->user_id === null ? null : (int) $customer->user_id;
        $platformId = $userId === null
            ? null
            : DB::table('platform_customers')->where('user_id', $userId)->value('id');
        $rawLocale = $userId === null ? null : DB::table('users')->where('id', $userId)->value('locale');
        $locale = str_starts_with(strtolower((string) $rawLocale), 'ar') ? 'ar' : 'en';

        return [
            'platform_customer_id' => $platformId === null ? null : (int) $platformId,
            'name' => $customer === null ? null : trim((string) $customer->name),
            'phone' => $this->normalizePhone($customer?->phone),
            'locale' => $locale,
        ];
    }

    private function preference(string $eventName, int $storeId, string $channel): ?object
    {
        foreach ([[$storeId, $channel], [$storeId, '*'], [0, $channel], [0, '*']] as [$scopeStore, $scopeChannel]) {
            $row = DB::table('whats_app_transactional_preferences')
                ->where('event_name', $eventName)
                ->where('delivery_channel', 'whatsapp')
                ->where('store_id', $scopeStore)
                ->where('customer_channel', $scopeChannel)
                ->first();

            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    private function hasReadyProfile(): bool
    {
        return WhatsAppConnectionProfile::query()
            ->where('is_primary', true)
            ->where('status', ConnectionProfileStatus::ACTIVE->value)
            ->where('receive_only', false)
            ->exists();
    }

    private function templateAllowed(string $templateName, string $locale): bool
    {
        if (! Schema::hasTable('whats_app_templates')) {
            return true;
        }

        $row = DB::table('whats_app_templates')
            ->where('provider_name', $templateName)
            ->whereIn('locale', [$locale, $locale === 'ar' ? 'ar_AR' : 'en_US'])
            ->first(['provider_status', 'is_active']);

        return $row === null
            || ((bool) $row->is_active && strtolower((string) $row->provider_status) === 'approved');
    }

    private function templateName(string $eventName, string $locale, ?object $preference): string
    {
        $override = $preference === null
            ? null
            : ($locale === 'ar' ? $preference->template_name_ar : $preference->template_name_en);

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        return 'foodex_'.str_replace('.', '_', $eventName).'_'.$locale;
    }

    /** @param array<string, mixed> $payload */
    private function renderBody(string $eventName, string $locale, array $payload): string
    {
        $order = (string) ($payload['order_reference'] ?? '#');
        $invoice = (string) ($payload['invoice_number'] ?? '');

        $copy = [
            'order.created' => ['ar' => "تم استلام طلبك {$order} بنجاح.", 'en' => "Your order {$order} was received successfully."],
            'order.confirmed' => ['ar' => "تم تأكيد طلبك {$order}.", 'en' => "Your order {$order} has been confirmed."],
            'order.preparing' => ['ar' => "طلبك {$order} قيد التجهيز.", 'en' => "Your order {$order} is being prepared."],
            'order.ready_for_pickup' => ['ar' => "طلبك {$order} جاهز للاستلام.", 'en' => "Your order {$order} is ready for pickup."],
            'order.scheduled_reminder' => ['ar' => "تذكير: موعد طلبك المجدول {$order} يقترب.", 'en' => "Reminder: your scheduled order {$order} is coming up."],
            'order.dispatched' => ['ar' => "طلبك {$order} في الطريق إليك.", 'en' => "Your order {$order} is on the way."],
            'order.delivered' => ['ar' => "تم تسليم طلبك {$order} بنجاح.", 'en' => "Your order {$order} was delivered successfully."],
            'order.cancelled' => ['ar' => "تم إلغاء طلبك {$order}.", 'en' => "Your order {$order} was cancelled."],
            'invoice.issued' => ['ar' => "تم إصدار فاتورة {$invoice} للطلب {$order}.", 'en' => "Invoice {$invoice} was issued for order {$order}."],
            'payment.received' => ['ar' => "تم استلام دفعة الطلب {$order} بنجاح.", 'en' => "Payment for order {$order} was received successfully."],
            'payment.failed' => ['ar' => "تعذر إتمام دفع الطلب {$order}.", 'en' => "Payment for order {$order} could not be completed."],
            'refund.processed' => ['ar' => "تمت معالجة استرداد المبلغ للطلب {$order}.", 'en' => "The refund for order {$order} has been processed."],
        ];

        return (string) ($copy[$eventName][$locale] ?? $copy[$eventName]['en'] ?? '');
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }

        $normalized = '+'.$digits;

        return ModuleState::validNumber($normalized) ? $normalized : null;
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
