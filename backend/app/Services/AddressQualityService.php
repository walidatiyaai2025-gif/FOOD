<?php

namespace App\Services;

use App\Models\AddressQualityReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AddressQualityService
{
    /** @param array<string,mixed> $attributes */
    public function queue(string $subjectType, int $subjectId, array $attributes = []): AddressQualityReview
    {
        $existing = AddressQualityReview::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->latest('id')
            ->first();

        if ($existing instanceof AddressQualityReview) {
            return $existing;
        }

        return AddressQualityReview::query()->create([
            'public_id' => (string) Str::uuid(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'status' => 'unmapped',
            'quality_class' => $attributes['quality_class'] ?? 'unknown',
            'confidence' => $attributes['confidence'] ?? null,
            'resolution_source' => $attributes['resolution_source'] ?? 'unresolved',
            'reason' => $attributes['reason'] ?? null,
        ]);
    }

    public function confirm(User $actor, AddressQualityReview $review, string $territoryKey, string $reason): AddressQualityReview
    {
        if (trim($territoryKey) === '' || trim($reason) === '') {
            throw ValidationException::withMessages([
                'mapping' => ['Territory and reason are required for an admin-confirmed mapping.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $review, $territoryKey, $reason): AddressQualityReview {
            $locked = AddressQualityReview::query()->lockForUpdate()->findOrFail($review->id);
            $oldStatus = $locked->status;
            $oldTerritory = $locked->territory_key;

            $locked->forceFill([
                'status' => 'confirmed',
                'quality_class' => 'confirmed',
                'territory_key' => $territoryKey,
                'resolution_source' => 'admin_confirmed',
                'reason' => $reason,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();

            $locked->events()->create([
                'event_type' => 'confirmed',
                'old_status' => $oldStatus,
                'new_status' => 'confirmed',
                'old_territory_key' => $oldTerritory,
                'new_territory_key' => $territoryKey,
                'reason' => $reason,
                'actor_id' => $actor->id,
            ]);

            return $locked->fresh('events');
        });
    }

    public function reject(User $actor, AddressQualityReview $review, string $reason): AddressQualityReview
    {
        return $this->transition($actor, $review, 'rejected', $reason);
    }

    public function reopen(User $actor, AddressQualityReview $review, string $reason): AddressQualityReview
    {
        return $this->transition($actor, $review, 'unmapped', $reason);
    }

    private function transition(User $actor, AddressQualityReview $review, string $status, string $reason): AddressQualityReview
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Reason is required.']]);
        }

        return DB::transaction(function () use ($actor, $review, $status, $reason): AddressQualityReview {
            $locked = AddressQualityReview::query()->lockForUpdate()->findOrFail($review->id);
            $oldStatus = $locked->status;
            $oldTerritory = $locked->territory_key;

            $locked->forceFill([
                'status' => $status,
                'territory_key' => $status === 'unmapped' ? null : $locked->territory_key,
                'resolution_source' => $status === 'unmapped' ? 'unresolved' : $locked->resolution_source,
                'reason' => $reason,
                'resolved_by' => $status === 'unmapped' ? null : $actor->id,
                'resolved_at' => $status === 'unmapped' ? null : now(),
            ])->save();

            $locked->events()->create([
                'event_type' => $status,
                'old_status' => $oldStatus,
                'new_status' => $status,
                'old_territory_key' => $oldTerritory,
                'new_territory_key' => $locked->territory_key,
                'reason' => $reason,
                'actor_id' => $actor->id,
            ]);

            return $locked->fresh('events');
        });
    }
}
