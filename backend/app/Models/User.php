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

    protected $fillable = ['name', 'email', 'password', 'locale', 'is_active'];

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
        if ($this->roles()->where('roles.code', $roleCode)->exists()) {
            return true;
        }

        if ($storeId === null) {
            return false;
        }

        return $this->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->whereHas('role', fn ($query) => $query->where('code', $roleCode))
            ->exists();
    }

    public function hasPermission(string $permissionCode, ?int $storeId = null): bool
    {
        if ($this->hasRole('SUPER_ADMIN')) {
            return true;
        }

        if ($this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('code', $permissionCode))
            ->exists()) {
            return true;
        }

        if ($storeId === null) {
            return false;
        }

        return $this->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->whereHas(
                'role.permissions',
                fn ($query) => $query->where('permissions.code', $permissionCode),
            )
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
