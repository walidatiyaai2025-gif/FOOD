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

        return VanVisit::query()->where(function (Builder $query) use ($actor, $vanId): void {
            $query->where('actor_user_id', $actor->getKey());

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

    /** @return array{van_id:int,assignment_id:int}|null */
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

        return ['van_id' => $vanId, 'assignment_id' => $assignmentId];
    }
}
