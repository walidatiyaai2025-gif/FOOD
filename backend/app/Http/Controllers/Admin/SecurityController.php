<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\RbacManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SecurityController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('security.view');
        $this->useActorLocale($request);

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }

        $users = User::query()
            ->with(['roles:id,code,name,is_active', 'storeRoleAssignments.role:id,code,name,is_active', 'storeRoleAssignments.store:id,code,name'])
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $roles = Role::query()
            ->with('permissions:id,code,name')
            ->withCount(['users', 'storeAssignments'])
            ->orderByDesc('is_system')
            ->orderBy('code')
            ->get();

        $permissions = Permission::query()->orderBy('code')->get();

        return view('admin.security', [
            'users' => $users,
            'roles' => $roles,
            'globalRoles' => $roles->whereIn('scope', ['global', 'both'])->where('is_active', true)->values(),
            'storeRoles' => $roles->whereIn('scope', ['store', 'both'])->where('is_active', true)->values(),
            'stores' => Store::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'permissionGroups' => $permissions->groupBy(static fn (Permission $permission): string => Str::before($permission->code, '.')),
            'search' => $search,
            'statusFilter' => $status,
        ]);
    }

    public function updateUserStatus(Request $request, User $user, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('users.status.manage');
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $rbac->setUserActive(
            $this->actor($request),
            $user,
            (bool) $validated['is_active'],
            $validated['reason'] ?? null,
            $request,
        );

        return back()->with('status', (bool) $validated['is_active']
            ? __('admin.security.user_activated')
            : __('admin.security.user_deactivated'));
    }

    public function updateUserRoles(Request $request, User $user, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('users.roles.manage');
        $validated = $request->validate([
            'global_role_ids' => ['nullable', 'array'],
            'global_role_ids.*' => ['integer', 'exists:roles,id'],
            'store_role_ids' => ['nullable', 'array'],
            'store_role_ids.*' => ['array'],
            'store_role_ids.*.*' => ['integer', 'exists:roles,id'],
        ]);

        $storeRoles = [];

        foreach (($validated['store_role_ids'] ?? []) as $storeId => $roleIds) {
            foreach ((array) $roleIds as $roleId) {
                $storeRoles[] = ['store_id' => (int) $storeId, 'role_id' => (int) $roleId];
            }
        }

        $rbac->replaceUserRoles(
            $this->actor($request),
            $user,
            array_map('intval', $validated['global_role_ids'] ?? []),
            $storeRoles,
            $request,
        );

        return back()->with('status', __('admin.security.user_roles_saved'));
    }

    public function storeRole(Request $request, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $validated = $this->validateRole($request, true);
        $rbac->createRole($this->actor($request), $validated['code'], $this->roleValues($validated), $request);

        return back()->with('status', __('admin.security.role_created'));
    }

    public function updateRole(Request $request, Role $role, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $validated = $this->validateRole($request);
        $rbac->updateRole($this->actor($request), $role, $this->roleValues($validated), $request);

        return back()->with('status', __('admin.security.role_saved'));
    }

    public function cloneRole(Request $request, Role $role, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]{2,49}$/', Rule::unique('roles', 'code')],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $rbac->cloneRole($this->actor($request), $role, $validated['code'], $validated['name'], $request);

        return back()->with('status', __('admin.security.role_cloned'));
    }

    public function destroyRole(Request $request, Role $role, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $rbac->deleteRole($this->actor($request), $role, $request);

        return back()->with('status', __('admin.security.role_deleted'));
    }

    /** @return array<string, mixed> */
    private function validateRole(Request $request, bool $includeCode = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scope' => ['required', Rule::in(['global', 'store', 'both'])],
            'is_active' => ['required', 'boolean'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];

        if ($includeCode) {
            $rules['code'] = ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]{2,49}$/', Rule::unique('roles', 'code')];
        }

        return $request->validate($rules);
    }

    /** @param array<string, mixed> $validated
     *  @return array{name:string,description:?string,scope:string,is_active:bool,permission_ids:list<int>}
     */
    private function roleValues(array $validated): array
    {
        return [
            'name' => (string) $validated['name'],
            'description' => isset($validated['description']) && trim((string) $validated['description']) !== ''
                ? trim((string) $validated['description'])
                : null,
            'scope' => (string) $validated['scope'],
            'is_active' => (bool) $validated['is_active'],
            'permission_ids' => array_map('intval', $validated['permission_ids'] ?? []),
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function useActorLocale(Request $request): void
    {
        $actor = $this->actor($request);
        App::setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');
    }
}
