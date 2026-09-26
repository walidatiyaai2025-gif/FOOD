<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CredentialAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(private readonly CredentialAuthenticator $credentials) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->credentials->authenticate($credentials['email'], $credentials['password']);

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are invalid.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken('foodex-client')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->identity($user),
        ]);
    }

    public function logout(Request $request): Response
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        return response()->noContent();
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return response()->json($this->identity($user));
    }

    /** @return array{id:int,name:string,email:string,locale:string,roles:list<string>,store_ids:list<int>} */
    private function identity(User $user): array
    {
        $roles = $user->roles()
            ->orderBy('roles.code')
            ->pluck('roles.code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();

        $storeIds = $user->storeRoleAssignments()
            ->select('store_id')
            ->distinct()
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'locale' => (string) $user->locale,
            'roles' => $roles,
            'store_ids' => $storeIds,
        ];
    }
}
