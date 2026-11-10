<?php

namespace App\Support;

use App\Models\User;
use App\Services\AdminSidebarManager;
use Illuminate\Support\Facades\Lang;

/**
 * Metadata projection of canonical, already-authorized Sidebar navigation.
 * Metadata never grants a route, role, tenant, store or module entitlement.
 */
final class V2SidebarMetadata
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly AdminSidebarManager $sidebar,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forUser(User $user): array
    {
        $registry = $this->sidebar->registry();
        $layout = $this->sidebar->layoutData();
        $result = [];

        foreach ($this->navigation->groupsFor($user) as $group) {
            $key = $group['key'];
            if ($key === '' || ! isset($registry['groups'][$key])) {
                continue;
            }

            $items = [];
            foreach ($group['children'] as $node) {
                $item = $this->item($node, $key, $registry['items'], $layout['items']);
                if ($item !== null) {
                    $items[] = $item;
                }
            }

            if ($items === []) {
                continue;
            }

            $definition = (array) $registry['groups'][$key];
            $configured = (array) ($layout['groups'][$key] ?? []);
            $label = (string) ($definition['label'] ?? '');

            $result[] = [
                'group_key' => $key,
                'label_ar' => $this->label($label, $configured['label_ar'] ?? null, 'ar'),
                'label_en' => $this->label($label, $configured['label_en'] ?? null, 'en'),
                'items' => $items,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  array<string, array<string, mixed>>  $layouts
     * @return array<string, mixed>|null
     */
    private function item(array $node, string $groupKey, array $definitions, array $layouts): ?array
    {
        $key = (string) ($node['key'] ?? '');
        $route = $node['route'] ?? null;
        if ($key === '' || ! isset($definitions[$key]) || ! is_string($route) || $route === '') {
            return null;
        }

        $definition = $definitions[$key];
        $configured = (array) ($layouts[$key] ?? []);
        $permissions = array_values(array_filter((array) ($definition['permissions'] ?? []), 'is_string'));
        $children = [];

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $item = $this->item($child, $groupKey, $definitions, $layouts);
                if ($item !== null) {
                    $children[] = $item;
                }
            }
        }

        $module = $definition['module'] ?? null;
        $label = (string) ($definition['label'] ?? '');

        return [
            'group_key' => $groupKey,
            'item_key' => $key,
            'label_ar' => $this->label($label, $configured['label_ar'] ?? null, 'ar'),
            'label_en' => $this->label($label, $configured['label_en'] ?? null, 'en'),
            'permission' => count($permissions) === 1 ? $permissions[0] : null,
            'permissions' => $permissions,
            'permission_mode' => (string) ($definition['mode'] ?? 'all'),
            'scope' => (string) ($definition['scope'] ?? 'both'),
            'channel' => $definition['channel'] ?? null,
            'module' => is_string($module) && $module !== '' ? $module : null,
            // Existing diagnostic routing is not an ERP operational-readiness attestation.
            'readiness' => $route === 'admin.enterprise-modules.show' ? 'diagnostic_only' : 'route_guarded',
            'route' => $route,
            'children' => $children,
        ];
    }

    private function label(string $translationKey, mixed $override, string $locale): ?string
    {
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        if ($translationKey === '' || ! Lang::hasForLocale($translationKey, $locale)) {
            return null;
        }

        $label = Lang::get($translationKey, [], $locale);

        return is_string($label) ? $label : null;
    }
}
