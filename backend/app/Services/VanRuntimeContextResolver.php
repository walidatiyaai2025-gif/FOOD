<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class VanRuntimeContextResolver
{
    /**
     * @return array{
     *   selected:?array{van_id:int,van_code:string,assignment_id:int,assignment_type:string,driver_id:?int,territory_key:?string,warehouse_id:?int,store_id:?int},
     *   available:list<array{van_id:int,van_code:string,assignment_id:int,assignment_type:string,driver_id:?int,territory_key:?string,warehouse_id:?int,store_id:?int}>,
     *   selection_reason:string
     * }
     */
    public function resolve(User $user, ?int $preferredVanId = null): array
    {
        $driverIds = DB::table('drivers')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('id');

        // Keep seller-store precedence identical for the authorization
        // filter and the store_id returned to the Van app.
        $sellerStoreExpression = 'COALESCE(assignment_warehouses.store_id, home_warehouses.store_id, assignment_drivers.store_id)';

        $assignments = DB::table('van_assignments')
            ->join('vans', 'vans.id', '=', 'van_assignments.van_id')
            ->leftJoin('warehouses as assignment_warehouses', 'assignment_warehouses.id', '=', 'van_assignments.warehouse_id')
            ->leftJoin('warehouses as home_warehouses', 'home_warehouses.id', '=', 'vans.home_warehouse_id')
            ->leftJoin('drivers as assignment_drivers', 'assignment_drivers.id', '=', 'van_assignments.driver_id')
            ->leftJoin('stores as effective_stores', 'effective_stores.id', '=', DB::raw($sellerStoreExpression))
            ->leftJoin('store_types as effective_store_types', 'effective_store_types.id', '=', 'effective_stores.store_type_id')
            ->where(function ($query) use ($sellerStoreExpression): void {
                // Filter legacy Retail/inactive seller contexts before selecting
                // a Van. Never silently fall back past an explicit Retail store.
                $query->whereRaw($sellerStoreExpression.' IS NULL')
                    ->orWhere(function ($query): void {
                        $query->where('effective_stores.is_active', true)
                            ->where('effective_store_types.code', 'B2B');
                    });
            })
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
                DB::raw($sellerStoreExpression.' as store_id'),
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
                'store_id' => $row->store_id === null ? null : (int) $row->store_id,
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
     * active B2B Driver may also use the Van runtime only when the selected
     * effective assignment points back to that same active Wholesale Driver
     * record. A Retail (B2C) Driver never inherits Van execution from an
     * accidental assignment or a stale B2B role on a Retail driver record.
     *
     * @param  array{driver_id:?int}|null  $context
     */
    public function canUseRuntime(User $user, ?array $context = null): bool
    {
        // Retained legacy driver roles must never reactivate a disabled identity.
        // Notification read models sometimes hydrate only users.id and locale.
        // Missing is_active is not evidence of deactivation or authorization:
        // resolve it from the authoritative users row before either access path.
        $isActive = $user->getAttribute('is_active');
        if ($isActive === null) {
            $isActive = User::query()->whereKey($user->getKey())->value('is_active');
        }
        if (! (bool) $isActive) {
            return false;
        }

        if ($user->hasPermission('van.login')) {
            return true;
        }

        // Wholesale execution is Van-only; Retail execution is Driver-only.
        // Never elevate a B2C driver to Van merely because an old Van assignment
        // happens to reference the same user.
        if (! $user->hasRole('B2B_DRIVER')) {
            return false;
        }

        $context ??= $this->resolve($user)['selected'];
        $driverId = $context['driver_id'] ?? null;

        if ($driverId === null) {
            return false;
        }

        // The selected Van seller may be a B2B warehouse while a historical
        // driver's *own* store is Retail. Check both independent boundaries:
        // this fallback cannot convert a B2C store identity into Van access.
        return DB::table('drivers')
            ->join('stores as driver_stores', 'driver_stores.id', '=', 'drivers.store_id')
            ->join('store_types as driver_store_types', 'driver_store_types.id', '=', 'driver_stores.store_type_id')
            ->where('drivers.id', (int) $driverId)
            ->where('drivers.user_id', $user->id)
            ->where('drivers.driver_type', 'b2b')
            ->where('drivers.is_active', true)
            ->where('driver_stores.is_active', true)
            ->where('driver_store_types.code', 'B2B')
            ->exists();
    }
}
