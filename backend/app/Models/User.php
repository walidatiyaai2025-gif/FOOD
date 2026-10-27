<?php

namespace App\Models;

use App\Services\BuiltInJobRoleCatalog;
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

    protected $fillable = ['name', 'username', 'email', 'phone_e164', 'phone_verified_at', 'password', 'locale', 'is_active', 'is_platform_customer', 'deactivated_at', 'deactivation_reason', 'last_seen_at'];

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

        $storeEffect = $storeId === null
            ? null
            : $this->permissionOverrideEffect($permissionCode, 'store:'.$storeId);
        if ($storeEffect !== null) {
            return $storeEffect === 'allow' && $this->hasPermissionScopeContext($storeId);
        }

        $globalEffect = $this->permissionOverrideEffect($permissionCode, 'global');
        if ($globalEffect === 'deny') {
            return false;
        }
        if ($globalEffect === 'allow') {
            if (! $this->permissionFitsActiveGlobalRoleDomain($permissionCode)) {
                return false;
            }

            if ($storeId === null) {
                return true;
            }

            return $this->hasGlobalRoleContextForChannel((string) $this->storeChannel($storeId));
        }

        return in_array($permissionCode, $this->inheritedPermissionCodes($storeId), true);
    }

    /** @return list<string> */
    public function inheritedPermissionCodes(?int $storeId = null): array
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

        $catalog = app(BuiltInJobRoleCatalog::class);
        $codes = $global
            ->with('permissions:id,code')
            ->get()
            ->filter(fn (Role $role): bool => $catalog->roleContributesPermissions((string) $role->code, null))
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'));

        if ($storeId !== null && $this->storeChannel($storeId) === 'b2c') {
            $codes = $codes->merge(
                $this->storeRoleAssignments()
                    ->where('store_id', $storeId)
                    ->with('role.permissions:id,code')
                    ->get()
                    ->filter(fn (UserStoreRole $assignment): bool => (bool) $assignment->role->is_active
                        && $assignment->role->scope === 'store'
                        && $catalog->roleContributesPermissions((string) $assignment->role->code, $storeId))
                    ->flatMap(fn (UserStoreRole $assignment) => $assignment->role->permissions->pluck('code')),
            );
        }

        return $codes
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function effectivePermissionCodes(?int $storeId = null): array
    {
        if ($this->hasRole('SUPER_ADMIN')) {
            return array_keys((array) config('permissions.abilities', []));
        }

        $codes = collect($this->inheritedPermissionCodes($storeId));
        $overrides = $this->permissionOverrideEffects($storeId);

        foreach ($overrides as $code => $effect) {
            if ($effect === 'deny') {
                $codes = $codes->reject(fn ($candidate): bool => $candidate === $code);

                continue;
            }

            if ($storeId === null || $this->hasPermissionScopeContext($storeId)) {
                $codes->push($code);
            }
        }

        return $codes
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<string, 'allow'|'deny'> */
    public function permissionOverrideEffects(?int $storeId = null): array
    {
        $scopeKeys = $storeId === null ? ['global'] : ['global', 'store:'.$storeId];
        $rows = DB::table('user_permission_overrides')
            ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
            ->where('user_permission_overrides.user_id', $this->getKey())
            ->whereIn('user_permission_overrides.scope_key', $scopeKeys)
            ->orderByRaw("CASE WHEN user_permission_overrides.scope_key = 'global' THEN 0 ELSE 1 END")
            ->get([
                'permissions.code',
                'user_permission_overrides.effect',
                'user_permission_overrides.scope_key',
            ]);

        $effects = [];
        $globalContext = $storeId === null
            || $this->hasGlobalRoleContextForChannel((string) $this->storeChannel($storeId));

        foreach ($rows as $row) {
            if (! is_string($row->code) || ! in_array($row->effect, ['allow', 'deny'], true)) {
                continue;
            }
            if ($row->scope_key === 'global'
                && $row->effect === 'allow'
                && (! $globalContext || ! $this->permissionFitsActiveGlobalRoleDomain((string) $row->code))) {
                continue;
            }

            $effects[$row->code] = $row->effect;
        }

        return $effects;
    }

    private function permissionOverrideEffect(string $permissionCode, string $scopeKey): ?string
    {
        $effect = DB::table('user_permission_overrides')
            ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
            ->where('user_permission_overrides.user_id', $this->getKey())
            ->where('user_permission_overrides.scope_key', $scopeKey)
            ->where('permissions.code', $permissionCode)
            ->value('user_permission_overrides.effect');

        return is_string($effect) && in_array($effect, ['allow', 'deny'], true) ? $effect : null;
    }

    private function permissionFitsActiveGlobalRoleDomain(string $permissionCode): bool
    {
        $roleCodes = $this->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->pluck('roles.code')
            ->map(static fn ($value): string => (string) $value);

        $retailDomain = $roleCodes->contains(
            static fn (string $code): bool => $code === 'B2C_DRIVER' || str_starts_with($code, 'RETAIL_'),
        );
        $wholesaleDomain = $roleCodes->contains(
            static fn (string $code): bool => $code !== 'B2C_DRIVER' && ! str_starts_with($code, 'RETAIL_'),
        );

        $allowedByRetail = $retailDomain && ! $this->permissionCrossesRetailBoundary($permissionCode);
        $allowedByWholesale = $wholesaleDomain
            && ! str_starts_with($permissionCode, 'drivers.b2c.')
            && ! str_starts_with($permissionCode, 'deliveries.b2c.');

        return $allowedByRetail || $allowedByWholesale;
    }

    private function permissionCrossesRetailBoundary(string $code): bool
    {
        return str_starts_with($code, 'b2b.')
            || str_starts_with($code, 'drivers.b2b.')
            || str_starts_with($code, 'deliveries.b2b.')
            || in_array($code, [
                'platform.manage',
                'security.view',
                'roles.manage',
                'users.view',
                'users.roles.manage',
                'users.status.manage',
                'translations.manage',
                'system.update',
                'demo_data.manage',
                'mobile_settings.manage',
                'push_settings.manage',
                'push_settings.test',
            ], true);
    }

    private function hasPermissionScopeContext(int $storeId): bool
    {
        $channel = $this->storeChannel($storeId);
        if ($channel === null) {
            return false;
        }

        if ($channel === 'b2c' && $this->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->whereHas('role', fn ($query) => $query
                ->where('is_active', true)
                ->where('scope', 'store'))
            ->exists()) {
            return true;
        }

        return $this->hasGlobalRoleContextForChannel($channel);
    }

    private function hasGlobalRoleContextForChannel(string $channel): bool
    {
        if ($channel === '') {
            return false;
        }

        $allowedGlobalRoles = array_values((array) config("admin.channels.{$channel}.global_roles", []));
        if ($allowedGlobalRoles === []) {
            return false;
        }

        return $this->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', $allowedGlobalRoles)
            ->exists();
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
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_platform_customer' => 'boolean',
            'deactivated_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
