<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseApprovalLimit;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseDelegation;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\EnterpriseRoleDataScope;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseSecurityManager;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EnterpriseSecurityController extends Controller
{
    public function __construct(
        private readonly EnterpriseSecurityManager $security,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('security.view');

        $roles = Role::query()
            ->where('is_active', true)
            ->with('permissions:id,code')
            ->orderBy('name')
            ->limit(200)
            ->get();

        $users = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'username', 'email']);

        $scopeOptions = $this->scopeOptions();
        $scopeLabels = collect($scopeOptions)->pluck('label', 'key')->all();

        $permissions = Permission::query()
            ->orderBy('code')
            ->limit(500)
            ->get(['id', 'code', 'name']);

        return view('admin.security-enterprise', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'roles' => $roles,
            'users' => $users,
            'scopeOptions' => $scopeOptions,
            'scopeLabels' => $scopeLabels,
            'permissionLabels' => $this->permissionLabels($permissions),
            'dataScopes' => EnterpriseRoleDataScope::query()
                ->with('role:id,name,code')
                ->orderByDesc('is_active')
                ->orderByDesc('updated_at')
                ->paginate(15, ['*'], 'scope_page')
                ->withQueryString(),
            'approvalLimits' => EnterpriseApprovalLimit::query()
                ->with('role:id,name,code')
                ->orderByDesc('is_active')
                ->orderByDesc('updated_at')
                ->paginate(15, ['*'], 'limit_page')
                ->withQueryString(),
            'delegations' => EnterpriseDelegation::query()
                ->with(['delegator:id,name,username,email', 'delegate:id,name,username,email'])
                ->orderByDesc('created_at')
                ->paginate(15, ['*'], 'delegation_page')
                ->withQueryString(),
            'pendingChanges' => EnterpriseWorkflowRequest::query()
                ->with(['requester:id,name,username,email', 'steps.actor:id,name,username,email'])
                ->where('workflow_key', 'enterprise_security_privileged_change')
                ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->paginate(15, ['*'], 'change_page')
                ->withQueryString(),
        ]);
    }

    public function requestRoleScopeGrant(Request $request): RedirectResponse
    {
        $actor = $this->authorizedActor($request, 'security.scopes.manage');
        $validated = $request->validate(['role_id' => ['required', 'integer', 'exists:roles,id']]);
        $role = Role::query()->findOrFail((int) $validated['role_id']);
        [$scopeType, $scopeId] = $this->validatedScope($request);
        $this->security->requestRoleScopeGrant($actor, $role, $scopeType, $scopeId);

        return back()->with('status', __('enterprise_security.requested'));
    }

    public function requestRoleScopeRevoke(
        Request $request,
        EnterpriseRoleDataScope $dataScope,
    ): RedirectResponse {
        $actor = $this->authorizedActor($request, 'security.scopes.manage');
        $this->security->requestRoleScopeRevoke($actor, $dataScope);

        return back()->with('status', __('enterprise_security.requested'));
    }

    public function requestApprovalLimit(Request $request): RedirectResponse
    {
        $actor = $this->authorizedActor($request, 'security.approval_limits.manage');
        $validated = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'permission_code' => ['required', 'string', Rule::in(array_keys((array) config('permissions.abilities', [])))],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'max_amount' => ['required', 'string', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
        ]);
        [$scopeType, $scopeId] = $this->validatedScope($request);
        $role = Role::query()->findOrFail((int) $validated['role_id']);

        $this->security->requestApprovalLimit(
            $actor,
            $role,
            (string) $validated['permission_code'],
            $scopeType,
            $scopeId,
            strtoupper((string) $validated['currency']),
            (string) $validated['max_amount'],
        );

        return back()->with('status', __('enterprise_security.requested'));
    }

    public function requestApprovalLimitDeactivate(
        Request $request,
        EnterpriseApprovalLimit $approvalLimit,
    ): RedirectResponse {
        $actor = $this->authorizedActor($request, 'security.approval_limits.manage');
        $this->security->requestApprovalLimitDeactivate($actor, $approvalLimit);

        return back()->with('status', __('enterprise_security.requested'));
    }

    public function requestDelegation(Request $request): RedirectResponse
    {
        $actor = $this->authorizedActor($request, 'security.delegations.manage');
        $validated = $request->validate([
            'delegate_id' => ['required', 'integer', 'exists:users,id'],
            'permission_code' => ['required', 'string', Rule::in(array_keys((array) config('permissions.abilities', [])))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ]);
        [$scopeType, $scopeId] = $this->validatedScope($request);
        $delegate = User::query()->findOrFail((int) $validated['delegate_id']);

        $this->security->requestDelegation(
            $actor,
            $actor,
            $delegate,
            (string) $validated['permission_code'],
            $scopeType,
            $scopeId,
            (string) $validated['starts_at'],
            (string) $validated['ends_at'],
        );

        return back()->with('status', __('enterprise_security.requested'));
    }

    public function requestDelegationRevoke(
        Request $request,
        EnterpriseDelegation $delegation,
    ): RedirectResponse {
        $actor = $this->authorizedActor($request, 'security.delegations.manage');
        $this->security->requestDelegationRevoke($actor, $delegation);

        return back()->with('status', __('enterprise_security.requested'));
    }

    public function decide(
        Request $request,
        EnterpriseWorkflowRequest $workflowRequest,
    ): RedirectResponse {
        $actor = $this->authorizedActor($request, 'security.approve');
        abort_unless($workflowRequest->workflow_key === 'enterprise_security_privileged_change', 404);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->security->decideAndApply(
            (int) $workflowRequest->getKey(),
            $actor,
            (string) $validated['decision'],
            isset($validated['comment']) ? trim((string) $validated['comment']) : null,
        );

        return back()->with('status', __('enterprise_security.decision_recorded'));
    }

    /** @return array{string, ?int} */
    private function validatedScope(Request $request): array
    {
        $validated = $request->validate([
            'scope_key' => ['required', 'string', 'max:96', 'regex:/^(platform:\*|(organization|company|legal_entity|business_unit|store):[1-9]\d*)$/'],
        ]);

        $key = (string) $validated['scope_key'];
        if ($key === 'platform:*') {
            return ['platform', null];
        }

        [$type, $id] = explode(':', $key, 2);

        return [$type, (int) $id];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        App::setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        return $actor;
    }

    private function authorizedActor(Request $request, string $ability): User
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize($ability);

        return $actor;
    }

    /** @return list<array{key:string,label:string}> */
    private function scopeOptions(): array
    {
        $options = [[
            'key' => 'platform:*',
            'label' => __('enterprise_security.scope_platform'),
        ]];

        foreach (EnterpriseOrganization::query()->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name', 'code']) as $record) {
            $options[] = ['key' => 'organization:'.$record->id, 'label' => __('enterprise_security.scope_organization').' — '.$record->name];
        }

        foreach (EnterpriseCompany::query()->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name', 'code']) as $record) {
            $options[] = ['key' => 'company:'.$record->id, 'label' => __('enterprise_security.scope_company').' — '.$record->name];
        }

        foreach (EnterpriseLegalEntity::query()->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name', 'code']) as $record) {
            $options[] = ['key' => 'legal_entity:'.$record->id, 'label' => __('enterprise_security.scope_legal_entity').' — '.$record->name];
        }

        foreach (EnterpriseBusinessUnit::query()->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name', 'code']) as $record) {
            $options[] = ['key' => 'business_unit:'.$record->id, 'label' => __('enterprise_security.scope_business_unit').' — '.$record->name];
        }

        foreach (Store::query()->where('is_active', true)->orderBy('name')->limit(200)->get(['id', 'name', 'code']) as $record) {
            $options[] = ['key' => 'store:'.$record->id, 'label' => __('enterprise_security.scope_store').' — '.$record->name];
        }

        return $options;
    }

    /** @param Collection<int, Permission> $permissions */
    private function permissionLabels(Collection $permissions): array
    {
        $groupTranslations = trans('security_permissions.groups');
        $actionTranslations = trans('security_permissions.actions');
        $groupTranslations = is_array($groupTranslations) ? $groupTranslations : [];
        $actionTranslations = is_array($actionTranslations) ? $actionTranslations : [];

        return $permissions->mapWithKeys(function (Permission $permission) use ($groupTranslations, $actionTranslations): array {
            $modulePath = Str::beforeLast($permission->code, '.');
            $rootModule = Str::before($permission->code, '.');
            $action = Str::afterLast($permission->code, '.');
            $moduleLabel = $groupTranslations[$modulePath]
                ?? $groupTranslations[$rootModule]
                ?? Str::headline(str_replace('_', ' ', $modulePath));
            $actionLabel = $actionTranslations[$action]
                ?? Str::headline(str_replace('_', ' ', $action));

            return [$permission->code => $moduleLabel.' — '.$actionLabel];
        })->all();
    }
}
