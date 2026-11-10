<?php

namespace App\Support;

/**
 * Presentation-only label from trusted Sidebar group registry metadata.
 * The caller must supply a group from an already-authorized navigation tree.
 * No status ever grants a route, role, module, tenant or approval.
 */
final class V2SidebarGroupStatus
{
    /** @var list<string> */
    private const ALLOWED = ['new', 'approval_required', 'exceptions', 'beta'];

    /** @param array<string, mixed> $authorizedGroup */
    public function forAuthorizedGroup(array $authorizedGroup): ?string
    {
        $key = $authorizedGroup['key'] ?? null;
        if (! is_string($key) || $key === '' || ($authorizedGroup['children'] ?? []) === []) {
            return null;
        }

        $definitions = (array) config('admin_sidebar.groups', []);
        if (! isset($definitions[$key]) || ! is_array($definitions[$key])) {
            return null;
        }

        $status = $definitions[$key]['status'] ?? null;

        return is_string($status) && in_array($status, self::ALLOWED, true) ? $status : null;
    }
}
