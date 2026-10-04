<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SystemInspectorRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SystemInspectorIngestionController extends Controller
{
    public function __invoke(Request $request, SystemInspectorRecorder $recorder): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $data = $request->validate([
            'app' => ['required', Rule::in(['customer', 'driver'])],
            'source' => ['required', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:80'],
            'severity' => ['required', Rule::in(['warning', 'error'])],
            'message' => ['required', 'string', 'max:2000'],
            'app_version' => ['nullable', 'string', 'max:80'],
            'app_build' => ['nullable', 'string', 'max:80'],
            'platform' => ['nullable', 'string', 'max:40'],
            'os_version' => ['nullable', 'string', 'max:1000'],
            'route' => ['nullable', 'string', 'max:1000'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'invoice_id' => ['nullable', 'integer', 'min:1'],
            'assignment_id' => ['nullable', 'integer', 'min:1'],
            'path' => ['nullable', 'string', 'max:4096'],
            'method' => ['nullable', 'string', 'max:12'],
            'status' => ['nullable', 'integer', 'between:400,599'],
            'correlation_id' => ['nullable', 'string', 'max:160'],
            'retry' => ['nullable', 'integer', 'between:0,100'],
            'elapsed_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'stack' => ['nullable', 'string', 'max:10000'],
            'error_type' => ['nullable', 'string', 'max:255'],
        ]);

        $isDriver = $actor->hasRole('B2C_DRIVER') || $actor->hasRole('B2B_DRIVER');
        if ($data['app'] === 'driver') {
            abort_unless($isDriver, 403);
        } elseif ($isDriver && ! $actor->is_platform_customer) {
            abort(403);
        }

        $recorder->recordMobile($data, $request);

        return response()->json([], 202);
    }
}
