<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CredentialAuthenticator
{
    public function authenticate(string $email, string $password): ?User
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->first();

        if (! $user || ! $user->is_active || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }
}
