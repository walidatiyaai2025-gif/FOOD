<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushDeviceToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PushDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $this->upsert($request, $user);
    }

    public function storeGuest(Request $request): JsonResponse
    {
        return $this->upsert($request, null);
    }

    public function destroy(Request $request, PushDeviceToken $device): JsonResponse
    {
        abort_unless(
            (int) $device->user_id === (int) $request->user()?->getAuthIdentifier(),
            404,
        );

        $device->update(['revoked_at' => now()]);

        return response()->json(['status' => 'revoked']);
    }

    private function upsert(Request $request, ?User $user): JsonResponse
    {
        $data = $request->validate([
            'app' => ['required', 'in:customer,driver,van'],
            'platform' => ['required', 'in:android,ios'],
            'environment' => ['required', 'in:development,staging,production'],
            'token' => ['required', 'string', 'max:4096'],
            'install_id' => ['nullable', 'string', 'max:120'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'target_channel' => ['nullable', 'in:all,b2b,b2c'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        if ($user === null) {
            abort_unless($data['app'] === 'customer', 401);
            abort_if(empty($data['install_id']), 422, 'install_id is required for anonymous Customer devices.');
        } elseif ($data['app'] === 'driver') {
            $allowed = DB::table('drivers')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->exists();
            abort_unless($allowed, 403);
        } elseif ($data['app'] === 'van') {
            abort_unless($user->hasPermission('van.login'), 403);
        } else {
            $allowed = DB::table('platform_customers')->where('user_id', $user->id)->where('is_active', true)->exists()
                || DB::table('customers')->where('user_id', $user->id)->exists()
                || DB::table('b2b_customers')->where('user_id', $user->id)->exists()
                || DB::table('b2c_customers')->where('user_id', $user->id)->exists();
            abort_unless($allowed, 403);
        }

        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;
        $targetChannel = (string) ($data['target_channel'] ?? 'all');
        if ($storeId !== null) {
            $storeChannel = strtolower((string) DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.id', $storeId)
                ->where('stores.is_active', true)
                ->value('store_types.code'));
            abort_unless(in_array($storeChannel, ['b2b', 'b2c'], true), 404);
            if ($targetChannel !== 'all') {
                abort_unless($targetChannel === $storeChannel, 422);
            }
        }

        $locale = isset($data['locale'])
            ? (string) $data['locale']
            : ($user instanceof User ? (string) $user->locale : 'ar');

        $installId = isset($data['install_id']) && trim((string) $data['install_id']) !== ''
            ? trim((string) $data['install_id'])
            : null;

        $device = DB::transaction(function () use (
            $data,
            $user,
            $installId,
            $storeId,
            $targetChannel,
            $locale,
        ): PushDeviceToken {
            $device = PushDeviceToken::query()->updateOrCreate(
                ['token_hash' => hash('sha256', $data['token'])],
                [
                    'user_id' => $user?->id,
                    'install_id' => $installId,
                    'app' => $data['app'],
                    'platform' => $data['platform'],
                    'environment' => $data['environment'],
                    'store_id' => $storeId,
                    'target_channel' => $targetChannel,
                    'locale' => $locale,
                    'token_encrypted' => $data['token'],
                    'revoked_at' => null,
                    'last_seen_at' => now(),
                ],
            );

            if ($installId !== null) {
                PushDeviceToken::query()
                    ->where('install_id', $installId)
                    ->where('app', $data['app'])
                    ->where('platform', $data['platform'])
                    ->where('environment', $data['environment'])
                    ->where('id', '!=', $device->id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }

            return $device;
        });

        return response()->json([
            'data' => [
                'id' => $device->id,
                'app' => $device->app,
                'platform' => $device->platform,
                'environment' => $device->environment,
                'anonymous' => $device->user_id === null,
            ],
        ], $device->wasRecentlyCreated ? 201 : 200);
    }
}
