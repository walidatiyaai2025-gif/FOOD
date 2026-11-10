<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Read-only route/action inventory of the canonical, already-authorized
 * Sidebar projection. This report is never an authorization decision.
 */
final class V2SidebarRouteInventory
{
    public function __construct(
        private readonly V2SidebarMetadata $metadata,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forUser(User $user): array
    {
        $entries = [];

        foreach ($this->metadata->forUser($user) as $group) {
            $groupKey = (string) ($group['group_key'] ?? '');

            foreach ((array) ($group['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $this->appendItem($entries, $groupKey, null, $item);
                }
            }
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed> $item
     */
    private function appendItem(array &$entries, string $groupKey, ?string $parent, array $item): void
    {
        $routeName = (string) ($item['route'] ?? '');
        $itemKey = (string) ($item['item_key'] ?? '');
        $registeredRoute = $routeName === '' ? null : Route::getRoutes()->getByName($routeName);

        // Stale route names must never appear in a route/action inventory as if
        // they were a usable, authorized destination.
        if ($itemKey === '' || $registeredRoute === null) {
            return;
        }

        $entries[] = [
            'group_key' => $groupKey,
            'item_key' => $itemKey,
            'parent_item_key' => $parent,
            'label_ar' => $item['label_ar'] ?? null,
            'label_en' => $item['label_en'] ?? null,
            'route' => $routeName,
            'route_uri' => $registeredRoute->uri(),
            'http_methods' => array_values(array_diff($registeredRoute->methods(), ['HEAD'])),
            'controller_action' => $registeredRoute->getActionName(),
            'permission' => $item['permission'] ?? null,
            'permissions' => (array) ($item['permissions'] ?? []),
            'permission_mode' => (string) ($item['permission_mode'] ?? 'all'),
            'scope' => (string) ($item['scope'] ?? 'both'),
            'channel' => $item['channel'] ?? null,
            'module' => $item['module'] ?? null,
            'readiness' => (string) ($item['readiness'] ?? 'route_guarded'),
        ];

        foreach ((array) ($item['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->appendItem($entries, $groupKey, $itemKey, $child);
            }
        }
    }
}
