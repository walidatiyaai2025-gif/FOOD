<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SystemInspectorRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RuntimeDiagnosticController extends Controller
{
    public function __invoke(Request $request, SystemInspectorRecorder $recorder): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(['customer_app', 'driver_app'])],
            'severity' => ['nullable', Rule::in(['warning', 'error'])],
            'event_type' => ['nullable', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:2000'],
            'exception_class' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:4096'],
            'route' => ['nullable', 'string', 'max:2048'],
            'method' => ['nullable', 'string', 'max:12'],
            'status' => ['nullable', 'integer', 'between:400,599'],
            'correlation_id' => ['nullable', 'string', 'max:100'],
            'stack' => ['nullable', 'string', 'max:10000'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'app_build' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'max:40'],
            'os_version' => ['nullable', 'string', 'max:1000'],
            'locale' => ['nullable', 'string', 'max:20'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c', 'wholesale', 'retail'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'operation' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'error_code' => ['nullable', 'string', 'max:120'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'attempt' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'retry_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'invoice_id' => ['nullable', 'integer', 'min:1'],
            'assignment_id' => ['nullable', 'integer', 'min:1'],
            'network_state' => ['nullable', Rule::in(['unknown', 'reachable', 'unreachable'])],
        ]);

        $recorder->recordRuntimeClient($data, $request);

        return response()->json([], 202);
    }
}
