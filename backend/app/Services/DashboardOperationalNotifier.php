<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class DashboardOperationalNotifier
{
    public function orderCreated(Order $order): void
    {
        $this->notifyOrderAudience(
            $order,
            'order.created',
            'طلب جديد '.$order->order_number,
            'New order '.$order->order_number,
            'تم إنشاء طلب جديد بقيمة '.number_format((float) $order->grand_total, 3).' '.$order->currency,
            'A new order was created for '.number_format((float) $order->grand_total, 3).' '.$order->currency,
            ['status' => (string) $order->status],
        );
    }

    public function orderStatusChanged(Order $order, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $this->notifyOrderAudience(
            $order,
            'order.status_changed',
            'تحديث الطلب '.$order->order_number,
            'Order update '.$order->order_number,
            'تغيرت حالة الطلب من '.$from.' إلى '.$to,
            'Order status changed from '.$from.' to '.$to,
            ['from_status' => $from, 'to_status' => $to],
        );
    }

    public function deliveryChanged(Order $order, string $status): void
    {
        $this->notifyOrderAudience(
            $order,
            'delivery.status_changed',
            'تحديث التوصيل '.$order->order_number,
            'Delivery update '.$order->order_number,
            'حالة التوصيل الحالية: '.$status,
            'Current delivery status: '.$status,
            ['delivery_status' => $status],
        );
    }

    private function notifyOrderAudience(
        Order $order,
        string $type,
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

        $globalRoles = array_values((array) config("admin.channels.{$channel}.global_roles", []));
        $storeRoles = array_values((array) config("admin.channels.{$channel}.store_roles", []));
        $storeId = (int) $order->store_id;

        $recipients = User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($globalRoles, $storeRoles, $storeId): void {
                if ($globalRoles !== []) {
                    $query->whereHas('roles', fn (Builder $roles) => $roles
                        ->whereIn('roles.code', $globalRoles)
                        ->where('roles.is_active', true));
                } else {
                    $query->whereRaw('1 = 0');
                }

                if ($storeRoles !== []) {
                    $query->orWhereHas('storeRoleAssignments', fn (Builder $assignments) => $assignments
                        ->where('store_id', $storeId)
                        ->whereHas('role', fn (Builder $roles) => $roles
                            ->whereIn('roles.code', $storeRoles)
                            ->where('roles.is_active', true)));
                }
            })
            ->get(['users.id']);

        foreach ($recipients as $recipient) {
            Notification::query()->create([
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
                'target_channel' => 'all',
                'user_id' => $recipient->id,
                'status' => 'published',
                'published_at' => now(),
                'data' => [
                    'order_id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'store_id' => $storeId,
                    'channel' => $channel,
                    ...$extraData,
                ],
            ]);
        }
    }
}
