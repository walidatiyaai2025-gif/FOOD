<?php

namespace App\Services;

/**
 * High-risk actions require an approved role and their own operational
 * authorization/approval policy. A per-user ALLOW is not that approval.
 *
 * DENY remains available to remove an inherited permission. Existing
 * role-granted permissions still pass their normal Gate, tenant, module,
 * entitlement and maker/checker checks.
 */
final class SensitivePermissionOverridePolicy
{
    private const ROLE_ONLY_PERMISSIONS = [
        'platform.manage',
        'security.view',
        'security.scopes.manage',
        'security.approval_limits.manage',
        'security.delegations.manage',
        'security.approve',
        'roles.manage',
        'users.view',
        'users.roles.manage',
        'users.status.manage',
        'users.delete',
        'system.update',
        'finance.post',
        'finance.approve',
        'whatsapp.activate',
        'whatsapp.emergency',
        'chatbot.runtime.manage',
        'demo_data.manage',
        'push_settings.manage',
        'sms_settings.manage',
    ];

    public static function requiresIndependentApproval(string $permissionCode): bool
    {
        return in_array($permissionCode, self::ROLE_ONLY_PERMISSIONS, true);
    }
}
