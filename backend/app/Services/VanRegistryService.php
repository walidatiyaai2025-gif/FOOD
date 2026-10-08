<?php

namespace App\Services;

use App\Models\ServiceTerritory;
use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VanRegistryService
{
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

            return VanAssignment::query()->create([
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
