<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class SecurityController extends Controller
{
    public function permissions(): JsonResponse
    {
        Gate::authorize('security.view');

        return response()->json([
            'data' => Permission::query()->orderBy('code')->get()->map(static fn (Permission $permission): array => [
                'id' => (int) $permission->id,
                'code' => (string) $permission->code,
                'name' => (string) $permission->name,
                'module' => Str::before($permission->code, '.'),
                'action' => Str::after($permission->code, '.'),
            ])->values(),
        ]);
    }

    public function roles(): JsonResponse
    {
        Gate::authorize('security.view');

        return response()->json([
            'data' => Role::query()
                ->with('permissions:id,code,name')
                ->withCount(['users', 'storeAssignments'])
                ->orderBy('code')
                ->get()
                ->map(fn (Role $role): array => $this->rolePayload($role))
                ->values(),
        ]);
    }

    public function storeRole(Request $request, RbacManager $rbac): JsonResponse
    {
        Gate::authorize('roles.manage');
        $validated = $this->validateRole($request, true);
        $role = $rbac->createRole($this->actor($request), $validated['code'], $this->roleValues($validated), $request);

        return response()->json(['data' => $this->rolePayload($role)], 201);
    }

    public function updateRole(Request $request, Role $role, RbacManager $rbac): JsonResponse
    {
        Gate::authorize('roles.manage');
        $validated = $this->validateRole($request);
        $role = $rbac->updateRole($this->actor($request), $role, $this->roleValues($validated), $request);

        return response()->json(['data' => $this->rolePayload($role)]);
    }

    public function cloneRole(Request $request, Role $role, RbacManager $rbac): JsonResponse
    {
        Gate::authorize('roles.manage');
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]{2,49}$/', Rule::unique('roles', 'code')],
            'name' => ['required', 'string', 'max:120'],
        ]);
        $clone = $rbac->cloneRole($this->actor($request), $role, $validated['code'], $validated['name'], $request);

        return response()->json(['data' => $this->rolePayload($clone)], 201);
    }

    public function destroyRole(Request $request, Role $role, RbacManager $rbac): Response
    {
        Gate::authorize('roles.manage');
        $rbac->deleteRole($this->actor($request), $role, $request);

        return response()->noContent();
    }

    public function users(Request $request): JsonResponse
    {
        $this->authorizeUserRead();

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        $storeId = $request->integer('store_id') ?: null;
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $users = User::query()
            ->with(['roles:id,code,name,is_active', 'storeRoleAssignments.role:id,code,name,is_active', 'storeRoleAssignments.store:id,code,name'])
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user): array => $this->userPayload($user, $storeId))->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function showUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserRead();
        $user->load(['roles:id,code,name,is_active', 'storeRoleAssignments.role:id,code,name,is_active', 'storeRoleAssignments.store:id,code,name']);
        $storeId = $request->integer('store_id') ?: null;

        return response()->json(['data' => $this->userPayload($user, $storeId)]);
    }

    public function updateUserRoles(Request $request, User $user, RbacManager $rbac): JsonResponse
    {
        Gate::authorize('users.roles.manage');
        $validated = $request->validate([
            'global_role_ids' => ['present', 'array'],
            'global_role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'store_roles' => ['present', 'array'],
            'store_roles.*.store_id' => ['required', 'integer', 'exists:stores,id'],
            'store_roles.*.role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $updated = $rbac->replaceUserRoles(
            $this->actor($request),
            $user,
            array_map('intval', $validated['global_role_ids']),
            array_map(static fn (array $assignment): array => [
                'store_id' => (int) $assignment['store_id'],
                'role_id' => (int) $assignment['role_id'],
            ], $validated['store_roles']),
            $request,
        );

        return response()->json(['data' => $this->userPayload($updated)]);
    }

    public function updateUserStatus(Request $request, User $user, RbacManager $rbac): JsonResponse
    {
        Gate::authorize('users.status.manage');
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $rbac->setUserActive(
            $this->actor($request),
            $user,
            (bool) $validated['is_active'],
            $validated['reason'] ?? null,
            $request,
        );

        return response()->json(['data' => $this->userPayload($updated)]);
    }

    /** @return array<string, mixed> */
    private function validateRole(Request $request, bool $includeCode = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scope' => ['required', Rule::in(['global', 'store', 'both'])],
            'is_active' => ['required', 'boolean'],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];

        if ($includeCode) {
            $rules['code'] = ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]{2,49}$/', Rule::unique('roles', 'code')];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{name:string,description:?string,scope:string,is_active:bool,permission_ids:list<int>}
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
            'permission_ids' => array_map('intval', $validated['permission_ids']),
        ];
    }

    /** @return array<string, mixed> */
    private function rolePayload(Role $role): array
    {
        $role->loadMissing('permissions:id,code,name');

        return [
            'id' => (int) $role->id,
            'code' => (string) $role->code,
            'name' => (string) $role->name,
            'description' => $role->description,
            'scope' => (string) $role->scope,
            'is_system' => (bool) $role->is_system,
            'is_active' => (bool) $role->is_active,
            'permissions' => $role->permissions->map(static fn (Permission $permission): array => [
                'id' => (int) $permission->id,
                'code' => (string) $permission->code,
                'name' => (string) $permission->name,
            ])->sortBy('code')->values(),
            'users_count' => isset($role->users_count) ? (int) $role->users_count : null,
            'store_assignments_count' => isset($role->store_assignments_count) ? (int) $role->store_assignments_count : null,
        ];
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user, ?int $storeId = null): array
    {
        $user->loadMissing(['roles:id,code,name,is_active', 'storeRoleAssignments.role:id,code,name,is_active', 'storeRoleAssignments.store:id,code,name']);

        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'locale' => (string) $user->locale,
            'is_active' => (bool) $user->is_active,
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $user->deactivation_reason,
            'global_roles' => $user->roles->map(static fn (Role $role): array => [
                'id' => (int) $role->id,
                'code' => (string) $role->code,
                'name' => (string) $role->name,
                'is_active' => (bool) $role->is_active,
            ])->sortBy('code')->values(),
            'store_roles' => $user->storeRoleAssignments->map(static fn ($assignment): array => [
                'store_id' => (int) $assignment->store_id,
                'store_code' => (string) $assignment->store?->code,
                'store_name' => (string) $assignment->store?->name,
                'role_id' => (int) $assignment->role_id,
                'role_code' => (string) $assignment->role?->code,
                'role_name' => (string) $assignment->role?->name,
                'role_active' => (bool) $assignment->role?->is_active,
            ])->values(),
            'effective_permissions' => $user->effectivePermissionCodes($storeId),
        ];
    }

    private function authorizeUserRead(): void
    {
        abort_unless(Gate::allows('users.view') || Gate::allows('security.view'), 403);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
