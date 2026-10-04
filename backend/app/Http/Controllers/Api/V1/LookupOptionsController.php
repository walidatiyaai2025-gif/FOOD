<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OperationalLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class LookupOptionsController extends Controller
{
    public function __construct(private readonly OperationalLookupService $lookups) {}

    public function __invoke(Request $request, string $type): JsonResponse
    {
        try {
            $data = $this->lookups->options($type, app()->getLocale());
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return response()->json([
            'type' => $type,
            'data' => $data,
        ]);
    }
}
