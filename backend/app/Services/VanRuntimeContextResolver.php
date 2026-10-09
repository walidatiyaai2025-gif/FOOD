<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VanRuntimeContextResolver
{
    /**
     * @return array{
     *   selected:?array{van_id:int,van_code:string,assignment_id:int,assignment_type:string,driver_id:?int,territory_key:?string,warehouse_id:?int},
     *   available:list<array{van_id:int,van_code:string,assignment_id:int,assignment_type:string,driver_id:?int,territory_key:?string,warehouse_id:?int}>,
     *   selection_reason:string
     * }
     */
    public function resolve(User $user, ?int $preferredVanId = null): array
    {
        $driverIds = DB::table('drivers')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('id');

        $assignments = DB::table('van_assignments')
            ->join('vans', 'vans.id', '=', 'van_assignments.van_id')
            ->where('van_assignments.status', 'active')
            ->where('vans.status', 'active')
            ->where('van_assignments.effective_from', '<=', now())
            ->where(fn ($query) => $query
                ->whereNull('van_assignments.effective_until')
                ->orWhere('van_assignments.effective_until', '>', now()))
            ->where(function ($query) use ($user, $driverIds): void {
                $query->where('van_assignments.representative_user_id', $user->id);
                if ($driverIds->isNotEmpty()) {
                    $query->orWhereIn('van_assignments.driver_id', $driverIds);
                }
            })
            ->orderByRaw("CASE WHEN van_assignments.assignment_type = 'primary' THEN 0 ELSE 1 END")
            ->orderByDesc('van_assignments.effective_from')
            ->orderBy('van_assignments.id')
            ->get([
                'van_assignments.id as assignment_id',
                'van_assignments.van_id',
                'van_assignments.assignment_type',
                'van_assignments.driver_id',
                'van_assignments.territory_key',
                'van_assignments.warehouse_id',
                'vans.code as van_code',
            ]);

        $available = $assignments
            ->map(static fn ($row): array => [
                'van_id' => (int) $row->van_id,
                'van_code' => (string) $row->van_code,
                'assignment_id' => (int) $row->assignment_id,
                'assignment_type' => (string) $row->assignment_type,
                'driver_id' => $row->driver_id === null ? null : (int) $row->driver_id,
                'territory_key' => $row->territory_key === null ? null : (string) $row->territory_key,
                'warehouse_id' => $row->warehouse_id === null ? null : (int) $row->warehouse_id,
            ])
            ->values();

        if ($preferredVanId !== null) {
            $preferred = $available->firstWhere('van_id', $preferredVanId);

            return [
                'selected' => $preferred,
                'available' => $available->all(),
                'selection_reason' => $preferred === null ? 'preferred_van_not_authorized' : 'explicit_van',
            ];
        }

        $primary = $available->filter(static fn (array $context): bool => $context['assignment_type'] === 'primary');

        return [
            'selected' => ($primary->first() ?? $available->first()),
            'available' => $available->all(),
            'selection_reason' => $primary->isNotEmpty() ? 'deterministic_primary' : ($available->isNotEmpty() ? 'single_or_backup' : 'no_effective_assignment'),
        ];
    }

    /**
     * Compatibility bridge for Driver accounts assigned directly to a Van.
     *
     * Explicit van.login remains the primary authorization mechanism. Legacy
     * production assignments can pre-date the VAN_OPERATOR role linkage, so an
     * active B2B/B2C Driver may also use the Van runtime only when the selected
     * effective assignment points back to that same active Driver record.
     *
     * @param array{driver_id:?int}|null $context
     */
    public function canUseRuntime(User $user, ?array $context = null): bool
    {
        if ($user->hasPermission('van.login')) {
            return true;
        }

        if (! $user->hasRole('B2B_DRIVER') && ! $user->hasRole('B2C_DRIVER')) {
            return false;
        }

        $context ??= $this->resolve($user)['selected'];
        $driverId = $context['driver_id'] ?? null;

        if ($driverId === null) {
            return false;
        }

        return DB::table('drivers')
            ->where('id', (int) $driverId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }
}
