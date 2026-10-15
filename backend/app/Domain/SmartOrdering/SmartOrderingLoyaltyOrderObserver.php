<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SmartOrderingLoyaltyOrderObserver
{
    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status') || strtolower((string) $order->channel) !== 'b2c') {
            return;
        }

        if (! Schema::hasTable('smart_ordering_sites') || ! Schema::hasTable('smart_ordering_loyalty_ledger')) {
            return;
        }

        $site = SmartOrderingSite::query()->where('store_id', (int) $order->store_id)->first();
        if (! $site instanceof SmartOrderingSite) {
            return;
        }

        $runtime = app(SmartOrderingRuntimePolicy::class);
        if (! $runtime->allowsPublic($site, Module::LOYALTY, true, true)) {
            return;
        }

        $orderId = (int) $order->getKey();
        $status = strtolower((string) $order->status);

        DB::afterCommit(static function () use ($site, $orderId, $status): void {
            try {
                $loyalty = app(LoyaltyService::class);
                $branchId = Schema::hasTable('smart_ordering_order_contexts')
                    ? DB::table('smart_ordering_order_contexts')->where('order_id', $orderId)->value('branch_id')
                    : null;

                if (in_array($status, ['completed', 'delivered'], true)) {
                    $loyalty->earnForCompletedOrder(
                        $site->fresh(),
                        $orderId,
                        $branchId === null ? null : (int) $branchId,
                    );
                } elseif (in_array($status, ['cancelled', 'refunded'], true)) {
                    $loyalty->reverseOrder($site->fresh(), $orderId);
                }
            } catch (Throwable $exception) {
                Log::warning('Smart Ordering loyalty lifecycle synchronization failed.', [
                    'site_id' => $site->id,
                    'order_id' => $orderId,
                    'status' => $status,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }
}
