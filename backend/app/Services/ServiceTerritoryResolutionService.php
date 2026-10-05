<?php

namespace App\Services;

use App\Models\Address;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ServiceTerritoryResolutionService
{
    public function resolve(Address $address, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        return DB::transaction(function () use ($address, $at): array {
            $override = DB::table('address_territory_overrides')
                ->join('service_territories', 'service_territories.id', '=', 'address_territory_overrides.service_territory_id')
                ->where('address_territory_overrides.address_id', $address->getKey())
                ->where('address_territory_overrides.status', 'active')
                ->where('service_territories.status', 'active')
                ->where(fn ($query) => $query->whereNull('address_territory_overrides.effective_from')->orWhere('address_territory_overrides.effective_from', '<=', $at))
                ->where(fn ($query) => $query->whereNull('address_territory_overrides.effective_until')->orWhere('address_territory_overrides.effective_until', '>', $at))
                ->where(fn ($query) => $query->whereNull('service_territories.effective_from')->orWhere('service_territories.effective_from', '<=', $at))
                ->where(fn ($query) => $query->whereNull('service_territories.effective_until')->orWhere('service_territories.effective_until', '>', $at))
                ->orderByDesc('address_territory_overrides.id')
                ->select(
                    'service_territories.*',
                    'address_territory_overrides.id as override_id',
                )
                ->first();

            if ($override !== null) {
                return $this->commit($address, $override, 'override', [(int) $override->id], [
                    'stage' => 'explicit_override',
                    'override_id' => (int) $override->override_id,
                ]);
            }

            if ($address->latitude !== null && $address->longitude !== null) {
                $candidates = DB::table('service_territories')
                    ->where('country_code', strtoupper((string) $address->country_code))
                    ->where('status', 'active')
                    ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
                    ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $at))
                    ->get()
                    ->filter(fn ($territory): bool => $this->contains(
                        json_decode((string) $territory->geometry, true) ?: [],
                        (float) $address->longitude,
                        (float) $address->latitude,
                    ))
                    ->sortBy([
                        ['priority', 'desc'],
                        ['code', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->values();

                if ($candidates->isNotEmpty()) {
                    $chosen = $candidates->first();

                    return $this->commit($address, $chosen, 'geometry', $candidates->pluck('id')->map(fn ($id) => (int) $id)->all(), [
                        'stage' => 'point_in_polygon',
                        'coordinate' => [
                            'latitude' => (float) $address->latitude,
                            'longitude' => (float) $address->longitude,
                        ],
                        'ordering' => ['priority:desc', 'code:asc', 'id:asc'],
                    ]);
                }
            }

            $mapping = DB::table('territory_admin_mappings')
                ->join('service_territories', 'service_territories.id', '=', 'territory_admin_mappings.service_territory_id')
                ->where('territory_admin_mappings.country_code', strtoupper((string) $address->country_code))
                ->where('territory_admin_mappings.status', 'active')
                ->where('service_territories.status', 'active')
                ->where(fn ($query) => $query->whereNull('territory_admin_mappings.city')->orWhere('territory_admin_mappings.city', $address->city))
                ->where(fn ($query) => $query->whereNull('territory_admin_mappings.area')->orWhere('territory_admin_mappings.area', $address->area))
                ->where(fn ($query) => $query->whereNull('territory_admin_mappings.effective_from')->orWhere('territory_admin_mappings.effective_from', '<=', $at))
                ->where(fn ($query) => $query->whereNull('territory_admin_mappings.effective_until')->orWhere('territory_admin_mappings.effective_until', '>', $at))
                ->where(fn ($query) => $query->whereNull('service_territories.effective_from')->orWhere('service_territories.effective_from', '<=', $at))
                ->where(fn ($query) => $query->whereNull('service_territories.effective_until')->orWhere('service_territories.effective_until', '>', $at))
                ->orderByDesc('territory_admin_mappings.priority')
                ->orderByDesc('service_territories.priority')
                ->orderBy('service_territories.code')
                ->select('service_territories.*', 'territory_admin_mappings.id as mapping_id')
                ->first();

            if ($mapping !== null) {
                return $this->commit($address, $mapping, 'admin_mapping', [(int) $mapping->id], [
                    'stage' => 'admin_confirmed_mapping',
                    'mapping_id' => (int) $mapping->mapping_id,
                ]);
            }

            return $this->commit($address, null, 'exception', [], [
                'stage' => 'unmapped',
                'reason' => $address->latitude === null || $address->longitude === null
                    ? 'coordinates_unavailable_and_no_admin_mapping'
                    : 'no_matching_active_territory',
            ]);
        });
    }

    private function commit(Address $address, ?object $territory, string $source, array $candidateIds, array $decisionTrace): array
    {
        $chosenId = $territory === null ? null : (int) $territory->id;
        $status = $territory === null ? 'unserviceable_exception' : 'serviceable';
        $reason = match ($source) {
            'override' => 'explicit_active_override',
            'geometry' => count($candidateIds) > 1 ? 'highest_priority_overlap_candidate' : 'point_inside_active_territory',
            'admin_mapping' => 'admin_confirmed_mapping',
            default => 'no_resolvable_territory',
        };

        $address->forceFill([
            'resolved_service_territory_id' => $chosenId,
            'territory_resolution_source' => $source,
            'territory_resolved_at' => now(),
            'serviceability_status' => $status,
        ])->save();

        $traceId = DB::table('territory_resolution_traces')->insertGetId([
            'address_id' => $address->getKey(),
            'source' => $source,
            'candidate_territory_ids' => json_encode(array_values($candidateIds), JSON_THROW_ON_ERROR),
            'chosen_service_territory_id' => $chosenId,
            'chosen_reason' => $reason,
            'decision_trace' => json_encode($decisionTrace, JSON_THROW_ON_ERROR),
            'resolved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'address_id' => (int) $address->getKey(),
            'serviceability_status' => $status,
            'source' => $source,
            'territory' => $territory === null ? null : [
                'id' => (int) $territory->id,
                'code' => $territory->code,
                'name_en' => $territory->name_en,
                'name_ar' => $territory->name_ar,
            ],
            'candidate_territory_ids' => array_values($candidateIds),
            'chosen_reason' => $reason,
            'trace_id' => (int) $traceId,
            'decision_trace' => $decisionTrace,
        ];
    }

    private function contains(array $geometry, float $longitude, float $latitude): bool
    {
        $coordinates = $geometry['coordinates'] ?? null;

        if (! is_array($coordinates)) {
            return false;
        }

        if (($geometry['type'] ?? null) === 'Polygon') {
            return $this->pointInPolygon($coordinates, $longitude, $latitude);
        }

        if (($geometry['type'] ?? null) === 'MultiPolygon') {
            foreach ($coordinates as $polygon) {
                if (is_array($polygon) && $this->pointInPolygon($polygon, $longitude, $latitude)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pointInPolygon(array $rings, float $longitude, float $latitude): bool
    {
        if ($rings === [] || ! $this->pointInRing($rings[0], $longitude, $latitude)) {
            return false;
        }

        foreach (array_slice($rings, 1) as $hole) {
            if ($this->pointInRing($hole, $longitude, $latitude)) {
                return false;
            }
        }

        return true;
    }

    private function pointInRing(array $ring, float $longitude, float $latitude): bool
    {
        $inside = false;
        $count = count($ring);

        if ($count < 4) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) ($ring[$i][0] ?? 0);
            $yi = (float) ($ring[$i][1] ?? 0);
            $xj = (float) ($ring[$j][0] ?? 0);
            $yj = (float) ($ring[$j][1] ?? 0);

            $intersects = (($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < (($xj - $xi) * ($latitude - $yi) / (($yj - $yi) ?: PHP_FLOAT_EPSILON)) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
