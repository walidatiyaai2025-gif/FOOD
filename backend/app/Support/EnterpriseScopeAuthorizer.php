<?php

namespace App\Support;

use App\Models\User;

final class EnterpriseScopeAuthorizer
{
    public function allows(
        User $user,
        ResolvedTenantContext $context,
        string $permission,
        string $scopeType,
        int $scopeId,
    ): bool {
        if ($permission === '' || $scopeId < 1) {
            return false;
        }

        if ($user->hasRole('SUPER_ADMIN')) {
            return $context->supportAccess || $context->isWholesale();
        }

        if ($context->enterpriseScope === null) {
            return false;
        }

        if ($context->enterpriseScope->contains($scopeType, $scopeId) === false) {
            return false;
        }

        return $user->hasPermission($permission, $context->storeId);
    }

    public function authorize(
        User $user,
        ResolvedTenantContext $context,
        string $permission,
        string $scopeType,
        int $scopeId,
    ): void {
        abort_unless($this->allows($user, $context, $permission, $scopeType, $scopeId), 403);
    }
}
