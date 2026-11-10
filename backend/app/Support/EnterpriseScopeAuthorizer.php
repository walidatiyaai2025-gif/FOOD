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
        if ($permission === '' || $scopeId < 1
            || ! $user->is_active
            || $context->userId !== (int) $user->getKey()) {
            return false;
        }

        if ($user->hasRole('SUPER_ADMIN')) {
            // Explicit retail support access is scoped to the exact store
            // hierarchy selected by the operator. It must not become a
            // cross-store ERP privilege merely because the role is global.
            if ($context->isRetail()) {
                return $context->supportAccess
                    && $context->enterpriseScope !== null
                    && $context->enterpriseScope->contains($scopeType, $scopeId);
            }

            // Keep the existing wholesale control-plane authority. Tenant
            // context is resolved server-side by TenantContextResolver.
            return $context->isWholesale();
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
