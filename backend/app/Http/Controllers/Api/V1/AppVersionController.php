<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use App\Services\AppVersionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AppVersionController extends Controller
{
    public function __invoke(Request $request, AppVersionPolicy $evaluator): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'in:android,ios'],
            'app' => ['required', 'in:customer,driver,van'],
            'current_version' => ['required', 'string', 'max:64'],
        ]);

        $locale = in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'en';
        foreach ($request->getLanguages() as $preferredLanguage) {
            $candidate = strtolower(substr((string) $preferredLanguage, 0, 2));
            if (in_array($candidate, ['ar', 'en'], true)) {
                $locale = $candidate;
                break;
            }
        }

        $policy = AppVersion::query()
            ->where('platform', $validated['platform'])
            ->where('app', $validated['app'])
            ->first();

        if ($policy === null) {
            return response()->json(['message' => __('app_versions.api_not_configured', [], $locale)], 404);
        }

        try {
            return response()->json($evaluator->evaluate($policy, $validated['current_version']));
        } catch (InvalidArgumentException) {
            return response()->json(['message' => __('app_versions.api_invalid_version', [], $locale)], 422);
        }
    }
}
