<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BranchCapacityService
{
    private const TERMINAL_STATUSES = ['COMPLETED', 'CANCELLED'];

    public function admit(
        SmartOrderingBranch $branch,
        string $mode,
        CarbonImmutable $scheduledFor,
    ): CarbonImmutable {
        $locked = SmartOrderingBranch::query()
            ->whereKey($branch->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $scheduledUtc = $scheduledFor->utc();
        $maximum = max(0, (int) $locked->max_active_orders);

        if ($maximum > 0) {
            $windowMinutes = max(1, (int) $locked->capacity_window_minutes);
            $from = $scheduledUtc->subMinutes($windowMinutes);
            $to = $scheduledUtc->addMinutes($windowMinutes);

            $active = DB::table('smart_ordering_order_contexts')
                ->where('branch_id', (int) $locked->getKey())
                ->whereNotIn('lifecycle_status', self::TERMINAL_STATUSES)
                ->whereBetween('scheduled_for', [$from, $to])
                ->count();

            abort_if(
                $active >= $maximum,
                409,
                'This branch has reached its order capacity for the selected time.',
            );
        }

        return $scheduledUtc->addMinutes($this->promiseMinutes($locked, $mode));
    }

    private function promiseMinutes(SmartOrderingBranch $branch, string $mode): int
    {
        return max(0, match ($mode) {
            'dine_in' => (int) $branch->dine_in_promise_minutes,
            'delivery' => (int) $branch->delivery_promise_minutes,
            default => (int) $branch->pickup_promise_minutes,
        });
    }
}
