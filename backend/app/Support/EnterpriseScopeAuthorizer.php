<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EnterpriseScopeAuthorizer
{
    public function allows(
        User $user,
        ResolvedTenantContext $context,
        string $permission,
        string $scopeType,
        int $scopeId,
    ): bool {
        if ($permission === '' || $scopeId < 1 || ! $user->is_active) {
            return false;
        }

        if ($user->hasRole('SUPER_ADMIN')) {
            // Bind the privileged Super Admin bypass to its own resolved
            // context. Ordinary Finance maker/checker workflows may evaluate
            // a persisted maker against the checker's scoped context; those
            // actors still require their own effective permission and scope.
            if ($context->userId !== (int) $user->getKey()) {
                return false;
            }
            // Explicit retail support access is scoped to the exact store
            // hierarchy selected by the operator. It must not become a
            // cross-store ERP privilege merely because the role is global.
            if ($context->isRetail()) {
                return $context->supportAccess
                    && $context->enterpriseScope !== null
                    && $context->enterpriseScope->contains($scopeType, $scopeId);
            }

            if (! $context->isWholesale()) {
                return false;
            }

            // Wholesale control-plane scope is not a Retail support session.
            // A direct store target must always belong to the B2B channel.
            if ($scopeType === 'store' && ! DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.id', $scopeId)
                ->where('stores.is_active', true)
                ->where('store_types.code', 'B2B')
                ->exists()) {
                return false;
            }

            // An explicitly selected Wholesale store remains bounded to its
            // hierarchy. An unscoped Wholesale control plane retains access
            // to B2B stores without impersonating a Retail tenant.
            return $context->storeId === null
                || ($context->enterpriseScope !== null
                    && $context->enterpriseScope->contains($scopeType, $scopeId));
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
