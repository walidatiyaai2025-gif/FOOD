<?php

namespace App\Services;

use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use App\Models\VanVisit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VanRegistryService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** @param array<string,mixed> $attributes */
    public function createVan(array $attributes): Van
    {
        return Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => $attributes['code'],
            'plate_number' => $attributes['plate_number'] ?? null,
            'vehicle_type' => $attributes['vehicle_type'] ?? null,
            'status' => $attributes['status'] ?? 'active',
            'capacity_units' => $attributes['capacity_units'] ?? null,
            'capacity_weight' => $attributes['capacity_weight'] ?? null,
            'capabilities' => $attributes['capabilities'] ?? [],
            'home_warehouse_id' => $attributes['home_warehouse_id'] ?? null,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    public function assign(User $actor, Van $van, array $attributes): VanAssignment
    {
        $from = Carbon::parse($attributes['effective_from'] ?? now());
        $until = isset($attributes['effective_until']) ? Carbon::parse($attributes['effective_until']) : null;

        if ($until !== null && $until->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages([
                'effective_until' => ['Effective-until must be after effective-from.'],
            ]);
        }

        $type = $attributes['assignment_type'] ?? 'primary';
        if (in_array($type, ['primary', 'backup'], true) === false) {
            throw ValidationException::withMessages([
                'assignment_type' => ['Assignment type must be primary or backup.'],
            ]);
        }

        $territoryKey = isset($attributes['territory_key']) ? trim((string) $attributes['territory_key']) : null;
        if ($territoryKey !== null && $territoryKey !== '') {
            $territoryExists = ServiceTerritory::query()
                ->where('code', $territoryKey)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $from))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                ->exists();

            if ($territoryExists === false) {
                throw ValidationException::withMessages([
                    'territory_key' => ['Territory must reference an active Service Territory at assignment start.'],
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $van, $attributes, $from, $until, $type, $territoryKey): VanAssignment {
            if ($type === 'primary') {
                $overlap = VanAssignment::query()
                    ->where('van_id', $van->id)
                    ->where('assignment_type', 'primary')
                    ->where('status', 'active')
                    ->where(function ($query) use ($until): void {
                        if ($until === null) {
                            $query->whereNull('effective_until')->orWhere('effective_until', '>', now());
                        } else {
                            $query->whereNull('effective_until')->orWhere('effective_until', '>', $until);
                        }
                    })
                    ->where(function ($query) use ($from): void {
                        $query->whereNull('effective_until')->orWhere('effective_until', '>', $from);
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($overlap) {
                    throw ValidationException::withMessages([
                        'assignment' => ['Van already has an overlapping active primary assignment.'],
                    ]);
                }

                if ($territoryKey !== null && $territoryKey !== '') {
                    $territoryOverlap = VanAssignment::query()
                        ->where('territory_key', $territoryKey)
                        ->where('assignment_type', 'primary')
                        ->where('status', 'active')
                        ->where('van_id', '!=', $van->id)
                        ->where(function ($query) use ($until): void {
                            if ($until === null) {
                                $query->whereNull('effective_until')->orWhere('effective_until', '>', now());
                            } else {
                                $query->whereNull('effective_until')->orWhere('effective_until', '>', $until);
                            }
                        })
                        ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                        ->lockForUpdate()
                        ->exists();

                    if ($territoryOverlap) {
                        throw ValidationException::withMessages([
                            'territory_key' => ['Territory already has an overlapping active primary Van assignment.'],
                        ]);
                    }
                }
            }

            $assignment = VanAssignment::query()->create([
                'public_id' => (string) Str::uuid(),
                'van_id' => $van->id,
                'driver_id' => $attributes['driver_id'] ?? null,
                'representative_user_id' => $attributes['representative_user_id'] ?? null,
                'warehouse_id' => $attributes['warehouse_id'] ?? $van->home_warehouse_id,
                'territory_key' => $territoryKey ?: null,
                'van_pool_key' => $attributes['van_pool_key'] ?? null,
                'assignment_type' => $type,
                'status' => 'active',
                'effective_from' => $from,
                'effective_until' => $until,
                'loaded_work_count' => $attributes['loaded_work_count'] ?? 0,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('van.assignment.created', $actor, $assignment, null, [
                'van_id' => (int) $assignment->van_id,
                'driver_id' => $assignment->driver_id === null ? null : (int) $assignment->driver_id,
                'representative_user_id' => $assignment->representative_user_id === null ? null : (int) $assignment->representative_user_id,
                'assignment_type' => (string) $assignment->assignment_type,
                'territory_key' => $assignment->territory_key,
                'effective_from' => (string) $assignment->effective_from,
                'effective_until' => $assignment->effective_until === null ? null : (string) $assignment->effective_until,
            ]);

            return $assignment;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function updateAssignment(
        User $actor,
        VanAssignment $assignment,
        Van $van,
        array $attributes,
    ): VanAssignment
    {
        $from = Carbon::parse($attributes['effective_from'] ?? $assignment->effective_from);
        $until = array_key_exists('effective_until', $attributes) && $attributes['effective_until'] !== null && $attributes['effective_until'] !== ''
            ? Carbon::parse($attributes['effective_until'])
            : null;

        if ($until !== null && $until->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages([
                'effective_until' => ['Effective-until must be after effective-from.'],
            ]);
        }

        $type = (string) ($attributes['assignment_type'] ?? $assignment->assignment_type);
        if (in_array($type, ['primary', 'backup'], true) === false) {
            throw ValidationException::withMessages([
                'assignment_type' => ['Assignment type must be primary or backup.'],
            ]);
        }

        $territoryKey = array_key_exists('territory_key', $attributes)
            ? trim((string) ($attributes['territory_key'] ?? ''))
            : trim((string) ($assignment->territory_key ?? ''));
        $territoryKey = $territoryKey === '' ? null : $territoryKey;

        if ($territoryKey !== null) {
            $territoryExists = ServiceTerritory::query()
                ->where('code', $territoryKey)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $from))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                ->exists();

            if ($territoryExists === false) {
                throw ValidationException::withMessages([
                    'territory_key' => ['Territory must reference an active Service Territory at assignment start.'],
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $assignment, $van, $attributes, $from, $until, $type, $territoryKey): VanAssignment {
            $locked = VanAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($type === 'primary') {
                $overlap = VanAssignment::query()
                    ->where('id', '!=', $locked->id)
                    ->where('van_id', $van->id)
                    ->where('assignment_type', 'primary')
                    ->where('status', 'active')
                    ->when($until !== null, fn ($query) => $query->where('effective_from', '<', $until))
                    ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                    ->lockForUpdate()
                    ->exists();

                if ($overlap) {
                    throw ValidationException::withMessages([
                        'assignment' => ['Van already has an overlapping active primary assignment.'],
                    ]);
                }

                if ($territoryKey !== null) {
                    $territoryOverlap = VanAssignment::query()
                        ->where('id', '!=', $locked->id)
                        ->where('territory_key', $territoryKey)
                        ->where('assignment_type', 'primary')
                        ->where('status', 'active')
                        ->where('van_id', '!=', $van->id)
                        ->when($until !== null, fn ($query) => $query->where('effective_from', '<', $until))
                        ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                        ->lockForUpdate()
                        ->exists();

                    if ($territoryOverlap) {
                        throw ValidationException::withMessages([
                            'territory_key' => ['Territory already has an overlapping active primary Van assignment.'],
                        ]);
                    }
                }
            }

            $before = $locked->toArray();
            $locked->forceFill([
                'van_id' => $van->id,
                'driver_id' => $attributes['driver_id'] ?? null,
                'representative_user_id' => $attributes['representative_user_id'] ?? null,
                'warehouse_id' => $attributes['warehouse_id'] ?? $van->home_warehouse_id,
                'territory_key' => $territoryKey,
                'van_pool_key' => $attributes['van_pool_key'] ?? null,
                'assignment_type' => $type,
                'effective_from' => $from,
                'effective_until' => $until,
                'loaded_work_count' => $attributes['loaded_work_count'] ?? 0,
            ])->save();

            $this->audit->record(
                'van.assignment.updated',
                $actor,
                $locked,
                $before,
                $locked->fresh()->toArray(),
            );

            return $locked->fresh();
        });
    }

    /**
     * Delete an assignment and assignment-owned operational activity while
     * preserving orders, customers, financial ledgers and immutable audit history.
     *
     * @return array{visits:int,order_van_assignments:int,dispatch_states_reset:int}
     */
    public function deleteAssignment(User $actor, VanAssignment $assignment): array
    {
        return DB::transaction(function () use ($actor, $assignment): array {
            $locked = VanAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $before = $locked->toArray();
            $from = Carbon::parse($locked->effective_from);
            $until = $locked->effective_until === null ? now() : Carbon::parse($locked->effective_until);

            $operatorUserIds = collect([$locked->representative_user_id]);
            if ($locked->driver_id !== null) {
                $driverUserId = DB::table('drivers')->where('id', $locked->driver_id)->value('user_id');
                if ($driverUserId !== null) {
                    $operatorUserIds->push((int) $driverUserId);
                }
            }
            $operatorUserIds = $operatorUserIds->filter()->map(fn ($id) => (int) $id)->unique()->values();

            $visitQuery = VanVisit::query()
                ->where(function ($query) use ($locked, $operatorUserIds, $from, $until): void {
                    $query->where('metadata->van_assignment_id', (int) $locked->id);

                    if ($operatorUserIds->isNotEmpty()) {
                        $query->orWhere(function ($legacy) use ($locked, $operatorUserIds, $from, $until): void {
                            $legacy
                                ->whereNull('metadata->van_assignment_id')
                                ->where('metadata->van_id', (int) $locked->van_id)
                                ->whereIn('actor_user_id', $operatorUserIds->all())
                                ->whereBetween('created_at', [$from, $until]);
                        });
                    }
                })
                ->lockForUpdate();

            $visitIds = $visitQuery->pluck('id');
            $visitCount = $visitIds->count();
            if ($visitIds->isNotEmpty()) {
                VanVisit::query()->whereIn('id', $visitIds)->delete();
            }

            $orderLinks = OrderVanAssignment::query()
                ->where('van_assignment_id', $locked->id)
                ->lockForUpdate()
                ->get(['id', 'order_id', 'decision_key']);

            $orderIds = $orderLinks->pluck('order_id')->unique()->values();
            $decisionKeys = $orderLinks->pluck('decision_key')->filter()->values()->all();
            $dispatchStatesReset = 0;

            if ($orderIds->isNotEmpty()) {
                $states = OrderDispatchState::query()
                    ->whereIn('order_id', $orderIds)
                    ->lockForUpdate()
                    ->get();

                foreach ($states as $state) {
                    $context = is_array($state->context) ? $state->context : [];
                    $contextAssignmentId = (int) (
                        $context['van_assignment_id']
                        ?? ($context['manual_dispatch']['van_assignment_id'] ?? 0)
                    );

                    $belongsToDeletedAssignment = $contextAssignmentId === (int) $locked->id
                        || in_array((string) $state->decision_key, $decisionKeys, true);

                    if (
                        $belongsToDeletedAssignment
                        && (string) $state->current_assignee_type === 'van'
                        && (int) $state->current_assignee_id === (int) $locked->van_id
                    ) {
                        unset($context['van_assignment_id']);
                        if (
                            isset($context['manual_dispatch'])
                            && is_array($context['manual_dispatch'])
                            && (int) ($context['manual_dispatch']['van_assignment_id'] ?? 0) === (int) $locked->id
                        ) {
                            unset($context['manual_dispatch']);
                        }

                        $state->forceFill([
                            'status' => 'awaiting_dispatch',
                            'routing_source' => 'assignment_deleted',
                            'routing_reason' => 'van_assignment_deleted',
                            'current_assignee_type' => null,
                            'current_assignee_id' => null,
                            'decision_key' => null,
                            'context' => $context,
                            'decided_at' => now(),
                        ])->save();

                        $dispatchStatesReset++;
                    }
                }
            }

            $orderLinkCount = $orderLinks->count();
            if ($orderLinks->isNotEmpty()) {
                OrderVanAssignment::query()->whereIn('id', $orderLinks->pluck('id'))->delete();
            }

            $locked->delete();

            $summary = [
                'visits' => $visitCount,
                'order_van_assignments' => $orderLinkCount,
                'dispatch_states_reset' => $dispatchStatesReset,
            ];

            $this->audit->record(
                'van.assignment.deleted',
                $actor,
                $locked,
                $before,
                ['purged' => $summary],
            );

            return $summary;
        });
    }

    public function suspend(Van $van, ?Van $transferTarget = null, ?string $reason = null): Van
    {
        return DB::transaction(function () use ($van, $transferTarget, $reason): Van {
            $locked = Van::query()->lockForUpdate()->findOrFail($van->id);
            $active = VanAssignment::query()
                ->where('van_id', $locked->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            $loaded = $active->sum(fn (VanAssignment $assignment): int => (int) $assignment->loaded_work_count);
            if ($loaded > 0 && $transferTarget === null) {
                throw ValidationException::withMessages([
                    'transfer_target' => ['Loaded work requires an explicit transfer or recovery target before suspension.'],
                ]);
            }

            foreach ($active as $assignment) {
                $assignment->forceFill([
                    'status' => 'ended',
                    'effective_until' => now(),
                    'transferred_to_van_id' => $loaded > 0 ? $transferTarget->id : null,
                    'transfer_reason' => $loaded > 0 ? $reason : null,
                ])->save();
            }

            $locked->forceFill(['status' => 'suspended'])->save();

            return $locked->fresh();
        });
    }

    /** @return Collection<int, VanAssignment> */
    public function effectiveAssignments(Carbon|string|null $at = null)
    {
        $moment = $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));

        return VanAssignment::query()
            ->where('status', 'active')
            ->where('effective_from', '<=', $moment)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $moment))
            ->orderBy('van_id')
            ->orderBy('assignment_type')
            ->get();
    }
}
