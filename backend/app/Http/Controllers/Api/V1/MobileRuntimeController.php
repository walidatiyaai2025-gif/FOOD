<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileAppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileRuntimeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['app' => ['required', 'in:customer,driver'], 'environment' => ['required', 'in:development,staging,production']]);
        $setting = MobileAppSetting::query()->where($data)->first();

        if ($setting === null) {
            return response()->json(['message' => 'Mobile runtime settings are not configured.'], 404);
        }

        return response()->json(['data' => $setting]);
    }
}
