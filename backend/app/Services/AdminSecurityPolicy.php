<?php

namespace App\Services;

use App\Models\AdminMfaCredential;
use App\Models\User;

final class AdminSecurityPolicy
{
    private const PRIVILEGED_PERMISSIONS = [
        'platform.manage',
        'security.view',
        'security.scopes.manage',
        'security.approval_limits.manage',
        'security.delegations.manage',
        'security.approve',
        'roles.manage',
        'users.roles.manage',
        'users.status.manage',
        'system.update',
    ];

    public function isPrivileged(User $user): bool
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return true;
        }

        return array_intersect(self::PRIVILEGED_PERMISSIONS, $user->effectivePermissionCodes()) !== [];
    }

    public function requiresMfa(User $user): bool
    {
        return (bool) config('security.mfa.enforce_privileged', false) && $this->isPrivileged($user);
    }

    public function hasEnabledMfa(User $user): bool
    {
        return AdminMfaCredential::query()
            ->where('user_id', $user->id)
            ->whereNotNull('enabled_at')
            ->whereNull('revoked_at')
            ->exists();
    }

    /** @return array{enabled:bool,configured:bool,provider:string,missing:list<string>} */
    public function ssoReadiness(): array
    {
        $missing = [];
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'client_id', 'client_secret'] as $key) {
            $value = config('security.sso.'.$key);
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
            $value = config('security.sso.'.$key);
            if (is_string($value) && $value !== '' && ! str_starts_with(strtolower($value), 'https://')) {
                $missing[] = $key.'_https';
            }
        }

        return [
            'enabled' => (bool) config('security.sso.enabled', false),
            'configured' => $missing === [],
            'provider' => (string) config('security.sso.provider', 'enterprise'),
            'missing' => array_values(array_unique($missing)),
        ];
    }
}
