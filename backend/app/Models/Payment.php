<?php

namespace App\Models;

use App\Services\DashboardOperationalNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Payment extends Model
{
    protected $table = 'payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (Payment $payment): void {
            if (! $payment->wasChanged('status') || $payment->order_id === null) {
                return;
            }

            $from = (string) $payment->getOriginal('status');
            $to = (string) $payment->status;
            $paymentId = (int) $payment->getKey();
            $orderId = (int) $payment->order_id;

            DB::afterCommit(function () use ($paymentId, $orderId, $from, $to): void {
                $fresh = Payment::query()->find($paymentId);
                $order = Order::query()->find($orderId);

                if ($fresh instanceof Payment && $order instanceof Order) {
                    app(DashboardOperationalNotifier::class)
                        ->paymentStatusChanged($order, $fresh, $from, $to);
                }
            });
        });
    }
}
