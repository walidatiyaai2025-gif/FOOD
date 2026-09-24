<?php

namespace App\Support;

use App\Models\User;

class AdminNavigation
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function for(User $user): array
    {
        $visible = [];

        foreach ((array) config('admin.channels', []) as $channel => $definition) {
            if (! is_string($channel) || ! is_array($definition)) {
                continue;
            }

            if ($this->canAccess($user, $channel)) {
                $visible[$channel] = $definition;
            }
        }

        return $visible;
    }

    public function canAccess(User $user, string $channel): bool
    {
        $definition = config("admin.channels.{$channel}");

        if (! is_array($definition)) {
            return false;
        }

        $globalRoles = array_values(array_filter(
            (array) ($definition['global_roles'] ?? []),
            'is_string',
        ));

        if ($globalRoles !== [] && $user->roles()->whereIn('roles.code', $globalRoles)->exists()) {
            return true;
        }

        $storeRoles = array_values(array_filter(
            (array) ($definition['store_roles'] ?? []),
            'is_string',
        ));

        if ($storeRoles === []) {
            return false;
        }

        return $user->storeRoleAssignments()
            ->whereHas(
                'role',
                fn ($query) => $query->whereIn('roles.code', $storeRoles),
            )
            ->exists();
    }

    public function canUseDashboard(User $user): bool
    {
        return $this->for($user) !== [];
    }
}
