<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Store;
use App\Services\AdminSidebarManager;
use App\Services\BuiltInJobRoleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;

final class UserRolePageTreePreviewController extends Controller
{
    /**
     * Read-only candidate tree for role assignment. This is never an RBAC
     * decision: effective user grants, explicit denies and each HTTP route
     * remain server-authoritative after the assignment is submitted.
     */
    public function __invoke(
        Request $request,
        AdminSidebarManager $sidebar,
        BuiltInJobRoleCatalog $jobRoles,
        ModuleRegistry $modules,
        ModuleOperationalReadiness $readiness,
    ): JsonResponse {
        Gate::authorize('security.view');
        Gate::authorize('users.roles.manage');

        $actor = $request->user();
        abort_unless($actor !== null && $actor->is_active, 403);

        $locale = (string) ($actor->locale ?? 'ar');
        App::setLocale(in_array($locale, ['ar', 'en'], true) ? $locale : 'ar');

        $input = $request->validate([
            'scope' => ['required', 'in:global,store'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'global_role_ids' => ['sometimes', 'array', 'max:30'],
            'global_role_ids.*' => ['integer', 'distinct', 'min:1'],
            'store_role_ids' => ['sometimes', 'array', 'max:30'],
            'store_role_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);

        $scope = (string) $input['scope'];
        $storeId = isset($input['store_id']) ? (int) $input['store_id'] : null;
        $globalIds = array_map('intval', $input['global_role_ids'] ?? []);
        $storeIds = array_map('intval', $input['store_role_ids'] ?? []);
        abort_if(array_intersect($globalIds, $storeIds) !== [] || count($globalIds) + count($storeIds) > 30, 422);

        // Preview only an existing active Retail store from the same picker.
        // An invented id or global-vs-store mismatch may not unlock a tree.
        if ($scope === 'store') {
            abort_unless($storeId !== null && Store::query()
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.id', $storeId)
                ->where('stores.is_active', true)
                ->where('store_types.code', 'B2C')->exists(), 422);
        } else {
            abort_if($storeId !== null || $storeIds !== [], 422);
        }

        $ids = array_values(array_unique(array_merge($globalIds, $storeIds)));
        $roles = Role::query()->with('permissions:id,code')->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($roles->count() === count($ids), 422);

        $superAdmin = $actor->hasRole('SUPER_ADMIN');
        if ($scope === 'store' && ! $superAdmin) {
            abort_unless($actor->hasPermission('users.roles.manage', $storeId), 403);
        }
        $globalGrantCodes = $superAdmin ? collect() : collect($actor->effectivePermissionCodes());
        $storeGrantCodes = $superAdmin ? collect() : collect($actor->effectivePermissionCodes($storeId));
        foreach ($ids as $id) {
            $role = $roles->get($id);
            abort_unless($role !== null && $role->is_active && $role->archived_at === null, 422);
            $isStoreRole = in_array($id, $storeIds, true);
            abort_unless($role->scope === ($isStoreRole ? 'store' : 'global'), 422);
            abort_unless($jobRoles->isAvailable((string) $role->code, $isStoreRole ? $storeId : null), 422);

            if (! $superAdmin) {
                abort_if(in_array($role->code, ['SUPER_ADMIN', 'PLATFORM_OWNER'], true), 403);
                $grantableCodes = $isStoreRole ? $storeGrantCodes : $globalGrantCodes;
                abort_if($role->permissions->pluck('code')->diff($grantableCodes)->isNotEmpty(), 403);
            }
        }

        $groups = [];
        $hidden = [];
        $moduleReady = [];
        foreach ($ids as $id) {
            $role = $roles->get($id);
            abort_unless($role instanceof Role, 422);
            foreach ($sidebar->roleMatrix($role)['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $key = (string) $item['key'];
                    $meta = (array) ($item['meta'] ?? []);
                    $nodeChannel = (string) ($meta['channel'] ?? '');
                    if ($scope === 'store' && $nodeChannel === 'b2b') {
                        continue;
                    }

                    if ($item['override'] === false || ! $item['global_enabled']) {
                        $hidden[$key] = true;

                        continue;
                    }
                    if (! $item['visible_for_role']) {
                        continue;
                    }

                    $moduleCode = (string) ($meta['module'] ?? '');
                    if ($moduleCode !== '') {
                        if (! array_key_exists($moduleCode, $moduleReady)) {
                            try {
                                $moduleReady[$moduleCode] = $readiness->status(
                                    $moduleCode,
                                    $modules->manifest($moduleCode)->version,
                                    $modules->state($moduleCode),
                                ) === 'ready';
                            } catch (\Throwable) {
                                $moduleReady[$moduleCode] = false;
                            }
                        }
                        if (! $moduleReady[$moduleCode]) {
                            continue;
                        }
                    }

                    $groupKey = (string) $group['key'];
                    if (! isset($groups[$groupKey])) {
                        $groups[$groupKey] = [
                            'label' => $this->label($group),
                            'items' => [],
                        ];
                    }
                    $groups[$groupKey]['items'][$key] = $this->label($item);
                }
            }
        }

        $result = [];
        $count = 0;
        foreach ($groups as $group) {
            $items = array_diff_key($group['items'], $hidden);
            if ($items === []) {
                continue;
            }
            $result[] = ['label' => $group['label'], 'items' => array_values($items)];
            $count += count($items);
        }

        return response()->json([
            'projection_only' => true,
            'count' => $count,
            'groups' => $result,
            'disclaimer' => __('admin.security.page_tree_preview_disclaimer'),
        ])->header('Cache-Control', 'private, no-store');
    }

    /** @param array<string, mixed> $item */
    private function label(array $item): string
    {
        $custom = $item['label_'.app()->getLocale()] ?? null;
        if (is_string($custom) && trim($custom) !== '') {
            return trim($custom);
        }

        return __((string) ($item['label'] ?? $item['key'] ?? ''));
    }
}
