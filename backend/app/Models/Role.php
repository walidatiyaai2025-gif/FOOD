<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $scope
 * @property bool $is_system
 * @property Carbon|null $archived_at
 * @property bool $is_active
 * @property-read Collection<int, Permission> $permissions
 */
class Role extends Model
{
    protected $table = 'roles';

    protected $guarded = [];

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }

    /** @return HasMany<UserStoreRole, $this> */
    public function storeAssignments(): HasMany
    {
        return $this->hasMany(UserStoreRole::class);
    }

    protected static function booted(): void
    {
        static::updating(function (Role $role): void {
            // Role codes are historical identities; changing a code would
            // re-label existing audit events and stored role references.
            if ($role->isDirty('code')) {
                throw ValidationException::withMessages([
                    'code' => [__('admin.security.errors.role_code_immutable')],
                ]);
            }

            // Archiving is a terminal state. Keep the row, code, permissions
            // and historical references intact even on a later stale update.
            if ($role->getRawOriginal('archived_at') !== null) {
                throw ValidationException::withMessages([
                    'role' => [__('admin.security.errors.role_archived')],
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }
}
