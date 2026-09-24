<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are invalid.'],
            ]);
        }

        $user->tokens()->delete();

        return response()->json([
            'token' => $user->createToken('foodex-client')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->identity($user),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function profile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->identity($user));
    }

    /** @return array{id:int,name:string,email:string,locale:string,roles:list<string>,store_ids:list<int>} */
    private function identity(User $user): array
    {
        $roles = $user->newQuery()
            ->whereKey($user->getKey())
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->pluck('roles.code')
            ->unique()
            ->values()
            ->all();

        $storeIds = $user->newQuery()
            ->whereKey($user->getKey())
            ->join('user_store_roles', 'users.id', '=', 'user_store_roles.user_id')
            ->pluck('user_store_roles.store_id')
            ->unique()
            ->values()
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'roles' => $roles,
            'store_ids' => $storeIds,
        ];
    }
}
