<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SelfStorePurchaseNotAllowed extends RuntimeException
{
    public const ERROR_CODE = 'SELF_STORE_PURCHASE_NOT_ALLOWED';

    public function __construct(public readonly int $storeId)
    {
        parent::__construct('Retail merchants cannot purchase from a Retail Store they own or manage.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::ERROR_CODE,
            'store_id' => $this->storeId,
        ], 403);
    }
}
