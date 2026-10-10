<?php

namespace App\Services;

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
    public function __construct(private readonly AuditLogger $audit) {}

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
    public function updateAssignment(User $actor, VanAssignment $assignment, array $attributes): VanAssignment
    {
        $from = Carbon::parse($attributes['effective_from']);
        $until = filled($attributes['effective_until'] ?? null)
            ? Carbon::parse((string) $attributes['effective_until'])
            : null;

        if ($until !== null && $until->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages([
                'effective_until' => ['Effective-until must be after effective-from.'],
            ]);
        }

        $type = (string) ($attributes['assignment_type'] ?? $assignment->assignment_type);
        if (! in_array($type, ['primary', 'backup'], true)) {
            throw ValidationException::withMessages([
                'assignment_type' => ['Assignment type must be primary or backup.'],
            ]);
        }

        $status = (string) ($attributes['status'] ?? $assignment->status);
        if (! in_array($status, ['active', 'ended'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Assignment status must be active or ended.'],
            ]);
        }

        $vanId = (int) ($attributes['van_id'] ?? $assignment->van_id);
        $vanExists = Van::query()->whereKey($vanId)->where('status', 'active')->exists();
        if (! $vanExists) {
            throw ValidationException::withMessages([
                'van_id' => ['Van must reference an active Van.'],
            ]);
        }

        $territoryKey = filled($attributes['territory_key'] ?? null)
            ? trim((string) $attributes['territory_key'])
            : null;

        if ($territoryKey !== null) {
            $territoryExists = ServiceTerritory::query()
                ->where('code', $territoryKey)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $from))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                ->exists();

            if (! $territoryExists) {
                throw ValidationException::withMessages([
                    'territory_key' => ['Territory must reference an active Service Territory at assignment start.'],
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $assignment, $attributes, $from, $until, $type, $status, $territoryKey, $vanId): VanAssignment {
            $locked = VanAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($type === 'primary' && $status === 'active') {
                $overlap = VanAssignment::query()
                    ->whereKeyNot($locked->id)
                    ->where('van_id', $vanId)
                    ->where('assignment_type', 'primary')
                    ->where('status', 'active')
                    ->when($until !== null, fn ($query) => $query->where('effective_from', '<', $until))
                    ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                    ->exists();

                if ($overlap) {
                    throw ValidationException::withMessages([
                        'assignment' => ['Van already has an overlapping active primary assignment.'],
                    ]);
                }

                if ($territoryKey !== null) {
                    $territoryOverlap = VanAssignment::query()
                        ->whereKeyNot($locked->id)
                        ->where('territory_key', $territoryKey)
                        ->where('assignment_type', 'primary')
                        ->where('status', 'active')
                        ->where('van_id', '!=', $vanId)
                        ->when($until !== null, fn ($query) => $query->where('effective_from', '<', $until))
                        ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                        ->exists();

                    if ($territoryOverlap) {
                        throw ValidationException::withMessages([
                            'territory_key' => ['Territory already has an overlapping active primary Van assignment.'],
                        ]);
                    }
                }
            }

            $before = $locked->only([
                'van_id',
                'driver_id',
                'representative_user_id',
                'warehouse_id',
                'territory_key',
                'van_pool_key',
                'assignment_type',
                'status',
                'effective_from',
                'effective_until',
                'loaded_work_count',
            ]);

            $locked->forceFill([
                'van_id' => $vanId,
                'driver_id' => $attributes['driver_id'] ?? null,
                'representative_user_id' => $attributes['representative_user_id'] ?? null,
                'warehouse_id' => $attributes['warehouse_id'] ?? null,
                'territory_key' => $territoryKey,
                'van_pool_key' => filled($attributes['van_pool_key'] ?? null) ? trim((string) $attributes['van_pool_key']) : null,
                'assignment_type' => $type,
                'status' => $status,
                'effective_from' => $from,
                'effective_until' => $until,
                'loaded_work_count' => (int) ($attributes['loaded_work_count'] ?? 0),
            ])->save();

            $this->audit->record('van.assignment.updated', $actor, $locked, $before, $locked->only(array_keys($before)));

            return $locked->fresh();
        });
    }

    /**
     * Delete an assignment and only the operational records explicitly attributed to it
     * inside its own effective window. Canonical orders, invoices and finance ledgers are
     * preserved; dispatch state is reset instead of deleting the order itself.
     *
     * @return array{visits:int,dispatch_assignments:int,dispatch_states:int,fleet_locations:int}
     */
    public function deleteAssignmentWithOperations(User $actor, VanAssignment $assignment): array
    {
        return DB::transaction(function () use ($actor, $assignment): array {
            $locked = VanAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $before = $locked->toArray();
            $from = Carbon::parse($locked->effective_from);
            $until = $locked->effective_until === null
                ? now()
                : Carbon::parse($locked->effective_until);

            $visits = VanVisit::query()
                ->where('metadata->van_assignment_id', $locked->id)
                ->whereBetween('created_at', [$from, $until])
                ->delete();

            $dispatchRows = DB::table('order_van_assignments')
                ->where('van_assignment_id', $locked->id)
                ->whereBetween('assigned_at', [$from, $until])
                ->get(['id', 'order_id', 'decision_key']);

            $orderIds = $dispatchRows->pluck('order_id')->map(static fn ($id): int => (int) $id)->unique()->values();
            $decisionKeys = $dispatchRows->pluck('decision_key')->filter()->map(static fn ($key): string => (string) $key)->values();

            $dispatchAssignments = DB::table('order_van_assignments')
                ->whereIn('id', $dispatchRows->pluck('id'))
                ->delete();

            $dispatchStates = 0;
            if ($orderIds->isNotEmpty()) {
                $dispatchStates = DB::table('order_dispatch_states')
                    ->whereIn('order_id', $orderIds)
                    ->where('current_assignee_type', 'van')
                    ->where('current_assignee_id', $locked->van_id)
                    ->where(function ($query) use ($locked, $decisionKeys): void {
                        $query->where('context->van_assignment_id', $locked->id);
                        if ($decisionKeys->isNotEmpty()) {
                            $query->orWhereIn('decision_key', $decisionKeys->all());
                        }
                    })
                    ->update([
                        'status' => 'awaiting_dispatch',
                        'routing_mode' => null,
                        'routing_source' => null,
                        'routing_reason' => 'van_assignment_deleted',
                        'current_assignee_type' => null,
                        'current_assignee_id' => null,
                        'decision_key' => null,
                        'context' => null,
                        'decided_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            $fleetLocations = DB::table('fleet_current_locations')
                ->where('actor_type', 'van')
                ->where('actor_id', $locked->van_id)
                ->where('assignment_id', $locked->id)
                ->whereBetween('captured_at', [$from, $until])
                ->delete();

            $counts = [
                'visits' => $visits,
                'dispatch_assignments' => $dispatchAssignments,
                'dispatch_states' => $dispatchStates,
                'fleet_locations' => $fleetLocations,
            ];

            $this->audit->record('van.assignment.deleted', $actor, $locked, $before, [
                'purged_operations' => $counts,
            ]);

            $locked->delete();

            return $counts;
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
