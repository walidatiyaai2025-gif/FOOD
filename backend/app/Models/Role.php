<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $scope
 * @property bool $is_system
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

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }
}
