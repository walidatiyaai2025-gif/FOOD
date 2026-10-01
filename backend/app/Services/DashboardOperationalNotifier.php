<?php

namespace App\Services;

use App\Models\DriverAssignment;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DashboardOperationalNotifier
{
    public function __construct(
        private readonly OrderLifecycleNotificationService $lifecycle,
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

        $recipients = User::query()
            ->where('is_active', true)
            ->get()
            ->filter(
                fn (User $user): bool => $user->hasPermission(
                    $permission,
                    $storeId,
                ),
            );

        foreach ($recipients as $recipient) {
            $type = 'customer.registered';
            $eventKey = 'platform-customer-registered:'.$customer->getKey();
            $dedupeKey = hash('sha256', $type.'|'.$eventKey);

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

    public function orderCreated(Order $order): void
    {
        $this->notifyOrderAudience(
            $order,
            'order.created',
            'order-created:'.$order->getKey(),
            'orders.view',
            'طلب جديد '.$order->order_number,
            'New order '.$order->order_number,
            'تم إنشاء طلب جديد بقيمة '.number_format((float) $order->grand_total, 3).' '.$order->currency,
            'A new order was created for '.number_format((float) $order->grand_total, 3).' '.$order->currency,
            ['status' => (string) $order->status],
        );

        $this->lifecycle->customerOrderCreated($order);
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

        $this->notifyOrderAudience(
            $order,
            'order.status_changed',
            'order-status:'.($historyId ?: $order->getKey().':'.$from.':'.$to.':'.(string) $order->updated_at),
            'orders.view',
            'تحديث الطلب '.$order->order_number,
            'Order update '.$order->order_number,
            'تغيرت حالة الطلب من '.$from.' إلى '.$to,
            'Order status changed from '.$from.' to '.$to,
            ['from_status' => $from, 'to_status' => $to],
        );

        $this->lifecycle->orderStatusChanged($order, $from, $to);
    }

    public function driverAssigned(
        Order $order,
        DriverAssignment $assignment,
        ?int $previousDriverId = null,
    ): void {
        $reassigned = $previousDriverId !== null;
        $type = $reassigned ? 'delivery.reassigned' : 'delivery.assigned';

        $this->notifyOrderAudience(
            $order,
            $type,
            $type.':'.$assignment->getKey(),
            'orders.view',
            $reassigned ? 'إعادة تعيين سائق للطلب '.$order->order_number : 'تم تعيين سائق للطلب '.$order->order_number,
            $reassigned ? 'Driver reassigned for order '.$order->order_number : 'Driver assigned to order '.$order->order_number,
            $reassigned ? 'تم نقل الطلب إلى سائق آخر.' : 'تم إسناد الطلب إلى سائق.',
            $reassigned ? 'The order was moved to another driver.' : 'The order was assigned to a driver.',
            [
                'assignment_id' => (int) $assignment->getKey(),
                'driver_id' => (int) $assignment->driver_id,
                'previous_driver_id' => $previousDriverId,
                'delivery_status' => (string) $assignment->status,
            ],
        );

        $this->lifecycle->driverAssigned($order, $assignment, $previousDriverId);
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
            'تحديث التوصيل '.$order->order_number,
            'Delivery update '.$order->order_number,
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
                'ملاحظة سائق على الطلب '.$order->order_number,
                'Driver note on order '.$order->order_number,
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

        $this->notifyOrderAudience(
            $order,
            'invoice.'.$event,
            'invoice:'.$invoice->getKey().':'.$event,
            'finance.view',
            $titlesAr[$event].$order->order_number,
            $titlesEn[$event].$order->order_number,
            'الفاتورة '.$invoice->invoice_number.' · '.number_format((float) $invoice->total, 3).' '.$invoice->currency,
            'Invoice '.$invoice->invoice_number.' · '.number_format((float) $invoice->total, 3).' '.$invoice->currency,
            [
                'invoice_id' => (int) $invoice->getKey(),
                'invoice_number' => (string) $invoice->invoice_number,
                'invoice_status' => (string) $invoice->status,
                'deep_link' => route('admin.invoices.show', ['invoice' => $invoice->getKey()], false),
            ],
        );
    }

    public function paymentStatusChanged(Order $order, Payment $payment, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $this->notifyOrderAudience(
            $order,
            'payment.status_changed',
            'payment:'.$payment->getKey().':'.$from.':'.$to.':'.(string) $payment->updated_at,
            'finance.view',
            'تحديث دفع الطلب '.$order->order_number,
            'Payment update for order '.$order->order_number,
            'تغيرت حالة الدفع من '.$from.' إلى '.$to,
            'Payment status changed from '.$from.' to '.$to,
            [
                'invoice_id' => $payment->invoice_id === null ? null : (int) $payment->invoice_id,
                'payment_id' => (int) $payment->getKey(),
                'from_payment_status' => $from,
                'payment_status' => $to,
            ],
        );
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

        $recipients = User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission($permission, $storeId));

        foreach ($recipients as $recipient) {
            $dedupeKey = hash('sha256', $type.'|'.$eventKey);

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
                        'order_number' => (string) $order->order_number,
                        'store_id' => $storeId,
                        'channel' => $channel,
                        'deep_link' => $deepLink,
                        ...$extraData,
                    ],
                ],
            );
        }
    }

    private function orderDeepLink(Order $order): string
    {
        $channel = strtolower((string) $order->channel);

        return $channel === 'b2c'
            ? route('admin.b2c.module', ['module' => 'orders', 'store_id' => (int) $order->store_id], false).'#order-'.$order->getKey()
            : route('admin.b2b.module', ['module' => 'orders'], false).'#order-'.$order->getKey();
    }
}
