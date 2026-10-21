<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SidebarManagementController extends Controller
{
    public function roleAccess(Request $request, AdminSidebarManager $sidebar): View
    {
        Gate::authorize('roles.manage');
        $this->useActorLocale($request);

        $roles = Role::query()
            ->with('permissions:id,code')
            ->orderByDesc('is_system')
            ->orderBy('code')
            ->get();

        $selectedId = (int) $request->query('role_id', (int) ($roles->first()?->id ?? 0));
        $selectedRole = $roles->firstWhere('id', $selectedId) ?? $roles->first();

        abort_if($selectedRole === null, 404);

        return view('admin.sidebar-role-access', [
            'roles' => $roles,
            'selectedRole' => $selectedRole,
            'matrix' => $sidebar->roleMatrix($selectedRole),
            'navGroups' => app(AdminNavigation::class)->groupsFor($request->user()),
            'navContext' => 'sidebar_role_access',
            'user' => $request->user(),
        ]);
    }

    public function updateRoleAccess(Request $request, Role $role, AdminSidebarManager $sidebar): RedirectResponse
    {
        Gate::authorize('roles.manage');

        $validated = $request->validate([
            'item_keys' => ['nullable', 'array'],
            'item_keys.*' => ['string', 'max:120'],
        ]);

        $before = $sidebar->roleMatrix($role);
        $sidebar->saveRoleVisibility($role, array_values($validated['item_keys'] ?? []));
        $after = $sidebar->roleMatrix($role);

        $this->audit($request, 'admin.sidebar.role_visibility.updated', 'role', (int) $role->id, $before, $after);

        return redirect()
            ->route('admin.sidebar-access.roles', ['role_id' => $role->id])
            ->with('status', __('sidebar_admin.role_saved'));
    }

    public function layout(Request $request, AdminSidebarManager $sidebar): View
    {
        Gate::authorize('roles.manage');
        $this->useActorLocale($request);

        return view('admin.sidebar-layout', [
            'layout' => $sidebar->layoutData(),
            'protectedItems' => $sidebar->registry()['protected_items'],
            'protectedGroups' => $sidebar->registry()['protected_groups'],
            'navGroups' => app(AdminNavigation::class)->groupsFor($request->user()),
            'navContext' => 'sidebar_layout',
            'user' => $request->user(),
        ]);
    }

    public function updateLayout(Request $request, AdminSidebarManager $sidebar): RedirectResponse
    {
        Gate::authorize('roles.manage');

        $validated = $request->validate([
            'groups' => ['required', 'array'],
            'groups.*.label_ar' => ['nullable', 'string', 'max:160'],
            'groups.*.label_en' => ['nullable', 'string', 'max:160'],
            'groups.*.icon' => ['nullable', 'string', 'max:16'],
            'groups.*.sort_order' => ['required', 'integer', 'between:-10000,10000'],
            'groups.*.is_enabled' => ['required', 'boolean'],
            'items' => ['required', 'array'],
            'items.*.group_key' => ['required', 'string', 'max:80'],
            'items.*.parent_key' => ['nullable', 'string', 'max:120'],
            'items.*.label_ar' => ['nullable', 'string', 'max:160'],
            'items.*.label_en' => ['nullable', 'string', 'max:160'],
            'items.*.sort_order' => ['required', 'integer', 'between:-10000,10000'],
            'items.*.is_enabled' => ['required', 'boolean'],
        ]);

        $registry = $sidebar->registry();
        $groups = (array) $validated['groups'];
        $items = (array) $validated['items'];

        foreach (array_keys($groups) as $groupKey) {
            if (! isset($registry['groups'][$groupKey])) {
                throw ValidationException::withMessages(['groups' => __('sidebar_admin.invalid_group')]);
            }
        }

        foreach ($items as $itemKey => $item) {
            if (! isset($registry['items'][$itemKey])) {
                throw ValidationException::withMessages(['items' => __('sidebar_admin.invalid_item')]);
            }
            if (! isset($registry['groups'][$item['group_key']])) {
                throw ValidationException::withMessages(["items.{$itemKey}.group_key" => __('sidebar_admin.invalid_group')]);
            }
            $parent = $item['parent_key'] ?? null;
            if ($parent !== null && $parent !== '') {
                if ($parent === $itemKey || ! isset($registry['items'][$parent])) {
                    throw ValidationException::withMessages(["items.{$itemKey}.parent_key" => __('sidebar_admin.invalid_parent')]);
                }
                $parentGroup = $items[$parent]['group_key'] ?? $registry['items'][$parent]['group'] ?? null;
                if ($parentGroup !== $item['group_key']) {
                    throw ValidationException::withMessages(["items.{$itemKey}.parent_key" => __('sidebar_admin.parent_same_group')]);
                }
            }
        }

        $this->assertNoParentCycles($items);

        $before = $sidebar->layoutData();
        $sidebar->saveLayout($groups, $items);
        $after = $sidebar->layoutData();
        $this->audit($request, 'admin.sidebar.layout.updated', 'admin_sidebar', null, $before, $after);

        return redirect()->route('admin.sidebar-layout.index')->with('status', __('sidebar_admin.layout_saved'));
    }

    /** @param array<string,array<string,mixed>> $items */
    private function assertNoParentCycles(array $items): void
    {
        foreach (array_keys($items) as $start) {
            $seen = [];
            $current = $start;
            while (isset($items[$current])) {
                $parent = $items[$current]['parent_key'] ?? null;
                if (! is_string($parent) || $parent === '') {
                    break;
                }
                if (isset($seen[$parent]) || $parent === $start) {
                    throw ValidationException::withMessages(["items.{$start}.parent_key" => __('sidebar_admin.parent_cycle')]);
                }
                $seen[$current] = true;
                $current = $parent;
            }
        }
    }

    private function audit(
        Request $request,
        string $event,
        string $type,
        ?int $id,
        mixed $before,
        mixed $after,
    ): void {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()?->id,
            'event' => $event,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'before' => json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function useActorLocale(Request $request): void
    {
        $locale = (string) ($request->user()?->locale ?? 'ar');
        App::setLocale(in_array($locale, ['ar', 'en'], true) ? $locale : 'ar');
    }
}
