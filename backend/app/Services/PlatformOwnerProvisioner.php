<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlatformOwnerProvisioner
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Internal-only bootstrap. Never route this through a web controller or
     * accept its credentials in a release artifact. Keep the credential input
     * transient and clear deployment secrets after the one-time operation.
     */
    public function provision(string $email, string $password, string $name = 'FOODEX Platform Owner'): User
    {
        $email = Str::lower(trim($email));
        $name = trim($name);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($password) < 16 || strlen($password) > 200
            || $name === '' || strlen($name) > 255) {
            throw ValidationException::withMessages([
                'owner' => ['A valid email, name and strong 16+ character secret are required.'],
            ]);
        }

        return DB::transaction(function () use ($email, $password, $name): User {
            DB::table('platform_owner_registry')->lockForUpdate()->get();
            if (DB::table('platform_owner_registry')->exists()) {
                throw ValidationException::withMessages([
                    'owner' => ['A platform owner is already provisioned. Rotation requires a separate audited recovery flow.'],
                ]);
            }
            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'owner' => ['A pre-existing account cannot be silently elevated to owner.'],
                ]);
            }
            $role = Role::query()->where('code', 'PLATFORM_OWNER')
                ->where('is_active', true)->where('is_system', true)->firstOrFail();
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
                'is_platform_customer' => false,
                'locale' => 'ar',
            ]);
            $user->roles()->attach($role->getKey());
            DB::table('platform_owner_registry')->insert([
                'id' => 1,
                'user_id' => $user->getKey(),
                'provisioned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('platform.owner.provisioned', $user, $user, null, [
                'user_id' => (int) $user->getKey(),
                'source' => 'internal_bootstrap',
            ]);

            return $user->fresh();
        }, 3);
    }
}
