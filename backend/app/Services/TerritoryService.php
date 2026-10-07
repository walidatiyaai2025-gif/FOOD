<?php

namespace App\Services;

use App\Models\AddressTerritoryResolution;
use App\Models\ServiceTerritory;
use App\Models\TerritoryGeometry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TerritoryService
{
    /**
     * @return array<string,mixed>
     */
    public function resolveAddress(
        string $addressType,
        string $addressKey,
        ?float $latitude,
        ?float $longitude,
        ?int $explicitTerritoryId = null,
        ?int $adminConfirmedTerritoryId = null,
        ?User $actor = null,
        Carbon|string|null $at = null,
    ): array {
        $moment = $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));

        if ($explicitTerritoryId !== null) {
            return $this->persistResolution(
                $addressType,
                $addressKey,
                $latitude,
                $longitude,
                $this->effectiveTerritoryOrFail($explicitTerritoryId, $moment),
                'explicit_override',
                'active explicit address override',
                [],
                $actor,
                $moment,
            );
        }

        if ($latitude !== null && $longitude !== null) {
            $candidates = $this->geometryCandidates($latitude, $longitude, $moment);

            if ($candidates->isNotEmpty()) {
                /** @var ServiceTerritory $winner */
                $winner = $candidates
                    ->sortBy([
                        ['priority', 'desc'],
                        ['code', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->first();

                return $this->persistResolution(
                    $addressType,
                    $addressKey,
                    $latitude,
                    $longitude,
                    $winner,
                    'point_in_polygon',
                    $candidates->count() === 1
                        ? 'single active territory contains the coordinate'
                        : 'highest territory priority wins; code/id provide deterministic tie-break',
                    $candidates->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                    $actor,
                    $moment,
                );
            }
        }

        if ($adminConfirmedTerritoryId !== null) {
            return $this->persistResolution(
                $addressType,
                $addressKey,
                $latitude,
                $longitude,
                $this->effectiveTerritoryOrFail($adminConfirmedTerritoryId, $moment),
                'admin_confirmed_mapping',
                'admin-confirmed mapping used because coordinate resolution was unavailable or unmatched',
                [],
                $actor,
                $moment,
            );
        }

        return $this->persistResolution(
            $addressType,
            $addressKey,
            $latitude,
            $longitude,
            null,
            'unmapped',
            'no explicit override, containing polygon, or admin-confirmed mapping exists',
            [],
            $actor,
            $moment,
        );
    }

    /**
     * @param  array<string, mixed>  $geojson
     */
    public function addGeometry(
        ServiceTerritory $territory,
        array $geojson,
        ?User $actor = null,
        Carbon|string|null $effectiveFrom = null,
        Carbon|string|null $effectiveUntil = null,
    ): TerritoryGeometry {
        $type = (string) ($geojson['type'] ?? '');
        if (in_array($type, ['Polygon', 'MultiPolygon'], true) === false) {
            throw ValidationException::withMessages([
                'geojson.type' => ['Only Polygon and MultiPolygon geometries are supported.'],
            ]);
        }

        $points = $this->flattenPoints($geojson['coordinates'] ?? []);
        if ($points === []) {
            throw ValidationException::withMessages([
                'geojson.coordinates' => ['Geometry must contain valid [longitude, latitude] coordinates.'],
            ]);
        }

        $from = $effectiveFrom instanceof Carbon ? $effectiveFrom : ($effectiveFrom === null ? null : Carbon::parse($effectiveFrom));
        $until = $effectiveUntil instanceof Carbon ? $effectiveUntil : ($effectiveUntil === null ? null : Carbon::parse($effectiveUntil));
        if ($from !== null && $until !== null && $until->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['effective_until' => ['Must be after effective_from.']]);
        }

        $lngs = array_column($points, 0);
        $lats = array_column($points, 1);

        return DB::transaction(function () use ($territory, $geojson, $type, $actor, $from, $until, $lngs, $lats): TerritoryGeometry {
            $latestVersion = (int) TerritoryGeometry::query()
                ->where('service_territory_id', $territory->getKey())
                ->lockForUpdate()
                ->max('version');

            return TerritoryGeometry::query()->create([
                'service_territory_id' => $territory->getKey(),
                'geometry_type' => $type,
                'geojson' => $geojson,
                'min_lat' => min($lats),
                'max_lat' => max($lats),
                'min_lng' => min($lngs),
                'max_lng' => max($lngs),
                'version' => $latestVersion + 1,
                'is_active' => true,
                'effective_from' => $from,
                'effective_until' => $until,
                'created_by' => $actor?->getKey(),
            ]);
        });
    }

    private function effectiveTerritoryOrFail(int $id, Carbon $moment): ServiceTerritory
    {
        $territory = $this->effectiveTerritories($moment)->whereKey($id)->first();

        if (($territory instanceof ServiceTerritory) === false) {
            throw ValidationException::withMessages([
                'territory_id' => ['Selected territory is not active for the requested effective time.'],
            ]);
        }

        return $territory;
    }

    /** @return Collection<int, ServiceTerritory> */
    private function geometryCandidates(float $latitude, float $longitude, Carbon $moment): Collection
    {
        $territories = $this->effectiveTerritories($moment)
            ->whereHas('geometries', function ($query) use ($latitude, $longitude, $moment): void {
                $query->where('is_active', true)
                    ->where('min_lat', '<=', $latitude)
                    ->where('max_lat', '>=', $latitude)
                    ->where('min_lng', '<=', $longitude)
                    ->where('max_lng', '>=', $longitude)
                    ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $moment))
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $moment));
            })
            ->with(['geometries' => function ($query) use ($latitude, $longitude, $moment): void {
                $query->where('is_active', true)
                    ->where('min_lat', '<=', $latitude)
                    ->where('max_lat', '>=', $latitude)
                    ->where('min_lng', '<=', $longitude)
                    ->where('max_lng', '>=', $longitude)
                    ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $moment))
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $moment))
                    ->orderByDesc('version');
            }])
            ->get();

        return $territories
            ->filter(fn (ServiceTerritory $territory): bool => $territory->geometries
                ->contains(function ($geometry) use ($longitude, $latitude): bool {
                    if (($geometry instanceof TerritoryGeometry) === false) {
                        return false;
                    }

                    /** @var array<string, mixed> $geojson */
                    $geojson = $geometry->getAttribute('geojson');

                    return $this->containsPoint($geojson, $longitude, $latitude);
                }))
            ->values();
    }

    private function effectiveTerritories(Carbon $moment)
    {
        return ServiceTerritory::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $moment))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $moment));
    }

    /**
     * @param  array<string, mixed>  $geojson
     */
    private function containsPoint(array $geojson, float $longitude, float $latitude): bool
    {
        $type = (string) ($geojson['type'] ?? '');
        $coordinates = $geojson['coordinates'] ?? [];

        if ($type === 'Polygon') {
            return $this->pointInPolygon($coordinates, $longitude, $latitude);
        }

        if ($type === 'MultiPolygon' && is_array($coordinates)) {
            foreach ($coordinates as $polygon) {
                if ($this->pointInPolygon($polygon, $longitude, $latitude)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pointInPolygon(mixed $polygon, float $x, float $y): bool
    {
        if (is_array($polygon) === false || $polygon === []) {
            return false;
        }

        $outer = $polygon[0] ?? [];
        if ($this->pointInRing($outer, $x, $y) === false) {
            return false;
        }

        foreach (array_slice($polygon, 1) as $hole) {
            if ($this->pointInRing($hole, $x, $y)) {
                return false;
            }
        }

        return true;
    }

    private function pointInRing(mixed $ring, float $x, float $y): bool
    {
        if (is_array($ring) === false || count($ring) < 4) {
            return false;
        }

        $inside = false;
        $j = count($ring) - 1;

        for ($i = 0, $count = count($ring); $i < $count; $i++) {
            $pi = $ring[$i] ?? null;
            $pj = $ring[$j] ?? null;
            if (is_array($pi) === false || is_array($pj) === false || count($pi) < 2 || count($pj) < 2) {
                $j = $i;

                continue;
            }

            $xi = (float) $pi[0];
            $yi = (float) $pi[1];
            $xj = (float) $pj[0];
            $yj = (float) $pj[1];

            $crosses = (($yi > $y) === ($yj <= $y))
                && ($x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: PHP_FLOAT_EPSILON)) + $xi);

            if ($crosses) {
                $inside = $inside === false;
            }

            $j = $i;
        }

        return $inside;
    }

    /** @return list<array{0:float,1:float}> */
    private function flattenPoints(mixed $value): array
    {
        $points = [];
        $walk = function (mixed $node) use (&$walk, &$points): void {
            if (is_array($node) === false) {
                return;
            }

            if (count($node) >= 2 && is_numeric($node[0] ?? null) && is_numeric($node[1] ?? null)) {
                $lng = (float) $node[0];
                $lat = (float) $node[1];
                if ($lng >= -180 && $lng <= 180 && $lat >= -90 && $lat <= 90) {
                    $points[] = [$lng, $lat];
                }

                return;
            }

            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($value);

        return $points;
    }

    /**
     * @param  list<int>  $candidateIds
     * @return array<string, mixed>
     */
    private function persistResolution(
        string $addressType,
        string $addressKey,
        ?float $latitude,
        ?float $longitude,
        ?ServiceTerritory $territory,
        string $source,
        string $reason,
        array $candidateIds,
        ?User $actor,
        Carbon $moment,
    ): array {
        $trace = [
            'order' => ['explicit_override', 'point_in_polygon', 'admin_confirmed_mapping', 'unmapped'],
            'source' => $source,
            'candidate_territory_ids' => $candidateIds,
            'chosen_territory_id' => $territory?->getKey(),
            'chosen_reason' => $reason,
        ];

        $record = AddressTerritoryResolution::query()->create([
            'address_type' => trim($addressType),
            'address_key' => trim($addressKey),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'service_territory_id' => $territory?->getKey(),
            'source' => $source,
            'serviceability_status' => $territory === null ? 'unmapped' : 'serviceable',
            'candidate_territory_ids' => $candidateIds,
            'chosen_reason' => $reason,
            'resolved_by' => $actor?->getKey(),
            'resolved_at' => $moment,
            'trace' => $trace,
        ]);

        return [
            'resolution_id' => (int) $record->getKey(),
            'territory_id' => $territory?->getKey(),
            'territory_code' => $territory?->code,
            'source' => $source,
            'serviceability_status' => $record->serviceability_status,
            'candidate_territory_ids' => $candidateIds,
            'chosen_reason' => $reason,
            'trace' => $trace,
        ];
    }
}
