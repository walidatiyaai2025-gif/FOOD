<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CustomerServiceCaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerSupportCaseController extends Controller
{
    public function __invoke(Request $request, CustomerServiceCaseManager $cases): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $case = $cases->createCustomerAppCase($actor, $request->only([
            'store_id',
            'phone',
            'message',
        ]));

        return response()->json([
            'data' => [
                'id' => (int) $case->getKey(),
                'public_id' => (string) $case->public_id,
                'status' => (string) $case->status,
                'type' => (string) $case->type,
                'source' => (string) $case->source,
            ],
        ], 201);
    }
}
