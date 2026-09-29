<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property bool $is_active
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $last_seen_at
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, UserStoreRole> $storeRoleAssignments
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'username', 'email', 'password', 'locale', 'is_active', 'deactivated_at', 'deactivation_reason', 'last_seen_at'];

    protected $hidden = ['password', 'remember_token'];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /** @return HasMany<UserStoreRole, $this> */
    public function storeRoleAssignments(): HasMany
    {
        return $this->hasMany(UserStoreRole::class);
    }

    public function hasRole(string $roleCode, ?int $storeId = null): bool
    {
        if ($storeId === null) {
            return $this->roles()
                ->where('roles.code', $roleCode)
                ->where('roles.is_active', true)
                ->where('roles.scope', 'global')
                ->exists();
        }

        $channel = $this->storeChannel($storeId);
        if ($channel === null) {
            return false;
        }

        if ($channel === 'b2c') {
            return $this->storeRoleAssignments()
                ->where('store_id', $storeId)
                ->whereHas('role', fn ($query) => $query
                    ->where('code', $roleCode)
                    ->where('is_active', true)
                    ->where('scope', 'store'))
                ->exists();
        }

        $allowedGlobalRoles = array_values((array) config("admin.channels.{$channel}.global_roles", []));

        return $this->roles()
            ->where('roles.code', $roleCode)
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', $allowedGlobalRoles)
            ->exists();
    }

    public function hasPermission(string $permissionCode, ?int $storeId = null): bool
    {
        if ($this->hasRole('SUPER_ADMIN')) {
            return true;
        }

        if ($storeId === null) {
            return $this->roles()
                ->where('roles.is_active', true)
                ->where('roles.scope', 'global')
                ->whereHas('permissions', fn ($query) => $query->where('code', $permissionCode))
                ->exists();
        }

        $channel = $this->storeChannel($storeId);
        if ($channel === null) {
            return false;
        }

        if ($channel === 'b2c' && $this->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->whereHas('role', fn ($query) => $query
                ->where('is_active', true)
                ->where('scope', 'store')
                ->whereHas('permissions', fn ($permissions) => $permissions->where('code', $permissionCode)))
            ->exists()) {
            return true;
        }

        $allowedGlobalRoles = array_values((array) config("admin.channels.{$channel}.global_roles", []));

        return $this->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', $allowedGlobalRoles)
            ->whereHas('permissions', fn ($query) => $query->where('code', $permissionCode))
            ->exists();
    }

    /** @return list<string> */
    public function effectivePermissionCodes(?int $storeId = null): array
    {
        if ($this->hasRole('SUPER_ADMIN')) {
            return array_keys((array) config('permissions.abilities', []));
        }

        $global = $this->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global');

        if ($storeId !== null) {
            $channel = $this->storeChannel($storeId);
            if ($channel === null) {
                return [];
            }

            $global->whereIn('roles.code', array_values((array) config("admin.channels.{$channel}.global_roles", [])));
        }

        $codes = $global
            ->with('permissions:id,code')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'));

        if ($storeId !== null && $this->storeChannel($storeId) === 'b2c') {
            $codes = $codes->merge(
                $this->storeRoleAssignments()
                    ->where('store_id', $storeId)
                    ->with('role.permissions:id,code')
                    ->get()
                    ->filter(fn (UserStoreRole $assignment): bool => (bool) $assignment->role->is_active
                        && $assignment->role->scope === 'store')
                    ->flatMap(fn (UserStoreRole $assignment) => $assignment->role->permissions->pluck('code')),
            );
        }

        return $codes
            ->filter(fn ($code): bool => is_string($code) && $code !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function storeChannel(int $storeId): ?string
    {
        $channel = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->value('store_types.code');

        return is_string($channel) ? strtolower($channel) : null;
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (is_string($user->username) && trim($user->username) !== '') {
                $user->username = strtolower(trim($user->username));

                return;
            }

            $emailPrefix = Str::before(strtolower((string) $user->email), '@');
            $base = Str::slug($emailPrefix, '_');
            if ($base === '') {
                $base = 'user';
            }

            $candidate = $base;
            $suffix = 1;
            while (static::query()->where('username', $candidate)->exists()) {
                $suffix++;
                $candidate = $base.'_'.$suffix;
            }

            $user->username = $candidate;
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
