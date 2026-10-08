<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\User;
use App\Services\CredentialAuthenticator;
use App\Services\PlatformCustomerService;
use App\Services\RetailMerchantIdentityService;
use App\Services\VanRuntimeContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly CredentialAuthenticator $credentials,
        private readonly PlatformCustomerService $platformCustomers,
        private readonly RetailMerchantIdentityService $retailMerchants,
        private readonly VanRuntimeContextResolver $vanContexts,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'app' => ['nullable', 'string', 'in:customer,driver,van'],
            'van_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $this->credentials->authenticate($credentials['email'], $credentials['password']);

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are invalid.'],
            ]);
        }

        $app = $credentials['app'] ?? null;
        if ($app === 'driver') {
            $driverExists = DB::table('drivers')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->exists();
            $driverRole = $user->hasRole('B2B_DRIVER') || $user->hasRole('B2C_DRIVER');
            abort_unless($driverExists && $driverRole, 403, 'This account is not authorized for the Driver app.');
        }

        if ($app === 'van') {
            abort_unless($user->hasPermission('van.login'), 403, 'This account is not authorized for the Van app.');
            $context = $this->vanContexts->resolve($user, isset($credentials['van_id']) ? (int) $credentials['van_id'] : null);
            abort_unless($context['selected'] !== null, 403, 'No effective Van assignment is available for this account.');
        }

        $tokenAbilities = $app === null ? ['*'] : ['app:'.$app];

        return response()->json([
            'token' => $user->createToken('foodex-'.($app ?? 'client'), $tokenAbilities)->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->identity($user, isset($credentials['van_id']) ? (int) $credentials['van_id'] : null),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'store_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $this->platformCustomers->register($validated, 'customer_app');

        return response()->json([
            'token' => $user->createToken('foodex-platform-customer')->plainTextToken,
            'token_type' => 'Bearer',
            'platform_customer' => true,
            'user' => $this->identity($user),
        ], 201);
    }

    public function mobileTrialLogin(Request $request): JsonResponse
    {
        abort_unless((bool) config('foodex.mobile_trial_username_login', false), 404);

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'app' => ['required', 'string', 'in:customer,driver'],
        ]);

        $username = strtolower(trim((string) $validated['username']));
        $user = User::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(username) = ?', [$username])
            ->first();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'username' => ['The provided username is invalid.'],
            ]);
        }

        if ($validated['app'] === 'driver') {
            $driverExists = DB::table('drivers')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->exists();
            $driverRole = $user->hasRole('B2B_DRIVER') || $user->hasRole('B2C_DRIVER');

            if (! $driverExists || ! $driverRole) {
                throw ValidationException::withMessages([
                    'username' => ['This username is not authorized for the Driver app.'],
                ]);
            }
        } else {
            $customerExists = DB::table('customers')->where('user_id', $user->id)->exists()
                || DB::table('b2b_customers')->where('user_id', $user->id)->exists()
                || DB::table('b2c_customers')->where('user_id', $user->id)->exists()
                || DB::table('user_store_roles')->where('user_id', $user->id)->exists();

            if (! $customerExists) {
                throw ValidationException::withMessages([
                    'username' => ['This username is not authorized for the Customer app.'],
                ]);
            }
        }

        return response()->json([
            'token' => $user->createToken('foodex-'.$validated['app'].'-trial')->plainTextToken,
            'token_type' => 'Bearer',
            'trial_username_login' => true,
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

    /** @return array<string,mixed> */
    private function identity(User $user, ?int $preferredVanId = null): array
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

        $driver = Driver::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->first();

        $driverScope = $driver instanceof Driver ? [
            'driver_id' => (int) $driver->getKey(),
            'channel' => strtolower((string) $driver->driver_type),
            'store_id' => $driver->store_id === null ? null : (int) $driver->store_id,
        ] : null;

        $vanScope = $user->hasPermission('van.login')
            ? $this->vanContexts->resolve($user, $preferredVanId)
            : ['selected' => null, 'available' => [], 'selection_reason' => 'van_login_not_granted'];

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'username' => $user->username === null ? null : (string) $user->username,
            'email' => (string) $user->email,
            'locale' => (string) $user->locale,
            'roles' => $roles,
            'permissions' => $user->effectivePermissionCodes(),
            'store_ids' => $storeIds,
            'driver_scope' => $driverScope,
            'van_scope' => $vanScope['selected'],
            'van_contexts' => $vanScope['available'],
            'van_context_selection_reason' => $vanScope['selection_reason'],
            'platform_customer' => $this->platformCustomers->isPlatformCustomer($user),
            ...$this->retailMerchants->identityPayload($user),
        ];
    }
}
