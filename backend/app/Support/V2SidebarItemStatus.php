<?php

namespace App\Support;

/**
 * Presentation-only classification from the trusted Sidebar item registry.
 * The caller must pass an item from the canonical already-authorized tree.
 * A status never changes route, permission, store, module or approval access.
 */
final class V2SidebarItemStatus
{
    /** @var list<string> */
    private const ALLOWED = ['new', 'paused', 'beta', 'approval_required'];

    /** @param array<string, mixed> $authorizedItem */
    public function forAuthorizedItem(array $authorizedItem): ?string
    {
        $key = $authorizedItem['key'] ?? null;
        $route = $authorizedItem['route'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($route) || $route === '') {
            return null;
        }

        $definitions = (array) config('admin_sidebar.items', []);
        if (! isset($definitions[$key]) || ! is_array($definitions[$key])) {
            return null;
        }

        $status = $definitions[$key]['status'] ?? null;

        return is_string($status) && in_array($status, self::ALLOWED, true) ? $status : null;
    }
}
