<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdminSidebarManager
{
    /** @return array{groups:array<string,array<string,mixed>>,items:array<string,array<string,mixed>>,protected_items:list<string>,protected_groups:list<string>} */
    public function registry(): array
    {
        $config = (array) config('admin_sidebar', []);

        return [
            'groups' => (array) ($config['groups'] ?? []),
            'items' => (array) ($config['items'] ?? []),
            'protected_items' => array_values(array_filter((array) ($config['protected_items'] ?? []), 'is_string')),
            'protected_groups' => array_values(array_filter((array) ($config['protected_groups'] ?? []), 'is_string')),
        ];
    }

    /** @return array{groups:array<string,array<string,mixed>>,items:array<string,array<string,mixed>>} */
    public function layoutData(): array
    {
        $registry = $this->registry();
        $groups = [];
        foreach ($registry['groups'] as $key => $definition) {
            $groups[$key] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'icon' => (string) ($definition['icon'] ?? '•'),
                'sort_order' => (int) ($definition['order'] ?? 0),
                'is_enabled' => true,
                'label_ar' => null,
                'label_en' => null,
                'configured' => false,
            ];
        }

        $items = [];
        foreach ($registry['items'] as $key => $definition) {
            $items[$key] = [
                'key' => $key,
                'group_key' => (string) ($definition['group'] ?? 'administration'),
                'parent_key' => null,
                'label' => (string) ($definition['label'] ?? $key),
                'sort_order' => (int) ($definition['order'] ?? 0),
                'is_enabled' => true,
                'label_ar' => null,
                'label_en' => null,
                'configured' => false,
                'meta' => $definition,
            ];
        }

        if (! $this->tablesReady()) {
            return ['groups' => $groups, 'items' => $items];
        }

        $protectedGroups = array_fill_keys($registry['protected_groups'], true);
        foreach (DB::table('admin_sidebar_groups')->get() as $row) {
            if (! isset($groups[$row->group_key])) {
                continue;
            }
            $groups[$row->group_key] = array_merge($groups[$row->group_key], [
                'icon' => $row->icon ?: $groups[$row->group_key]['icon'],
                'sort_order' => (int) $row->sort_order,
                'is_enabled' => isset($protectedGroups[$row->group_key]) ? true : (bool) $row->is_enabled,
                'label_ar' => $row->label_ar,
                'label_en' => $row->label_en,
                'configured' => true,
            ]);
        }

        foreach (DB::table('admin_sidebar_items')->get() as $row) {
            if (! isset($items[$row->item_key])) {
                continue;
            }
            $items[$row->item_key] = array_merge($items[$row->item_key], [
                'group_key' => isset($groups[$row->group_key]) ? $row->group_key : $items[$row->item_key]['group_key'],
                'parent_key' => $row->parent_key,
                'sort_order' => (int) $row->sort_order,
                'is_enabled' => (bool) $row->is_enabled,
                'label_ar' => $row->label_ar,
                'label_en' => $row->label_en,
                'configured' => true,
            ]);
        }

        uasort($groups, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['key'], $b['key']));

        return ['groups' => $groups, 'items' => $items];
    }

    /** @return array{role:Role,groups:list<array<string,mixed>>} */
    public function roleMatrix(Role $role): array
    {
        $role->loadMissing('permissions:id,code');
        $permissions = $role->permissions->pluck('code')->filter()->values()->all();
        $layout = $this->layoutData();
        $overrides = collect();

        if ($this->tablesReady()) {
            $overrides = DB::table('admin_sidebar_role_visibility')
                ->where('role_id', $role->id)
                ->get()
                ->keyBy('item_key');
        }

        $grouped = [];
        foreach ($layout['groups'] as $groupKey => $group) {
            $grouped[$groupKey] = $group + ['items' => []];
        }

        foreach ($layout['items'] as $key => $item) {
            $meta = (array) ($item['meta'] ?? []);
            $baseAllowed = $this->roleCanUseItem($role, $meta, $permissions);
            $override = $overrides->get($key);
            $globalEnabled = (bool) $item['is_enabled'] && (bool) ($grouped[$item['group_key']]['is_enabled'] ?? true);
            $visible = $baseAllowed && $globalEnabled && ($override === null || (bool) $override->is_visible);

            $targetGroup = isset($grouped[$item['group_key']]) ? $item['group_key'] : (string) ($meta['group'] ?? 'administration');
            $grouped[$targetGroup]['items'][] = $item + [
                'base_allowed' => $baseAllowed,
                'global_enabled' => $globalEnabled,
                'visible_for_role' => $visible,
                'override' => $override === null ? null : (bool) $override->is_visible,
                'permissions' => array_values(array_filter((array) ($meta['permissions'] ?? []), 'is_string')),
                'permission_mode' => (string) ($meta['mode'] ?? 'all'),
            ];
        }

        foreach ($grouped as &$group) {
            usort($group['items'], static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['key'], $b['key']));
        }
        unset($group);

        return [
            'role' => $role,
            'groups' => array_values(array_filter($grouped, static fn (array $group): bool => $group['items'] !== [])),
        ];
    }

    /** @param list<string> $visibleKeys */
    public function saveRoleVisibility(Role $role, array $visibleKeys): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $registry = $this->registry();
        $knownKeys = array_keys($registry['items']);
        $selected = array_fill_keys(array_values(array_intersect($knownKeys, $visibleKeys)), true);
        $role->loadMissing('permissions:id,code');
        $permissions = $role->permissions->pluck('code')->filter()->values()->all();
        $protected = array_fill_keys($registry['protected_items'], true);
        $now = now();

        DB::transaction(function () use ($role, $registry, $selected, $permissions, $protected, $now): void {
            foreach ($registry['items'] as $key => $meta) {
                if (! $this->roleCanUseItem($role, (array) $meta, $permissions)) {
                    DB::table('admin_sidebar_role_visibility')
                        ->where('role_id', $role->id)
                        ->where('item_key', $key)
                        ->delete();
                    continue;
                }

                $visible = isset($selected[$key]);
                if (isset($protected[$key])) {
                    $visible = true;
                }

                DB::table('admin_sidebar_role_visibility')->updateOrInsert(
                    ['role_id' => $role->id, 'item_key' => $key],
                    ['is_visible' => $visible, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }, 3);
    }

    /**
     * @param array<string,array<string,mixed>> $groups
     * @param array<string,array<string,mixed>> $items
     */
    public function saveLayout(array $groups, array $items): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $registry = $this->registry();
        $protected = array_fill_keys($registry['protected_items'], true);
        $protectedGroups = array_fill_keys($registry['protected_groups'], true);
        $now = now();

        DB::transaction(function () use ($groups, $items, $registry, $protected, $protectedGroups, $now): void {
            foreach ($registry['groups'] as $key => $definition) {
                $input = (array) ($groups[$key] ?? []);
                DB::table('admin_sidebar_groups')->updateOrInsert(
                    ['group_key' => $key],
                    [
                        'label_ar' => $this->nullableText($input['label_ar'] ?? null),
                        'label_en' => $this->nullableText($input['label_en'] ?? null),
                        'icon' => $this->nullableText($input['icon'] ?? null) ?: (string) ($definition['icon'] ?? '•'),
                        'sort_order' => (int) ($input['sort_order'] ?? $definition['order'] ?? 0),
                        'is_enabled' => isset($protectedGroups[$key]) ? true : (bool) ($input['is_enabled'] ?? false),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }

            foreach ($registry['items'] as $key => $definition) {
                $input = (array) ($items[$key] ?? []);
                DB::table('admin_sidebar_items')->updateOrInsert(
                    ['item_key' => $key],
                    [
                        'group_key' => isset($registry['groups'][$input['group_key'] ?? ''])
                            ? (string) $input['group_key']
                            : (string) ($definition['group'] ?? 'administration'),
                        'parent_key' => $this->nullableText($input['parent_key'] ?? null),
                        'label_ar' => $this->nullableText($input['label_ar'] ?? null),
                        'label_en' => $this->nullableText($input['label_en'] ?? null),
                        'sort_order' => (int) ($input['sort_order'] ?? $definition['order'] ?? 0),
                        'is_enabled' => isset($protected[$key]) ? true : (bool) ($input['is_enabled'] ?? false),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }, 3);
    }

    /**
     * Apply global layout and role-specific visibility after canonical RBAC has
     * already produced the authorized navigation. This layer can only hide,
     * rename, regroup, or reorder authorized targets; it can never grant access.
     *
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    public function apply(User $user, array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $layout = $this->layoutData();
        $roleIds = $this->roleIdsForUser($user);
        $visibility = collect();

        if ($this->tablesReady() && $roleIds !== []) {
            $visibility = DB::table('admin_sidebar_role_visibility')
                ->whereIn('role_id', $roleIds)
                ->get()
                ->groupBy('item_key');
        }

        $originalGroups = collect($groups)->keyBy('key');
        $bucket = [];

        foreach ($groups as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                $key = (string) ($item['key'] ?? '');
                if ($key === '' || ! $this->itemVisibleForRoles($key, $roleIds, $visibility)) {
                    continue;
                }

                $itemLayout = $layout['items'][$key] ?? null;
                if ($itemLayout !== null && ! (bool) $itemLayout['is_enabled']) {
                    continue;
                }

                $targetGroup = $itemLayout !== null && (bool) $itemLayout['configured']
                    ? (string) $itemLayout['group_key']
                    : (string) $group['key'];

                $groupLayout = $layout['groups'][$targetGroup] ?? null;
                if ($groupLayout !== null && ! (bool) $groupLayout['is_enabled']) {
                    continue;
                }

                $item['sort_order'] = (int) ($itemLayout['sort_order'] ?? count($bucket[$targetGroup]['items'] ?? []) * 10);
                $item['parent_key'] = $itemLayout !== null && (bool) $itemLayout['configured'] ? $itemLayout['parent_key'] : null;
                $item['children'] = [];
                $this->applyLocalizedLabels($item, $itemLayout);

                if (! isset($bucket[$targetGroup])) {
                    $source = $originalGroups->get($targetGroup);
                    $registryGroup = $layout['groups'][$targetGroup] ?? null;
                    $bucket[$targetGroup] = [
                        'group' => [
                            'key' => $targetGroup,
                            'label' => (string) ($source['label'] ?? $registryGroup['label'] ?? $targetGroup),
                            'icon' => (string) ($registryGroup['icon'] ?? $source['icon'] ?? '•'),
                            'children' => [],
                            'sort_order' => (int) ($registryGroup['sort_order'] ?? 9999),
                        ],
                        'items' => [],
                    ];
                    $this->applyLocalizedLabels($bucket[$targetGroup]['group'], $registryGroup);
                }

                $bucket[$targetGroup]['items'][$key] = $item;
            }
        }

        $result = [];
        foreach ($bucket as $groupKey => $entry) {
            $items = $entry['items'];
            uasort($items, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['key'], $b['key']));

            $childrenByParent = [];
            foreach ($items as $key => $item) {
                $parent = is_string($item['parent_key'] ?? null) ? $item['parent_key'] : null;
                if ($parent !== null && $parent !== $key && isset($items[$parent])) {
                    $childrenByParent[$parent][] = $key;
                }
            }

            $build = function (string $key, array $trail = []) use (&$build, $items, $childrenByParent): array {
                $item = $items[$key];
                if (in_array($key, $trail, true)) {
                    $item['children'] = [];

                    return $item;
                }

                $trail[] = $key;
                $item['children'] = array_map(
                    fn (string $childKey): array => $build($childKey, $trail),
                    $childrenByParent[$key] ?? [],
                );

                return $item;
            };

            $nested = [];
            foreach (array_keys($items) as $key) {
                $parent = is_string($items[$key]['parent_key'] ?? null) ? $items[$key]['parent_key'] : null;
                if ($parent === null || ! isset($items[$parent]) || $parent === $key) {
                    $nested[] = $build($key);
                }
            }

            if ($nested === []) {
                $nested = array_map(fn (string $key): array => $build($key), array_keys($items));
            }

            $group = $entry['group'];
            $group['children'] = $nested;
            $result[] = $group;
        }

        usort($result, static fn (array $a, array $b): int => ($a['sort_order'] ?? 9999) <=> ($b['sort_order'] ?? 9999) ?: strcmp($a['key'], $b['key']));

        return $result;
    }

    /** @param list<string> $permissions */
    private function roleCanUseItem(Role $role, array $meta, array $permissions): bool
    {
        if (in_array($role->code, array_values((array) ($meta['exclude_roles'] ?? [])), true)) {
            return false;
        }

        $scope = (string) ($meta['scope'] ?? 'both');
        if ($scope !== 'both' && $role->scope !== $scope) {
            return false;
        }

        $channel = $meta['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            $roleListKey = $role->scope === 'store' ? 'store_roles' : 'global_roles';
            $allowedRoles = array_values(array_filter((array) config("admin.channels.{$channel}.{$roleListKey}", []), 'is_string'));
            if ($allowedRoles !== [] && ! in_array($role->code, $allowedRoles, true)) {
                return false;
            }
        }

        if ($role->code === 'SUPER_ADMIN') {
            return $scope !== 'store';
        }

        $required = array_values(array_filter((array) ($meta['permissions'] ?? []), 'is_string'));
        if ($required === []) {
            return true;
        }

        $mode = (string) ($meta['mode'] ?? 'all');
        if ($mode === 'any') {
            return array_intersect($required, $permissions) !== [];
        }

        return array_diff($required, $permissions) === [];
    }

    /** @return list<int> */
    private function roleIdsForUser(User $user): array
    {
        if (! Schema::hasTable('roles')) {
            return [];
        }

        $global = $user->roles()
            ->where('roles.is_active', true)
            ->pluck('roles.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $store = [];
        if (Schema::hasTable('user_store_roles')) {
            $store = DB::table('user_store_roles')
                ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
                ->where('user_store_roles.user_id', $user->id)
                ->where('roles.is_active', true)
                ->pluck('roles.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        return array_values(array_unique(array_merge($global, $store)));
    }

    private function itemVisibleForRoles(string $key, array $roleIds, $visibility): bool
    {
        if ($roleIds === []) {
            return true;
        }

        $rows = $visibility->get($key, collect());
        if ($rows->isEmpty()) {
            return true;
        }

        if ($rows->contains(fn ($row): bool => (bool) $row->is_visible)) {
            return true;
        }

        $configuredRoleIds = $rows->pluck('role_id')->map(static fn ($id): int => (int) $id)->unique()->all();

        return array_diff($roleIds, $configuredRoleIds) !== [];
    }

    /** @param array<string,mixed>|null $layout */
    private function applyLocalizedLabels(array &$node, ?array $layout): void
    {
        if ($layout === null) {
            return;
        }

        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $secondary = $locale === 'ar' ? 'en' : 'ar';
        $primaryCustom = $layout['label_'.$locale] ?? null;
        $secondaryCustom = $layout['label_'.$secondary] ?? null;

        if (is_string($primaryCustom) && trim($primaryCustom) !== '') {
            $node['label_text'] = trim($primaryCustom);
        }
        if (is_string($secondaryCustom) && trim($secondaryCustom) !== '') {
            $node['label_secondary_text'] = trim($secondaryCustom);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('admin_sidebar_groups')
            && Schema::hasTable('admin_sidebar_items')
            && Schema::hasTable('admin_sidebar_role_visibility');
    }
}
