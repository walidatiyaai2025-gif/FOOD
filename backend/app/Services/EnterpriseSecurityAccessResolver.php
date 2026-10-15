<?php

namespace App\Services;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseApprovalLimit;
use App\Models\EnterpriseDelegation;
use App\Models\EnterpriseRoleDataScope;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EnterpriseSecurityAccessResolver
{
    public function __construct(private readonly OrganizationHierarchyService $hierarchy) {}

    public function allows(
        User $actor,
        string $permission,
        string $scopeType,
        ?int $scopeId,
        ?string $amount = null,
        ?string $currency = null,
    ): bool {
        if (! $actor->is_active || $permission === '') {
            return false;
        }

        if ($actor->hasRole('SUPER_ADMIN')) {
            return true;
        }

        if ($this->directAllows($actor, $permission, $scopeType, $scopeId, $amount, $currency)) {
            return true;
        }

        return EnterpriseDelegation::query()
            ->with('delegator')
            ->where('delegate_id', $actor->getKey())
            ->where('permission_code', $permission)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->get()
            ->contains(function (EnterpriseDelegation $delegation) use (
                $permission,
                $scopeType,
                $scopeId,
                $amount,
                $currency,
            ): bool {
                if (! $this->scopeMatches(
                    (string) $delegation->scope_type,
                    $delegation->scope_id === null ? null : (int) $delegation->scope_id,
                    $scopeType,
                    $scopeId,
                )) {
                    return false;
                }

                $delegator = $delegation->delegator;

                return $delegator instanceof User
                    && $this->directAllows($delegator, $permission, $scopeType, $scopeId, $amount, $currency);
            });
    }

    private function directAllows(
        User $actor,
        string $permission,
        string $scopeType,
        ?int $scopeId,
        ?string $amount,
        ?string $currency,
    ): bool {
        $storeId = $scopeType === 'store' ? $scopeId : null;
        if (! $actor->hasPermission($permission, $storeId)) {
            return false;
        }

        return $this->rolesWithPermission($actor, $permission, $storeId)
            ->contains(fn (Role $role): bool => $this->roleAllows(
                $role,
                $permission,
                $scopeType,
                $scopeId,
                $amount,
                $currency,
            ));
    }

    /** @return Collection<int, Role> */
    private function rolesWithPermission(User $actor, string $permission, ?int $storeId): Collection
    {
        $globalRoles = $actor->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereHas('permissions', fn ($query) => $query->where('code', $permission));

        if ($storeId === null) {
            return $globalRoles->get()->unique('id')->values();
        }

        $channel = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->value('store_types.code');

        if (! is_string($channel)) {
            return collect();
        }

        $channel = strtolower($channel);
        $global = $globalRoles
            ->whereIn('roles.code', array_values((array) config("admin.channels.{$channel}.global_roles", [])))
            ->get();

        if ($channel !== 'b2c') {
            return $global->unique('id')->values();
        }

        $storeRoles = $actor->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->with('role.permissions:id,code')
            ->get()
            ->map(fn ($assignment) => $assignment->role)
            ->filter(fn (Role $role): bool => (bool) $role->is_active
                && $role->scope === 'store'
                && $role->permissions->contains('code', $permission));

        return $global->concat($storeRoles)->unique('id')->values();
    }

    private function roleAllows(
        Role $role,
        string $permission,
        string $scopeType,
        ?int $scopeId,
        ?string $amount,
        ?string $currency,
    ): bool {
        $scopeGrants = EnterpriseRoleDataScope::query()
            ->where('role_id', $role->getKey())
            ->where('is_active', true)
            ->get();

        if ($scopeGrants->isNotEmpty() && ! $scopeGrants->contains(
            fn (EnterpriseRoleDataScope $grant): bool => $this->scopeMatches(
                (string) $grant->scope_type,
                $grant->scope_id === null ? null : (int) $grant->scope_id,
                $scopeType,
                $scopeId,
            ),
        )) {
            return false;
        }

        if ($amount === null) {
            return true;
        }

        $limits = EnterpriseApprovalLimit::query()
            ->where('role_id', $role->getKey())
            ->where('permission_code', $permission)
            ->where('is_active', true)
            ->get();

        if ($limits->isEmpty()) {
            return true;
        }

        $currency = strtoupper((string) $currency);

        return $limits->contains(function (EnterpriseApprovalLimit $limit) use (
            $scopeType,
            $scopeId,
            $amount,
            $currency,
        ): bool {
            if (strtoupper((string) $limit->currency) !== $currency) {
                return false;
            }

            if (! $this->scopeMatches(
                (string) $limit->scope_type,
                $limit->scope_id === null ? null : (int) $limit->scope_id,
                $scopeType,
                $scopeId,
            )) {
                return false;
            }

            return $this->toMilliunits($amount) <= $this->toMilliunits((string) $limit->max_amount);
        });
    }

    private function scopeMatches(
        string $grantType,
        ?int $grantId,
        string $targetType,
        ?int $targetId,
    ): bool {
        if ($grantType === 'platform') {
            return $grantId === null;
        }

        if ($grantType === $targetType && $grantId === $targetId) {
            return true;
        }

        if ($targetType !== 'store' || $targetId === null || $grantId === null) {
            return false;
        }

        $scope = $this->hierarchy->scopeForStore($targetId);
        if ($scope === null) {
            return false;
        }

        return match ($grantType) {
            'organization' => $scope['organization_id'] === $grantId,
            'company' => $scope['company_id'] === $grantId,
            'legal_entity' => $scope['legal_entity_id'] === $grantId,
            'business_unit' => $scope['business_unit_id'] === $grantId,
            'store' => $scope['store_id'] === $grantId,
            default => false,
        };
    }

    private function toMilliunits(string $amount): int
    {
        $value = trim($amount);
        if (! preg_match('/^\d{1,15}(?:\.\d{1,3})?$/', $value)) {
            return PHP_INT_MAX;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');

        return ((int) $whole * 1000) + (int) $fraction;
    }
}
