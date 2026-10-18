<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerServiceTeamManager
{
    /** @var list<string> */
    public const MANAGED_ROLE_CODES = [
        'CUSTOMER_SERVICE_AGENT',
        'CUSTOMER_SERVICE_SUPERVISOR',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function canManage(User $actor): bool
    {
        return $actor->is_active
            && ($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('customer_service.team.manage'));
    }

    /** @return Collection<int,User> */
    public function members(User $actor): Collection
    {
        $this->authorize($actor);

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.code', self::MANAGED_ROLE_CODES))
            ->with(['roles:id,code,name', 'storeRoleAssignments:id,user_id,store_id,role_id'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->isManagedMember($user))
            ->values();
    }

    /** @param array{name:string,email:string,password:string,locale:string,role_code:string} $data */
    public function create(User $actor, array $data, Request $request): User
    {
        $this->authorize($actor);
        $role = $this->managedRole((string) $data['role_code']);

        return DB::transaction(function () use ($actor, $data, $request, $role): User {
            $member = User::query()->create([
                'name' => trim((string) $data['name']),
                'email' => strtolower(trim((string) $data['email'])),
                'password' => (string) $data['password'],
                'locale' => (string) $data['locale'],
                'is_active' => true,
                'is_platform_customer' => false,
            ]);
            $member->roles()->attach($role->getKey());

            $fresh = $member->refresh()->load('roles:id,code,name');
            $this->audit->record(
                'customer_service.team.member_created',
                $actor,
                $fresh,
                null,
                [
                    'role_code' => (string) $role->code,
                    'is_active' => true,
                ],
                $request,
            );

            return $fresh;
        }, 3);
    }

    /** @param array{role_code:string,is_active:bool,deactivation_reason?:?string} $data */
    public function update(User $actor, User $member, array $data, Request $request): User
    {
        $this->authorize($actor);
        $member->loadMissing(['roles:id,code,name', 'storeRoleAssignments:id,user_id,store_id,role_id']);
        if (! $this->isManagedMember($member)) {
            throw ValidationException::withMessages([
                'member' => [__('customer_service_workspace.team.errors.outside_scope')],
            ]);
        }

        $role = $this->managedRole((string) $data['role_code']);
        $before = [
            'role_codes' => $member->roles->pluck('code')->sort()->values()->all(),
            'is_active' => (bool) $member->is_active,
        ];

        return DB::transaction(function () use ($actor, $member, $data, $request, $role, $before): User {
            $member->roles()->sync([$role->getKey()]);
            $active = (bool) $data['is_active'];
            $reason = trim((string) ($data['deactivation_reason'] ?? ''));

            $member->forceFill([
                'is_active' => $active,
                'deactivated_at' => $active ? null : now(),
                'deactivation_reason' => $active ? null : ($reason === '' ? 'customer_service_manager_action' : $reason),
            ])->save();

            $fresh = $member->refresh()->load('roles:id,code,name');
            $this->audit->record(
                'customer_service.team.member_updated',
                $actor,
                $fresh,
                $before,
                [
                    'role_code' => (string) $role->code,
                    'is_active' => (bool) $fresh->is_active,
                ],
                $request,
            );

            return $fresh;
        }, 3);
    }

    /** @return list<string> */
    public function roleCodes(): array
    {
        return self::MANAGED_ROLE_CODES;
    }

    private function authorize(User $actor): void
    {
        abort_unless($this->canManage($actor), 403);
    }

    private function managedRole(string $code): Role
    {
        if (! in_array($code, self::MANAGED_ROLE_CODES, true)) {
            throw ValidationException::withMessages([
                'role_code' => [__('customer_service_workspace.team.errors.role_not_allowed')],
            ]);
        }

        return Role::query()
            ->where('code', $code)
            ->where('scope', 'global')
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function isManagedMember(User $member): bool
    {
        $roleCodes = $member->roles->pluck('code')->filter()->values()->all();

        return $roleCodes !== []
            && array_diff($roleCodes, self::MANAGED_ROLE_CODES) === []
            && $member->storeRoleAssignments->isEmpty();
    }
}
