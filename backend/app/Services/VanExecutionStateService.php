<?php

namespace App\Services;

use App\Models\OrderVanAssignment;
use App\Models\OrderVanExecutionState;
use Illuminate\Support\Carbon;

final class VanExecutionStateService
{
    public function initialize(OrderVanAssignment $assignment, Carbon|string|null $at = null): OrderVanExecutionState
    {
        $moment = $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));

        return OrderVanExecutionState::query()->firstOrCreate(
            ['order_van_assignment_id' => $assignment->getKey()],
            [
                'order_id' => $assignment->order_id,
                'van_id' => $assignment->van_id,
                'status' => 'assigned',
                'last_transition_at' => $moment,
                'context' => [
                    'assignment_source' => $assignment->source,
                    'assignment_decision_key' => $assignment->decision_key,
                ],
            ],
        );
    }
}
