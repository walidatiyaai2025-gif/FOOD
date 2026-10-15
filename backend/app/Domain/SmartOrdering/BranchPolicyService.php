<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BranchPolicyService
{
    public function __construct(
        private readonly SmartOrderingRuntimePolicy $runtime,
        private readonly Request $request,
    ) {}

    public function branch(string $publicId): SmartOrderingBranch
    {
        $branch = SmartOrderingBranch::query()
            ->with('site')
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->firstOrFail();

        $validStore = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', (int) $branch->store_id)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->exists();

        abort_unless($validStore, 404);

        return $branch;
    }

    public function assertAccepting(SmartOrderingBranch $branch): void
    {
        abort_unless((bool) $branch->is_active, 404);
        $this->runtime->assertRequest(
            $branch->site()->firstOrFail(),
            Module::ORDERING,
            $this->request,
            $branch,
        );
        abort_unless((bool) $branch->accepting_orders, 409, 'This branch is not accepting orders.');
    }

    public function scheduledFor(
        SmartOrderingBranch $branch,
        string $mode,
        ?string $requested,
    ): CarbonImmutable {
        $this->assertAccepting($branch);
        $this->assertMode($branch, $mode);

        $timezone = trim((string) $branch->timezone) ?: 'Asia/Kuwait';
        $now = CarbonImmutable::now($timezone);
        $scheduled = $requested === null || trim($requested) === ''
            ? $now
            : CarbonImmutable::parse($requested, $timezone);

        if ($scheduled->lt($now->subMinutes(2))) {
            throw ValidationException::withMessages([
                'scheduled_for' => ['Scheduled time cannot be in the past.'],
            ]);
        }

        if ($requested !== null && trim($requested) !== '') {
            abort_unless((bool) $branch->allow_scheduled, 409, 'Scheduled orders are disabled for this branch.');

            $maximum = $now->addDays(max(1, (int) $branch->max_schedule_days));
            abort_if($scheduled->gt($maximum), 409, 'Scheduled time exceeds the branch pre-order window.');

            if ($mode === 'pickup') {
                $minimumPickup = $now->addMinutes(max(0, (int) $branch->pickup_lead_minutes));
                abort_if($scheduled->lt($minimumPickup), 409, 'Pickup time is earlier than the branch preparation lead time.');
            }
        }

        $closedDates = array_values(array_filter(
            (array) $branch->holiday_closed_dates,
            static fn (string $value): bool => $value !== '',
        ));
        abort_if(in_array($scheduled->format('Y-m-d'), $closedDates, true), 409, 'This branch is closed on the selected date.');

        $hours = DB::table('smart_ordering_branch_hours')
            ->where('branch_id', (int) $branch->getKey())
            ->where('day_of_week', $scheduled->dayOfWeek)
            ->first();

        if ($hours === null) {
            return $scheduled;
        }

        abort_if((bool) $hours->is_closed, 409, 'This branch is closed at the selected time.');

        if ($hours->opens_at === null || $hours->closes_at === null) {
            return $scheduled;
        }

        $opens = CarbonImmutable::parse($scheduled->format('Y-m-d').' '.$hours->opens_at, $timezone);
        $closes = CarbonImmutable::parse($scheduled->format('Y-m-d').' '.$hours->closes_at, $timezone);
        if ($closes->lte($opens)) {
            $closes = $closes->addDay();
            if ($scheduled->lt($opens)) {
                $opens = $opens->subDay();
                $closes = $closes->subDay();
            }
        }

        abort_unless($scheduled->betweenIncluded($opens, $closes), 409, 'This branch is closed at the selected time.');

        return $scheduled;
    }

    public function deliveryZone(
        SmartOrderingBranch $branch,
        float $latitude,
        float $longitude,
    ): object {
        $zones = DB::table('smart_ordering_delivery_zones')
            ->where('branch_id', (int) $branch->getKey())
            ->where('is_active', true)
            ->orderBy('radius_km')
            ->get();

        foreach ($zones as $zone) {
            $distance = $this->distanceKm(
                $latitude,
                $longitude,
                (float) $zone->center_latitude,
                (float) $zone->center_longitude,
            );

            if ($distance <= (float) $zone->radius_km + 0.0001) {
                $zone->distance_km = round($distance, 3);

                return $zone;
            }
        }

        abort(409, 'Delivery address is outside this branch service area.');
    }

    public function table(SmartOrderingBranch $branch, string $tablePublicId, string $token): object
    {
        $table = DB::table('smart_ordering_tables')
            ->where('public_id', $tablePublicId)
            ->where('branch_id', (int) $branch->getKey())
            ->where('is_active', true)
            ->first();

        abort_unless($table !== null, 404, 'Dining table is not available.');
        abort_unless(
            hash_equals((string) $table->token_hash, hash('sha256', $token)),
            404,
            'Dining table token is invalid.',
        );

        return $table;
    }

    private function assertMode(SmartOrderingBranch $branch, string $mode): void
    {
        $module = match ($mode) {
            'dine_in' => Module::DINE_IN,
            'pickup' => Module::PICKUP,
            'delivery' => Module::DELIVERY,
            default => null,
        };
        abort_unless($module instanceof Module, 422, 'Fulfillment mode is not available for this branch.');

        $supported = (bool) $branch->supports_delivery;
        if ($module === Module::DINE_IN) {
            $supported = (bool) $branch->supports_dine_in;
        } elseif ($module === Module::PICKUP) {
            $supported = (bool) $branch->supports_pickup;
        }
        abort_unless($supported, 422, 'Fulfillment mode is not available for this branch.');
        $this->runtime->assertRequest(
            $branch->site()->firstOrFail(),
            $module,
            $this->request,
            $branch,
        );
    }

    private function distanceKm(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $earthRadius = 6371.0088;
        $latDelta = deg2rad($latB - $latA);
        $lngDelta = deg2rad($lngB - $lngA);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }
}
