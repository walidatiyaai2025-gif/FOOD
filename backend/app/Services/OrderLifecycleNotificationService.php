<?php

namespace App\Services;

use App\Jobs\DispatchPushNotification;
use App\Models\DriverAssignment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderVanAssignment;
use App\Support\DisplayNumber;
use Illuminate\Support\Facades\DB;

final class OrderLifecycleNotificationService
{
    public function customerOrderCreated(Order $order): void
    {
        $this->notifyCustomer(
            $order,
            'order.created',
            'customer-order-created:'.$order->getKey(),
            'تم استلام طلبك '.DisplayNumber::reference($order->getKey()),
            'Order received '.DisplayNumber::reference($order->getKey()),
            'تم استلام طلبك بنجاح وسنبدأ متابعته.',
            'Your order was received successfully and is now being tracked.',
            ['status' => (string) $order->status],
        );
    }

    public function orderStatusChanged(Order $order, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $copy = $this->statusCopy($order, $to);
        $historyId = DB::table('order_status_history')
            ->where('order_id', $order->getKey())
            ->where('to_status', $to)
            ->orderByDesc('id')
            ->value('id');
        $eventKey = 'order-status:'.($historyId ?: $order->getKey().':'.$from.':'.$to.':'.(string) $order->updated_at);

        $this->notifyCustomer(
            $order,
            'order.status_changed',
            'customer-'.$eventKey,
            $copy['title_ar'],
            $copy['title_en'],
            $copy['body_ar'],
            $copy['body_en'],
            [
                'from_status' => $from,
                'status' => $to,
                'to_status' => $to,
            ],
        );

        $channel = strtolower((string) $order->channel);
        if ($channel === 'b2b') {
            $dispatchAssigneeType = strtolower((string) DB::table('order_dispatch_states')
                ->where('order_id', $order->getKey())
                ->value('current_assignee_type'));

            if ($dispatchAssigneeType === 'driver') {
                $driverAssignment = $to === 'cancelled'
                    ? $this->latestAssignment($order)
                    : $this->activeAssignment($order);
                if ($driverAssignment instanceof DriverAssignment) {
                    $this->notifyDriverUser(
                        $order,
                        (int) $driverAssignment->driver_id,
                        'order.status_changed',
                        'driver-'.$eventKey,
                        $to === 'cancelled'
                            ? 'تم إلغاء الطلب '.DisplayNumber::reference($order->getKey())
                            : 'تحديث الطلب '.DisplayNumber::reference($order->getKey()),
                        $to === 'cancelled'
                            ? 'Order cancelled '.DisplayNumber::reference($order->getKey())
                            : 'Order update '.DisplayNumber::reference($order->getKey()),
                        $to === 'cancelled'
                            ? 'تم إلغاء الطلب ولم يعد متاحاً للتنفيذ.'
                            : 'حالة الطلب الآن: '.$to,
                        $to === 'cancelled'
                            ? 'The order was cancelled and is no longer actionable.'
                            : 'Order status is now: '.$to,
                        [
                            'assignment_id' => (int) $driverAssignment->getKey(),
                            'from_status' => $from,
                            'status' => $to,
                            'to_status' => $to,
                            'access_revoked' => $to === 'cancelled',
                        ],
                    );
                }

                return;
            }

            $assignment = $to === 'cancelled'
                ? $this->latestVanAssignment($order)
                : $this->activeVanAssignment($order);

            if ($assignment instanceof OrderVanAssignment) {
                $actionRequired = $to === 'failed';
                $this->notifyVanUsers(
                    $order,
                    $assignment,
                    $actionRequired ? 'van.action_required' : 'order.status_changed',
                    'van-'.$eventKey,
                    $actionRequired
                        ? 'إجراء مطلوب للطلب '.DisplayNumber::reference($order->getKey())
                        : ($to === 'cancelled'
                            ? 'تم إلغاء الطلب '.DisplayNumber::reference($order->getKey())
                            : 'تحديث الطلب '.DisplayNumber::reference($order->getKey())),
                    $actionRequired
                        ? 'Action required '.DisplayNumber::reference($order->getKey())
                        : ($to === 'cancelled'
                            ? 'Order cancelled '.DisplayNumber::reference($order->getKey())
                            : 'Order update '.DisplayNumber::reference($order->getKey())),
                    $actionRequired
                        ? 'تعذر إتمام التوصيل. افتح الطلب لمراجعة السبب والإجراء التالي.'
                        : ($to === 'cancelled'
                            ? 'تم إلغاء الطلب ولم يعد متاحاً للتنفيذ.'
                            : 'حالة الطلب الآن: '.$to),
                    $actionRequired
                        ? 'Delivery could not be completed. Open the order to review the reason and next action.'
                        : ($to === 'cancelled'
                            ? 'The order was cancelled and is no longer actionable.'
                            : 'Order status is now: '.$to),
                    [
                        'order_van_assignment_id' => (int) $assignment->getKey(),
                        'van_id' => (int) $assignment->van_id,
                        'from_status' => $from,
                        'status' => $to,
                        'to_status' => $to,
                        'action_required' => $actionRequired,
                        'access_revoked' => $to === 'cancelled',
                    ],
                );
            }

            return;
        }

        if ($channel !== 'b2c') {
            return;
        }

        $assignment = $to === 'cancelled'
            ? $this->latestAssignment($order)
            : $this->activeAssignment($order);
        if ($assignment instanceof DriverAssignment) {
            $this->notifyDriverUser(
                $order,
                (int) $assignment->driver_id,
                'order.status_changed',
                'driver-'.$eventKey,
                $to === 'cancelled'
                    ? 'تم إلغاء الطلب '.DisplayNumber::reference($order->getKey())
                    : 'تحديث الطلب '.DisplayNumber::reference($order->getKey()),
                $to === 'cancelled'
                    ? 'Order cancelled '.DisplayNumber::reference($order->getKey())
                    : 'Order update '.DisplayNumber::reference($order->getKey()),
                $to === 'cancelled'
                    ? 'تم إلغاء الطلب ولم يعد متاحاً للتنفيذ.'
                    : 'حالة الطلب الآن: '.$to,
                $to === 'cancelled'
                    ? 'The order was cancelled and is no longer actionable.'
                    : 'Order status is now: '.$to,
                [
                    'assignment_id' => (int) $assignment->getKey(),
                    'from_status' => $from,
                    'status' => $to,
                    'to_status' => $to,
                    'access_revoked' => $to === 'cancelled',
                ],
            );
        }
    }

    public function driverAssigned(
        Order $order,
        DriverAssignment $assignment,
        ?int $previousDriverId = null,
        bool $manualB2bDispatch = false,
    ): void {
        $channel = strtolower((string) $order->channel);
        if ($channel !== 'b2c' && ! ($channel === 'b2b' && $manualB2bDispatch)) {
            return;
        }

        $this->notifyDriverUser(
            $order,
            (int) $assignment->driver_id,
            'delivery.assigned',
            'driver-assigned:'.$assignment->getKey(),
            'طلب جديد للتوصيل '.DisplayNumber::reference($order->getKey()),
            'New delivery '.DisplayNumber::reference($order->getKey()),
            'تم إسناد طلب جديد إليك. افتح الطلب لمراجعة تفاصيل التوصيل.',
            'A new order was assigned to you. Open it to review delivery details.',
            [
                'assignment_id' => (int) $assignment->getKey(),
                'delivery_status' => (string) $assignment->status,
            ],
        );

        if ($previousDriverId !== null && $previousDriverId !== (int) $assignment->driver_id) {
            $this->notifyDriverUser(
                $order,
                $previousDriverId,
                'delivery.reassigned_away',
                'driver-reassigned-away:'.$assignment->getKey().':'.$previousDriverId,
                'تم سحب الطلب '.DisplayNumber::reference($order->getKey()),
                'Order reassigned '.DisplayNumber::reference($order->getKey()),
                'تم نقل الطلب إلى سائق آخر ولم يعد متاحاً للتنفيذ من حسابك.',
                'The order was reassigned to another driver and is no longer actionable for you.',
                [
                    'assignment_id' => (int) $assignment->getKey(),
                    'delivery_status' => 'reassigned',
                    'access_revoked' => true,
                ],
            );
        }

        $this->notifyCustomer(
            $order,
            $previousDriverId === null ? 'delivery.assigned' : 'delivery.reassigned',
            'customer-driver-assigned:'.$assignment->getKey(),
            'تم تعيين سائق للطلب '.DisplayNumber::reference($order->getKey()),
            'Driver assigned '.DisplayNumber::reference($order->getKey()),
            $previousDriverId === null
                ? 'تم تعيين سائق لطلبك وسيظهر لك أي تحديث جديد فور حدوثه.'
                : 'تم تغيير السائق المسؤول عن توصيل طلبك.',
            $previousDriverId === null
                ? 'A driver has been assigned to your order. You will receive further updates automatically.'
                : 'The driver responsible for your order has changed.',
            [
                'assignment_id' => (int) $assignment->getKey(),
                'driver_id' => (int) $assignment->driver_id,
                'previous_driver_id' => $previousDriverId,
                'delivery_status' => (string) $assignment->status,
            ],
        );
    }

    public function driverUnassigned(
        Order $order,
        DriverAssignment $assignment,
        ?string $reason = null,
    ): void {
        if (! $this->driverRuntimeOwnsOrder($order, (int) $assignment->driver_id)) {
            return;
        }

        $eventKey = 'driver-unassigned:'.$assignment->getKey().':'.(string) $assignment->updated_at;

        $this->notifyDriverUser(
            $order,
            (int) $assignment->driver_id,
            'delivery.unassigned',
            $eventKey,
            'تم سحب الطلب '.DisplayNumber::reference($order->getKey()),
            'Order removed '.DisplayNumber::reference($order->getKey()),
            'لم يعد هذا الطلب معيناً لك.',
            'This order is no longer assigned to you.',
            [
                'assignment_id' => (int) $assignment->getKey(),
                'delivery_status' => 'unassigned',
                'reason' => $reason,
                'access_revoked' => true,
            ],
        );

        $this->notifyCustomer(
            $order,
            'delivery.unassigned',
            'customer-'.$eventKey,
            'تحديث سائق الطلب '.DisplayNumber::reference($order->getKey()),
            'Driver update '.DisplayNumber::reference($order->getKey()),
            'تم إلغاء إسناد السائق الحالي للطلب وسيتم إشعارك عند تعيين سائق جديد.',
            'The current driver assignment was removed. You will be notified when a new driver is assigned.',
            [
                'assignment_id' => (int) $assignment->getKey(),
                'delivery_status' => 'unassigned',
                'reason' => $reason,
            ],
        );
    }

    public function deliveryStatusChanged(
        Order $order,
        DriverAssignment $assignment,
        string $from,
        string $to,
        ?string $note = null,
    ): void {
        if (! $this->driverRuntimeOwnsOrder($order, (int) $assignment->driver_id)) {
            return;
        }

        // These assignment transitions also change the authoritative order status.
        // orderStatusChanged() emits the customer-visible push for them, so avoid
        // showing the customer two notifications for one logical state change.
        if (in_array($to, ['out_for_delivery', 'delivered', 'failed'], true)) {
            return;
        }

        $eventKey = 'delivery-status:'.$assignment->getKey().':'.$from.':'.$to.':'.(string) $assignment->updated_at;

        $copy = match ($to) {
            'accepted' => [
                'title_ar' => 'السائق قبل الطلب '.DisplayNumber::reference($order->getKey()),
                'title_en' => 'Driver accepted '.DisplayNumber::reference($order->getKey()),
                'body_ar' => 'السائق قبل مهمة التوصيل.',
                'body_en' => 'The driver accepted the delivery assignment.',
            ],
            'picked_up' => [
                'title_ar' => 'تم استلام الطلب للتوصيل',
                'title_en' => 'Order picked up for delivery',
                'body_ar' => 'السائق استلم الطلب ويستعد للتوصيل.',
                'body_en' => 'The driver picked up your order and is preparing to deliver it.',
            ],
            'out_for_delivery' => [
                'title_ar' => 'طلبك في الطريق',
                'title_en' => 'Your order is on the way',
                'body_ar' => 'السائق في طريقه إليك الآن.',
                'body_en' => 'Your driver is on the way now.',
            ],
            'delivered' => [
                'title_ar' => 'تم تسليم طلبك',
                'title_en' => 'Order delivered',
                'body_ar' => 'تم تسليم الطلب بنجاح.',
                'body_en' => 'Your order was delivered successfully.',
            ],
            'failed' => [
                'title_ar' => 'تعذر توصيل الطلب',
                'title_en' => 'Delivery could not be completed',
                'body_ar' => 'تعذر إتمام التوصيل. راجع حالة الطلب لمعرفة آخر تحديث.',
                'body_en' => 'Delivery could not be completed. Open the order for the latest update.',
            ],
            default => [
                'title_ar' => 'تحديث التوصيل '.DisplayNumber::reference($order->getKey()),
                'title_en' => 'Delivery update '.DisplayNumber::reference($order->getKey()),
                'body_ar' => 'حالة التوصيل الآن: '.$to,
                'body_en' => 'Delivery status is now: '.$to,
            ],
        };

        $this->notifyCustomer(
            $order,
            'delivery.status_changed',
            'customer-'.$eventKey,
            $copy['title_ar'],
            $copy['title_en'],
            $copy['body_ar'],
            $copy['body_en'],
            [
                'assignment_id' => (int) $assignment->getKey(),
                'driver_id' => (int) $assignment->driver_id,
                'from_delivery_status' => $from,
                'delivery_status' => $to,
            ],
        );
    }

    public function vanAssigned(
        Order $order,
        OrderVanAssignment $assignment,
        bool $reassigned = false,
    ): void {
        if (strtolower((string) $order->channel) !== 'b2b'
            || (int) $assignment->order_id !== (int) $order->getKey()) {
            return;
        }

        $this->notifyVanUsers(
            $order,
            $assignment,
            $reassigned ? 'van.delivery.reassigned' : 'van.delivery.assigned',
            ($reassigned ? 'van-reassigned:' : 'van-assigned:').$assignment->getKey(),
            $reassigned
                ? 'تم نقل الطلب إلى المركبة الحالية '.DisplayNumber::reference($order->getKey())
                : 'طلب جديد للمركبة '.DisplayNumber::reference($order->getKey()),
            $reassigned
                ? 'Order reassigned to this Van '.DisplayNumber::reference($order->getKey())
                : 'New Van delivery '.DisplayNumber::reference($order->getKey()),
            'افتح الطلب لمراجعة تفاصيل العميل والتنفيذ.',
            'Open the order to review customer and execution details.',
            [
                'order_van_assignment_id' => (int) $assignment->getKey(),
                'van_id' => (int) $assignment->van_id,
                'delivery_status' => 'assigned',
            ],
        );
    }

    public function vanAssignmentRevoked(
        Order $order,
        OrderVanAssignment $assignment,
        string $reason = 'reassigned',
    ): void {
        if (strtolower((string) $order->channel) !== 'b2b'
            || (int) $assignment->order_id !== (int) $order->getKey()) {
            return;
        }

        $this->notifyVanUsers(
            $order,
            $assignment,
            'van.delivery.reassigned_away',
            'van-revoked:'.$assignment->getKey().':'.(string) $assignment->updated_at,
            'تم سحب الطلب '.DisplayNumber::reference($order->getKey()),
            'Order removed '.DisplayNumber::reference($order->getKey()),
            'لم يعد هذا الطلب تابعاً للمركبة الحالية.',
            'This order is no longer assigned to the current Van.',
            [
                'order_van_assignment_id' => (int) $assignment->getKey(),
                'van_id' => (int) $assignment->van_id,
                'delivery_status' => (string) $assignment->status,
                'reason' => $reason,
                'access_revoked' => true,
            ],
        );
    }

    private function notifyCustomer(
        Order $order,
        string $type,
        string $eventKey,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        array $extraData = [],
    ): void {
        $userId = $this->customerUserId($order);
        if ($userId === null) {
            return;
        }

        $this->publish(
            $order,
            $userId,
            'customer',
            $type,
            $eventKey,
            $titleAr,
            $titleEn,
            $bodyAr,
            $bodyEn,
            $extraData,
        );
    }

    private function driverRuntimeOwnsOrder(Order $order, int $driverId): bool
    {
        $channel = strtolower((string) $order->channel);
        if ($channel === 'b2c') {
            return true;
        }
        if ($channel !== 'b2b') {
            return false;
        }

        return DB::table('order_dispatch_states')
            ->where('order_id', $order->getKey())
            ->where('status', 'assigned')
            ->where('current_assignee_type', 'driver')
            ->where('current_assignee_id', $driverId)
            ->exists();
    }

    private function notifyDriverUser(
        Order $order,
        int $driverId,
        string $type,
        string $eventKey,
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

        $driver = DB::table('drivers')
            ->where('id', $driverId)
            ->where('is_active', true)
            ->first(['user_id', 'driver_type', 'store_id']);
        if ($driver === null || $driver->user_id === null) {
            return;
        }

        if (strtolower((string) $driver->driver_type) !== $channel) {
            return;
        }

        $driverStoreId = $driver->store_id === null
            ? null
            : (int) $driver->store_id;
        $orderStoreId = (int) $order->store_id;
        if ($driverStoreId !== $orderStoreId) {
            return;
        }

        $this->publish(
            $order,
            (int) $driver->user_id,
            'driver',
            $type,
            $eventKey,
            $titleAr,
            $titleEn,
            $bodyAr,
            $bodyEn,
            $extraData,
        );
    }

    private function notifyVanUsers(
        Order $order,
        OrderVanAssignment $assignment,
        string $type,
        string $eventKey,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        array $extraData = [],
    ): void {
        foreach ($this->vanRecipientUserIds($assignment) as $userId) {
            $this->publish(
                $order,
                $userId,
                'van',
                $type,
                $eventKey,
                $titleAr,
                $titleEn,
                $bodyAr,
                $bodyEn,
                $extraData,
            );
        }
    }

    /** @return list<int> */
    private function vanRecipientUserIds(OrderVanAssignment $assignment): array
    {
        if ($assignment->van_assignment_id === null) {
            return [];
        }

        $runtime = DB::table('van_assignments')
            ->leftJoin('drivers', 'drivers.id', '=', 'van_assignments.driver_id')
            ->where('van_assignments.id', $assignment->van_assignment_id)
            ->where('van_assignments.van_id', $assignment->van_id)
            ->first([
                'van_assignments.representative_user_id',
                'drivers.user_id as driver_user_id',
            ]);

        if ($runtime === null) {
            return [];
        }

        return collect([
            $runtime->representative_user_id,
            $runtime->driver_user_id,
        ])
            ->filter(fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function publish(
        Order $order,
        int $userId,
        string $app,
        string $type,
        string $eventKey,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        array $extraData,
    ): void {
        $targetChannel = strtolower((string) $order->channel);
        if (! in_array($targetChannel, ['b2b', 'b2c'], true)) {
            return;
        }

        $storeId = (int) $order->store_id;
        $dedupeKey = hash('sha256', implode('|', [
            'recipient:'.$userId,
            'app:'.$app,
            'channel:'.$targetChannel,
            'store:'.$storeId,
            'type:'.$type,
            'event:'.$eventKey,
        ]));
        $deepLink = $this->deepLink($order, $app, $extraData);

        $notification = Notification::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'dedupe_key' => $dedupeKey,
            ],
            [
                'channel' => 'both',
                'type' => $type,
                'title' => $titleAr,
                'body' => $bodyAr,
                'title_ar' => $titleAr,
                'title_en' => $titleEn,
                'body_ar' => $bodyAr,
                'body_en' => $bodyEn,
                'audience' => 'user',
                'app' => $app,
                'target_channel' => $targetChannel,
                'store_id' => $storeId,
                'status' => 'published',
                'published_at' => now(),
                'data' => [
                    ...$extraData,
                    'order_id' => (int) $order->getKey(),
                    'order_number' => (string) DisplayNumber::reference($order->getKey()),
                    'store_id' => $storeId,
                    'channel' => $targetChannel,
                    'status' => (string) $order->status,
                    'event_key' => $eventKey,
                    'event_at' => now()->toAtomString(),
                    'order_updated_at' => $order->updated_at?->toAtomString(),
                    'state_version' => hash(
                        'sha256',
                        implode('|', [
                            'recipient:'.$userId,
                            'channel:'.$targetChannel,
                            'store:'.$storeId,
                            'event:'.$eventKey,
                            'updated:'.(string) $order->updated_at,
                        ]),
                    ),
                    'route' => match ($app) {
                        'customer' => 'order',
                        'van' => 'order_detail',
                        default => 'assignment',
                    },
                    'deep_link' => $deepLink,
                ],
            ],
        );

        if ($notification->wasRecentlyCreated) {
            DispatchPushNotification::dispatch((int) $notification->getKey())->afterCommit();
        }
    }

    private function deepLink(Order $order, string $app, array $extraData): string
    {
        $channel = strtolower((string) $order->channel);
        $storeId = (int) $order->store_id;
        $orderId = (int) $order->getKey();

        if ($app === 'driver') {
            $assignmentId = (int) ($extraData['assignment_id'] ?? 0);

            return '/driver/'.$channel.'/deliveries?'.http_build_query([
                'channel' => $channel,
                'store_id' => $storeId,
                'order_id' => $orderId,
                'assignment_id' => $assignmentId > 0 ? $assignmentId : null,
            ]);
        }

        if ($app === 'van') {
            return '/van/orders/'.$orderId.'?'.http_build_query([
                'channel' => 'b2b',
                'store_id' => $storeId,
                'order_van_assignment_id' => $extraData['order_van_assignment_id'] ?? null,
            ]);
        }

        $commerceChannel = $channel === 'b2b' ? 'wholesale' : 'retail';
        $path = $channel === 'b2b'
            ? '/b2b/orders/'.$orderId
            : '/orders/'.$orderId.'/track';

        return $path.'?'.http_build_query([
            'channel' => $commerceChannel,
            'store_id' => $storeId,
        ]);
    }

    private function customerUserId(Order $order): ?int
    {
        if ($order->b2b_customer_id !== null) {
            $id = DB::table('b2b_customers')
                ->where('id', $order->b2b_customer_id)
                ->value('user_id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        if ($order->b2c_customer_id !== null) {
            $id = DB::table('b2c_customers')
                ->where('id', $order->b2c_customer_id)
                ->value('user_id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        $customerId = $order->getAttribute('customer_id');
        if ($customerId === null) {
            return null;
        }

        $id = DB::table('customers')
            ->where('id', $customerId)
            ->value('user_id');

        return $id === null ? null : (int) $id;
    }

    private function activeAssignment(Order $order): ?DriverAssignment
    {
        return DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned'])
            ->latest('id')
            ->first();
    }

    private function latestAssignment(Order $order): ?DriverAssignment
    {
        return DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first();
    }

    private function activeVanAssignment(Order $order): ?OrderVanAssignment
    {
        return OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    private function latestVanAssignment(Order $order): ?OrderVanAssignment
    {
        return OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first();
    }

    /** @return array{title_ar:string,title_en:string,body_ar:string,body_en:string} */
    private function statusCopy(Order $order, string $status): array
    {
        return match ($status) {
            'pending' => [
                'title_ar' => 'تم استلام طلبك '.DisplayNumber::reference($order->getKey()),
                'title_en' => 'Order received '.DisplayNumber::reference($order->getKey()),
                'body_ar' => 'طلبك قيد المراجعة.',
                'body_en' => 'Your order is being reviewed.',
            ],
            'confirmed' => [
                'title_ar' => 'تم تأكيد الطلب '.DisplayNumber::reference($order->getKey()),
                'title_en' => 'Order confirmed '.DisplayNumber::reference($order->getKey()),
                'body_ar' => 'تم تأكيد طلبك بنجاح.',
                'body_en' => 'Your order has been confirmed.',
            ],
            'preparing' => [
                'title_ar' => 'جاري تجهيز طلبك',
                'title_en' => 'Your order is being prepared',
                'body_ar' => 'بدأ تجهيز الطلب '.DisplayNumber::reference($order->getKey()).'.',
                'body_en' => 'Preparation started for order '.DisplayNumber::reference($order->getKey()).'.',
            ],
            'ready' => [
                'title_ar' => 'طلبك جاهز للتوصيل',
                'title_en' => 'Your order is ready',
                'body_ar' => 'تم تجهيز الطلب وهو جاهز للمرحلة التالية.',
                'body_en' => 'Your order is prepared and ready for the next step.',
            ],
            'out_for_delivery' => [
                'title_ar' => 'طلبك في الطريق',
                'title_en' => 'Your order is on the way',
                'body_ar' => 'طلبك في طريقه للتسليم.',
                'body_en' => 'Your order is on the way for delivery.',
            ],
            'delivered' => [
                'title_ar' => 'تم تسليم طلبك',
                'title_en' => 'Order delivered',
                'body_ar' => 'تم تسليم الطلب '.DisplayNumber::reference($order->getKey()).' بنجاح.',
                'body_en' => 'Order '.DisplayNumber::reference($order->getKey()).' was delivered successfully.',
            ],
            'cancelled' => [
                'title_ar' => 'تم إلغاء الطلب '.DisplayNumber::reference($order->getKey()),
                'title_en' => 'Order cancelled '.DisplayNumber::reference($order->getKey()),
                'body_ar' => 'تم إلغاء الطلب. افتح التفاصيل لمعرفة آخر حالة.',
                'body_en' => 'The order was cancelled. Open it for the latest details.',
            ],
            'failed' => [
                'title_ar' => 'تعذر إتمام توصيل الطلب',
                'title_en' => 'Delivery issue',
                'body_ar' => 'تعذر إتمام التوصيل للطلب '.DisplayNumber::reference($order->getKey()).'.',
                'body_en' => 'Delivery could not be completed for order '.DisplayNumber::reference($order->getKey()).'.',
            ],
            default => [
                'title_ar' => 'تحديث الطلب '.DisplayNumber::reference($order->getKey()),
                'title_en' => 'Order update '.DisplayNumber::reference($order->getKey()),
                'body_ar' => 'حالة الطلب الآن: '.$status,
                'body_en' => 'Order status is now: '.$status,
            ],
        };
    }
}
