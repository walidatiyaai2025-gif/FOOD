<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'locale', 'is_active', 'deactivated_at', 'deactivation_reason'];

    protected $hidden = ['password', 'remember_token'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function storeRoleAssignments(): HasMany
    {
        return $this->hasMany(UserStoreRole::class);
    }

    public function hasRole(string $roleCode, ?int $storeId = null): bool
    {
        if ($this->roles()
            ->where('roles.code', $roleCode)
            ->where('roles.is_active', true)
            ->whereIn('roles.scope', ['global', 'both'])
            ->exists()) {
            return true;
        }

        if ($storeId === null) {
            return false;
        }

        return $this->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->whereHas('role', fn ($query) => $query
                ->where('code', $roleCode)
                ->where('is_active', true)
                ->whereIn('scope', ['store', 'both']))
            ->exists();
    }

    public function hasPermission(string $permissionCode, ?int $storeId = null): bool
    {
        if ($this->hasRole('SUPER_ADMIN')) {
            return true;
        }

        if ($storeId !== null && $this->storeRoleAssignments()->exists()) {
            return $this->storeRoleAssignments()
                ->where('store_id', $storeId)
                ->whereHas('role', fn ($query) => $query
                    ->where('is_active', true)
                    ->whereIn('scope', ['store', 'both'])
                    ->whereHas('permissions', fn ($permissions) => $permissions->where('code', $permissionCode)))
                ->exists();
        }

        if ($this->roles()
            ->where('roles.is_active', true)
            ->whereIn('roles.scope', ['global', 'both'])
            ->whereHas('permissions', fn ($query) => $query->where('code', $permissionCode))
            ->exists()) {
            return true;
        }

        if ($storeId === null) {
            return false;
        }

        return $this->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->whereHas('role', fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('scope', ['store', 'both'])
                ->whereHas('permissions', fn ($permissions) => $permissions->where('code', $permissionCode)))
            ->exists();
    }

    /** @return list<string> */
    public function effectivePermissionCodes(?int $storeId = null): array
    {
        if ($this->hasRole('SUPER_ADMIN')) {
            return array_values(array_keys((array) config('permissions.abilities', [])));
        }

        $codes = $this->roles()
            ->where('roles.is_active', true)
            ->whereIn('roles.scope', ['global', 'both'])
            ->with('permissions:id,code')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'));

        if ($storeId !== null) {
            $codes = $codes->merge(
                $this->storeRoleAssignments()
                    ->where('store_id', $storeId)
                    ->with('role.permissions:id,code')
                    ->get()
                    ->filter(fn (UserStoreRole $assignment): bool => (bool) $assignment->role?->is_active
                        && in_array($assignment->role?->scope, ['store', 'both'], true))
                    ->flatMap(fn (UserStoreRole $assignment) => $assignment->role?->permissions->pluck('code') ?? collect()),
            );
        }

        return $codes
            ->filter(fn ($code): bool => is_string($code) && $code !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }
}
