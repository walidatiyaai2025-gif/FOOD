<?php

namespace App\Services;

use App\Domain\WhatsApp\TransactionalNotificationEngine;
use App\Models\DriverAssignment;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformCustomer;
use App\Models\User;
use App\Support\DisplayNumber;
use Illuminate\Support\Facades\DB;

final class DashboardOperationalNotifier
{
    public function __construct(
        private readonly OrderLifecycleNotificationService $lifecycle,
        private readonly OperationalTenantScope $tenantScope,
        private readonly TransactionalNotificationEngine $whatsAppTransactions,
    ) {}

    public function platformCustomerRegistered(PlatformCustomer $customer): void
    {
        $channel = strtolower((string) $customer->origin_channel);
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            return;
        }

        $storeId = $customer->origin_store_id === null
            ? null
            : (int) $customer->origin_store_id;
        if ($storeId === null || $storeId <= 0) {
            return;
        }

        $permission = $channel === 'b2c'
            ? 'customers.view'
            : 'b2b.accounts.view';
        $deepLink = route(
            'admin.customer-360.show',
            ['platformCustomer' => $customer->getKey()],
            false,
        );

        $recipients = $this->dashboardOperationalRecipients(
            $permission,
            $storeId,
            $channel,
        );

        foreach ($recipients as $recipient) {
            $type = 'customer.registered';
            $eventKey = 'platform-customer-registered:'.$customer->getKey();
            $dedupeKey = $this->dedupeKey(
                $recipient,
                $type,
                $eventKey,
                $channel,
                $storeId,
            );

            Notification::query()->firstOrCreate(
                [
                    'user_id' => $recipient->id,
                    'dedupe_key' => $dedupeKey,
                ],
                [
                    'channel' => 'in_app',
                    'type' => $type,
                    'title' => 'تسجيل عميل منصة جديد',
                    'body' => 'تم تسجيل '.$customer->name.' بنجاح.',
                    'title_ar' => 'تسجيل عميل منصة جديد',
                    'title_en' => 'New platform customer registration',
                    'body_ar' => 'تم تسجيل '.$customer->name.' بنجاح.',
                    'body_en' => $customer->name.' registered successfully.',
                    'audience' => 'user',
                    'app' => 'dashboard',
                    'target_channel' => $channel,
                    'store_id' => $storeId,
                    'status' => 'published',
                    'published_at' => now(),
                    'data' => [
                        'platform_customer_id' => (int) $customer->getKey(),
                        'user_id' => (int) $customer->user_id,
                        'store_id' => $storeId,
                        'channel' => $channel,
                        'registration_source' => (string) $customer->registration_source,
                        'deep_link' => $deepLink,
                    ],
                ],
            );
        }
    }

    public function orderCreated(Order $order, string $source = 'legacy'): void
    {
        $source = strtolower(trim($source));
        if (! in_array($source, ['customer_app', 'dashboard', 'van', 'integration', 'legacy'], true)) {
            $source = 'legacy';
        }

        $customerIdentity = $this->orderCustomerIdentity($order);
        $customerNameAr = $customerIdentity['name'] ?? 'عميل غير معروف';
        $customerNameEn = $customerIdentity['name'] ?? 'Unknown customer';
        $phonePrefixAr = $customerIdentity['phone'] === null
            ? ''
            : 'الهاتف: '.$customerIdentity['phone'].' · ';
        $phonePrefixEn = $customerIdentity['phone'] === null
            ? ''
            : 'Phone: '.$customerIdentity['phone'].' · ';

        $this->notifyOrderAudience(
            $order,
            'order.created',
            'order-created:'.$order->getKey(),
            'orders.view',
            'هذا الطلب من: '.$customerNameAr,
            'This order is from: '.$customerNameEn,
            $phonePrefixAr.'طلب جديد '.DisplayNumber::reference($order->getKey()).' · الإجمالي '.DisplayNumber::money((float) $order->grand_total, $order->currency).' '.$order->currency,
            $phonePrefixEn.'New order '.DisplayNumber::reference($order->getKey()).' · Total '.DisplayNumber::money((float) $order->grand_total, $order->currency).' '.$order->currency,
            [
                'status' => (string) $order->status,
                'source' => $source,
                'customer_name' => $customerIdentity['name'],
                'customer_phone' => $customerIdentity['phone'],
            ],
        );

        $this->lifecycle->customerOrderCreated($order);

        $this->whatsAppTransactions->forOrder(
            $order,
            'order.created',
            'order-created:'.$order->getKey(),
            ['status' => (string) $order->status, 'source' => $source],
        );
    }

    public function orderStatusChanged(Order $order, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $historyId = DB::table('order_status_history')
            ->where('order_id', $order->getKey())
            ->where('to_status', $to)
            ->orderByDesc('id')
            ->value('id');
        $eventKey = 'order-status:'.($historyId ?: $order->getKey().':'.$from.':'.$to.':'.(string) $order->updated_at);

        $this->notifyOrderAudience(
            $order,
            'order.status_changed',
            $eventKey,
            'orders.view',
            'تحديث الطلب '.DisplayNumber::reference($order->getKey()),
            'Order update '.DisplayNumber::reference($order->getKey()),
            'تغيرت حالة الطلب من '.$from.' إلى '.$to,
            'Order status changed from '.$from.' to '.$to,
            ['from_status' => $from, 'to_status' => $to],
        );

        $this->lifecycle->orderStatusChanged($order, $from, $to);

        $transactionalEvent = TransactionalNotificationEngine::orderEventForStatus($to);
        if ($transactionalEvent !== null) {
            $this->whatsAppTransactions->forOrder(
                $order,
                $transactionalEvent,
                $eventKey,
                ['from_status' => $from, 'to_status' => $to],
            );
        }
    }

    public function driverAssigned(
        Order $order,
        DriverAssignment $assignment,
        ?int $previousDriverId = null,
        bool $manualB2bDispatch = false,
    ): void {
        $reassigned = $previousDriverId !== null;
        $type = $reassigned ? 'delivery.reassigned' : 'delivery.assigned';

        $this->notifyOrderAudience(
            $order,
            $type,
            $type.':'.$assignment->getKey(),
            'orders.view',
            $reassigned ? 'إعادة تعيين سائق للطلب '.DisplayNumber::reference($order->getKey()) : 'تم تعيين سائق للطلب '.DisplayNumber::reference($order->getKey()),
            $reassigned ? 'Driver reassigned for order '.DisplayNumber::reference($order->getKey()) : 'Driver assigned to order '.DisplayNumber::reference($order->getKey()),
            $reassigned ? 'تم نقل الطلب إلى سائق آخر.' : 'تم إسناد الطلب إلى سائق.',
            $reassigned ? 'The order was moved to another driver.' : 'The order was assigned to a driver.',
            [
                'assignment_id' => (int) $assignment->getKey(),
                'driver_id' => (int) $assignment->driver_id,
                'previous_driver_id' => $previousDriverId,
                'delivery_status' => (string) $assignment->status,
            ],
        );

        $this->lifecycle->driverAssigned(
            $order,
            $assignment,
            $previousDriverId,
            $manualB2bDispatch,
        );
    }

    public function deliveryChanged(
        Order $order,
        string $status,
        ?DriverAssignment $assignment = null,
        ?string $note = null,
        ?string $fromStatus = null,
    ): void {
        $auditId = null;
        if ($assignment instanceof DriverAssignment) {
            $auditId = DB::table('audit_logs')
                ->where('event', 'delivery.assignment.status_changed')
                ->where('auditable_type', DriverAssignment::class)
                ->where('auditable_id', $assignment->getKey())
                ->orderByDesc('id')
                ->value('id');
        }

        $assignmentKey = $assignment instanceof DriverAssignment ? $assignment->getKey() : $order->getKey();
        $eventUpdatedAt = $assignment instanceof DriverAssignment ? $assignment->updated_at : $order->updated_at;
        $eventKey = 'delivery-status:'.($auditId ?: $assignmentKey.':'.$status.':'.(string) $eventUpdatedAt);

        $this->notifyOrderAudience(
            $order,
            'delivery.status_changed',
            $eventKey,
            'orders.view',
            'تحديث التوصيل '.DisplayNumber::reference($order->getKey()),
            'Delivery update '.DisplayNumber::reference($order->getKey()),
            'حالة التوصيل الحالية: '.$status,
            'Current delivery status: '.$status,
            [
                'assignment_id' => $assignment?->getKey(),
                'driver_id' => $assignment?->driver_id,
                'from_delivery_status' => $fromStatus,
                'delivery_status' => $status,
                'note' => $note,
            ],
        );

        if ($assignment instanceof DriverAssignment) {
            if ($status === 'unassigned') {
                $this->lifecycle->driverUnassigned($order, $assignment, $note);
            } else {
                $this->lifecycle->deliveryStatusChanged(
                    $order,
                    $assignment,
                    $fromStatus ?? 'unknown',
                    $status,
                    $note,
                );
            }
        }

        if ($assignment instanceof DriverAssignment && $note !== null && trim($note) !== '') {
            $this->notifyOrderAudience(
                $order,
                'delivery.note_added',
                'delivery-note:'.($auditId ?: $assignment->getKey().':'.$status.':'.hash('sha256', trim($note))),
                'orders.view',
                'ملاحظة سائق على الطلب '.DisplayNumber::reference($order->getKey()),
                'Driver note on order '.DisplayNumber::reference($order->getKey()),
                trim($note),
                trim($note),
                [
                    'assignment_id' => (int) $assignment->getKey(),
                    'driver_id' => (int) $assignment->driver_id,
                    'delivery_status' => $status,
                    'note' => trim($note),
                ],
            );
        }
    }

    public function invoiceChanged(Order $order, Invoice $invoice, string $event): void
    {
        $event = strtolower($event);
        if (! in_array($event, ['issued', 'reissued', 'voided'], true)) {
            return;
        }

        $titlesAr = [
            'issued' => 'تم إصدار فاتورة للطلب ',
            'reissued' => 'تمت إعادة إصدار فاتورة للطلب ',
            'voided' => 'تم إلغاء فاتورة للطلب ',
        ];
        $titlesEn = [
            'issued' => 'Invoice issued for order ',
            'reissued' => 'Invoice reissued for order ',
            'voided' => 'Invoice voided for order ',
        ];

        $invoiceEventKey = 'invoice:'.$invoice->getKey().':'.$event;

        $this->notifyOrderAudience(
            $order,
            'invoice.'.$event,
            $invoiceEventKey,
            'finance.view',
            $titlesAr[$event].DisplayNumber::reference($order->getKey()),
            $titlesEn[$event].DisplayNumber::reference($order->getKey()),
            'الفاتورة '.DisplayNumber::reference($invoice->getKey()).' · '.DisplayNumber::money((float) $invoice->total, $invoice->currency).' '.$invoice->currency,
            'Invoice '.DisplayNumber::reference($invoice->getKey()).' · '.DisplayNumber::money((float) $invoice->total, $invoice->currency).' '.$invoice->currency,
            [
                'invoice_id' => (int) $invoice->getKey(),
                'invoice_number' => (string) DisplayNumber::reference($invoice->getKey()),
                'invoice_status' => (string) $invoice->status,
                'deep_link' => route('admin.invoices.show', ['invoice' => $invoice->getKey()], false),
            ],
        );

        $this->lifecycle->customerOrderChanged($order, 'invoice_updated', [
            'invoice_id' => (int) $invoice->getKey(),
            'invoice_status' => (string) $invoice->status,
            'invoice_event' => $event,
        ]);

        if (in_array($event, ['issued', 'reissued'], true)) {
            $this->whatsAppTransactions->forOrder(
                $order,
                'invoice.issued',
                $invoiceEventKey,
                [
                    'invoice_id' => (int) $invoice->getKey(),
                    'invoice_number' => (string) $invoice->invoice_number,
                    'invoice_total' => (float) $invoice->total,
                    'currency' => (string) $invoice->currency,
                    'invoice_event' => $event,
                ],
            );
        }
    }

    public function paymentStatusChanged(Order $order, Payment $payment, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $eventKey = 'payment:'.$payment->getKey().':'.$from.':'.$to.':'.(string) $payment->updated_at;

        $this->notifyOrderAudience(
            $order,
            'payment.status_changed',
            $eventKey,
            'finance.view',
            'تحديث دفع الطلب '.DisplayNumber::reference($order->getKey()),
            'Payment update for order '.DisplayNumber::reference($order->getKey()),
            'تغيرت حالة الدفع من '.$from.' إلى '.$to,
            'Payment status changed from '.$from.' to '.$to,
            [
                'invoice_id' => $payment->invoice_id === null ? null : (int) $payment->invoice_id,
                'payment_id' => (int) $payment->getKey(),
                'from_payment_status' => $from,
                'payment_status' => $to,
            ],
        );

        $this->lifecycle->customerOrderChanged($order, 'payment_updated', [
            'payment_id' => (int) $payment->getKey(),
            'from_payment_status' => $from,
            'payment_status' => $to,
        ]);

        $transactionalEvent = TransactionalNotificationEngine::paymentEventForStatus($to);
        if ($transactionalEvent !== null) {
            $this->whatsAppTransactions->forOrder(
                $order,
                $transactionalEvent,
                $eventKey,
                [
                    'payment_id' => (int) $payment->getKey(),
                    'payment_status' => $to,
                    'from_payment_status' => $from,
                    'amount' => (float) $payment->amount,
                    'currency' => (string) ($payment->currency ?? $order->currency),
                ],
            );
        }
    }

    private function notifyOrderAudience(
        Order $order,
        string $type,
        string $eventKey,
        string $permission,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        array $extraData = [],
    ): void {
        $channel = strtolower((string) $order->channel);
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            return;
        }

        $storeId = (int) $order->store_id;
        $deepLink = $extraData['deep_link'] ?? $this->orderDeepLink($order);

        $recipients = $this->dashboardOperationalRecipients(
            $permission,
            $storeId,
            $channel,
        );

        foreach ($recipients as $recipient) {
            $dedupeKey = $this->dedupeKey(
                $recipient,
                $type,
                $eventKey,
                $channel,
                $storeId,
            );

            Notification::query()->firstOrCreate(
                [
                    'user_id' => $recipient->id,
                    'dedupe_key' => $dedupeKey,
                ],
                [
                    'channel' => 'in_app',
                    'type' => $type,
                    'title' => $titleAr,
                    'body' => $bodyAr,
                    'title_ar' => $titleAr,
                    'title_en' => $titleEn,
                    'body_ar' => $bodyAr,
                    'body_en' => $bodyEn,
                    'audience' => 'user',
                    'app' => 'dashboard',
                    'target_channel' => $channel,
                    'store_id' => $storeId,
                    'status' => 'published',
                    'published_at' => now(),
                    'data' => [
                        'order_id' => (int) $order->id,
                        'order_number' => (string) DisplayNumber::reference($order->getKey()),
                        'store_id' => $storeId,
                        'channel' => $channel,
                        'deep_link' => $deepLink,
                        ...$extraData,
                    ],
                ],
            );
        }
    }

    /**
     * @return array{name:?string,phone:?string}
     */
    private function orderCustomerIdentity(Order $order): array
    {
        $channel = strtolower((string) $order->channel);
        $customerId = $channel === 'b2b'
            ? (int) $order->b2b_customer_id
            : (int) $order->b2c_customer_id;

        if (! in_array($channel, ['b2b', 'b2c'], true) || $customerId <= 0) {
            return ['name' => null, 'phone' => null];
        }

        $customer = DB::table($channel === 'b2b' ? 'b2b_customers' : 'b2c_customers')
            ->where('id', $customerId)
            ->first(['name', 'phone']);

        $name = trim((string) data_get($customer, 'name', ''));
        $phone = trim((string) data_get($customer, 'phone', ''));

        return [
            'name' => $name === '' ? null : $name,
            'phone' => $phone === '' ? null : $phone,
        ];
    }

    private function dashboardOperationalRecipients(
        string $permission,
        int $storeId,
        string $channel,
    ) {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(function (User $user) use ($permission, $storeId, $channel): bool {
                // SUPER_ADMIN retains platform oversight/reporting access, but Retail
                // operational events belong to the exact Retail Store audience only.
                if ($channel === 'b2c' && $user->hasRole('SUPER_ADMIN')) {
                    return false;
                }

                return in_array(
                    $storeId,
                    $this->tenantScope->allowedStoreIds(
                        $user,
                        $permission,
                        $channel,
                    ),
                    true,
                );
            });
    }

    private function dedupeKey(
        User $recipient,
        string $type,
        string $eventKey,
        string $channel,
        int $storeId,
    ): string {
        return hash('sha256', implode('|', [
            'recipient:'.$recipient->getKey(),
            'channel:'.$channel,
            'store:'.$storeId,
            'type:'.$type,
            'event:'.$eventKey,
        ]));
    }

    private function orderDeepLink(Order $order): string
    {
        $channel = strtolower((string) $order->channel);

        return $channel === 'b2c'
            ? route('admin.b2c.module', ['module' => 'orders', 'store_id' => (int) $order->store_id], false).'#order-'.$order->getKey()
            : route('admin.b2b.module', ['module' => 'orders'], false).'#order-'.$order->getKey();
    }
}
