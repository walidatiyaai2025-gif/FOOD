<?php

namespace App\Services;

use App\Models\FleetCurrentLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FleetLocationService
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function heartbeat(string $actorType, int $actorId, array $attributes): FleetCurrentLocation
    {
        if (in_array($actorType, ['driver', 'van'], true) === false) {
            throw ValidationException::withMessages([
                'actor_type' => ['Actor type must be driver or van.'],
            ]);
        }

        $capturedAt = CarbonImmutable::parse((string) $attributes['captured_at']);
        $values = [
            'vehicle_id' => $attributes['vehicle_id'] ?? ($attributes['van_id'] ?? null),
            'assignment_id' => $attributes['assignment_id'] ?? null,
            'route_key' => $attributes['route_key'] ?? null,
            'store_id' => $attributes['store_id'] ?? null,
            'channel' => $attributes['channel'] ?? null,
            'latitude' => $attributes['latitude'],
            'longitude' => $attributes['longitude'],
            'accuracy' => $attributes['accuracy'] ?? null,
            'speed' => $attributes['speed'] ?? null,
            'heading' => $attributes['heading'] ?? null,
            'captured_at' => $capturedAt,
            'received_at' => now(),
            'source_app' => $attributes['source_app'] ?? 'unknown',
            'app_version' => $attributes['app_version'] ?? null,
            'is_mocked' => $attributes['is_mocked'] ?? null,
        ];

        // Never take a next-key/gap lock before the first insert. With many new
        // actors arriving together, SELECT ... FOR UPDATE on a missing composite
        // unique key can deadlock unrelated actor inserts in InnoDB.
        $current = FleetCurrentLocation::query()
            ->where('actor_type', $actorType)
            ->where('actor_id', $actorId)
            ->first();

        if (! $current instanceof FleetCurrentLocation) {
            try {
                return FleetCurrentLocation::query()->create([
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    ...$values,
                ]);
            } catch (QueryException $exception) {
                // A concurrent heartbeat for the same actor may have won the
                // insert race. Only continue when that authoritative row now
                // exists; otherwise preserve the original database failure.
                $current = FleetCurrentLocation::query()
                    ->where('actor_type', $actorType)
                    ->where('actor_id', $actorId)
                    ->first();

                if (! $current instanceof FleetCurrentLocation) {
                    throw $exception;
                }
            }
        }

        return DB::transaction(function () use ($actorType, $actorId, $values, $capturedAt): FleetCurrentLocation {
            $current = FleetCurrentLocation::query()
                ->where('actor_type', $actorType)
                ->where('actor_id', $actorId)
                ->lockForUpdate()
                ->firstOrFail();

            if (CarbonImmutable::parse((string) $current->captured_at)->greaterThanOrEqualTo($capturedAt)) {
                return $current;
            }

            $current->forceFill($values)->save();

            return $current;
        }, 3);
    }

    public function status(FleetCurrentLocation $location, CarbonImmutable|string|null $at = null): string
    {
        $moment = $at instanceof CarbonImmutable
            ? $at
            : ($at === null ? CarbonImmutable::now() : CarbonImmutable::parse($at));
        $receivedAt = CarbonImmutable::parse((string) $location->received_at);

        if ($receivedAt->greaterThanOrEqualTo($moment->subSeconds(45))) {
            return 'online';
        }

        if ($receivedAt->greaterThanOrEqualTo($moment->subMinutes(3))) {
            return 'stale';
        }

        return 'offline';
    }

    public function pruneBefore(CarbonImmutable|string $cutoff): int
    {
        $before = $cutoff instanceof CarbonImmutable ? $cutoff : CarbonImmutable::parse($cutoff);

        return FleetCurrentLocation::query()
            ->where('received_at', '<', $before)
            ->delete();
    }
}
