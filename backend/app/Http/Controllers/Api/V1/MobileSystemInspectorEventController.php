<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SystemInspectorRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class MobileSystemInspectorEventController extends Controller
{
    public function __invoke(Request $request, SystemInspectorRecorder $recorder): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $request->validate([
            'app' => ['required', Rule::in(['customer', 'driver', 'van'])],
            'category' => ['required', 'string', 'max:120'],
            'severity' => ['nullable', Rule::in(['warning', 'error'])],
            'message' => ['required', 'string', 'max:2000'],
            'app_version' => ['nullable', 'string', 'max:80'],
            'app_build' => ['nullable', 'string', 'max:80'],
            'platform' => ['nullable', 'string', 'max:40'],
            'os_version' => ['nullable', 'string', 'max:240'],
            'current_route' => ['nullable', 'string', 'max:512'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'invoice_id' => ['nullable', 'integer', 'min:1'],
            'assignment_id' => ['nullable', 'integer', 'min:1'],
            'route_id' => ['nullable', 'integer', 'min:1'],
            'manifest_id' => ['nullable', 'integer', 'min:1'],
            'visit_id' => ['nullable', 'integer', 'min:1'],
            'collection_id' => ['nullable', 'integer', 'min:1'],
            'remittance_id' => ['nullable', 'integer', 'min:1'],
            'method' => ['nullable', 'string', 'max:12'],
            'path' => ['nullable', 'string', 'max:4096'],
            'status' => ['nullable', 'integer', 'between:400,599'],
            'correlation_id' => ['nullable', 'string', 'max:100'],
            'retry' => ['nullable', 'boolean'],
            'attempt' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:10000'],
            'metadata' => ['nullable', 'array', 'max:40'],
        ]);

        $this->authorizeApp($user, (string) $data['app']);
        $storeId = $this->authorizedStoreId(
            $user,
            (string) $data['app'],
            isset($data['store_id']) ? (int) $data['store_id'] : null,
        );

        $recorder->recordMobile($data, $request, $storeId);

        return response()->json([], 202);
    }

    private function authorizeApp(User $user, string $app): void
    {
        if ($app === 'driver') {
            abort_unless(
                DB::table('drivers')
                    ->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->exists(),
                403,
            );

            return;
        }

        if ($app === 'van') {
            abort_unless($user->hasPermission('van.login'), 403);

            return;
        }

        $allowed = DB::table('platform_customers')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists()
            || DB::table('customers')->where('user_id', $user->id)->exists()
            || DB::table('b2b_customers')->where('user_id', $user->id)->exists()
            || DB::table('b2c_customers')->where('user_id', $user->id)->exists();

        abort_unless($allowed, 403);
    }

    private function authorizedStoreId(User $user, string $app, ?int $storeId): ?int
    {
        if ($storeId === null) {
            return null;
        }

        if ($app === 'driver') {
            $allowed = DB::table('drivers')
                ->where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->exists();

            abort_unless($allowed, 403);

            return $storeId;
        }

        if ($app === 'van') {
            abort_unless(
                $user->hasPermission('van.login')
                && $user->hasPermission('stores.view', $storeId),
                403,
            );

            return $storeId;
        }

        $allowed = DB::table('b2c_customers')
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->exists()
            || DB::table('orders')
                ->join('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
                ->where('b2b_customers.user_id', $user->id)
                ->where('orders.store_id', $storeId)
                ->exists()
            || DB::table('orders')
                ->join('b2c_customers', 'b2c_customers.id', '=', 'orders.b2c_customer_id')
                ->where('b2c_customers.user_id', $user->id)
                ->where('orders.store_id', $storeId)
                ->exists();

        abort_unless($allowed, 403);

        return $storeId;
    }
}
