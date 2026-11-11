<?php

namespace App\Services;

use App\Models\User;
use App\Models\VanVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class VanRuntimeVisitScope
{
    /** @return Builder<VanVisit> */
    public function query(Request $request, User $actor): Builder
    {
        $context = $request->attributes->get('van_runtime_context');
        $vanId = is_array($context) ? (int) ($context['van_id'] ?? 0) : 0;

        return VanVisit::query()
            ->where('customer_type', 'b2b')
            ->where(function (Builder $query) use ($actor, $vanId): void {
                // Preserve legacy untagged visits from this operator, but
                // never follow the same operator into a different Van's
                // explicitly attributed visits after reassignment.
                $query->where(function (Builder $owned) use ($actor, $vanId): void {
                    $owned->where('actor_user_id', $actor->getKey());

                    if ($vanId > 0) {
                        $owned->where(function (Builder $assigned) use ($vanId): void {
                            $assigned->whereNull('metadata->van_id')
                                ->orWhere('metadata->van_id', $vanId);
                        });
                    }
                });

                if ($vanId > 0) {
                    $query->orWhere('metadata->van_id', $vanId);
                }
            });
    }

    public function contains(Request $request, User $actor, VanVisit $visit): bool
    {
        return $this->query($request, $actor)
            ->whereKey($visit->getKey())
            ->exists();
    }

    /** @return array{van_id:int,assignment_id:int,store_id:?int}|null */
    public function context(Request $request): ?array
    {
        $context = $request->attributes->get('van_runtime_context');
        if (! is_array($context)) {
            return null;
        }

        $vanId = (int) ($context['van_id'] ?? 0);
        $assignmentId = (int) ($context['assignment_id'] ?? 0);

        if ($vanId <= 0 || $assignmentId <= 0) {
            return null;
        }

        $storeId = (int) ($context['store_id'] ?? 0);

        return [
            'van_id' => $vanId,
            'assignment_id' => $assignmentId,
            'store_id' => $storeId > 0 ? $storeId : null,
        ];
    }
}
