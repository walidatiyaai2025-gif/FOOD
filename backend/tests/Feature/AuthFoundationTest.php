<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_view_profile_and_logout(): void
    {
        $user = User::query()->create([
            'name' => 'FOODEX User',
            'email' => 'user@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.roles', [])
            ->assertJsonPath('user.store_ids', []);

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('email', $user->email);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->withToken($token)->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_invalid_or_inactive_credentials_are_rejected(): void
    {
        $user = User::query()->create([
            'name' => 'Inactive',
            'email' => 'inactive@example.test',
            'password' => Hash::make('correct-password'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertUnprocessable();

        $user->update(['is_active' => true]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.test',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_protected_identity_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_public_b2b_self_registration_does_not_exist(): void
    {
        $this->postJson('/api/v1/b2b/register', [])->assertNotFound();
        $this->postJson('/api/v1/b2b/signup', [])->assertNotFound();
    }
}
