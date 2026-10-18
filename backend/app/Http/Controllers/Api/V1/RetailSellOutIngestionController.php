<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\SellOut\RetailSellOutIngestionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RetailSellOutIngestionController extends Controller
{
    public function index(Request $request, RetailSellOutIngestionService $sellOut): JsonResponse
    {
        $data = $request->validate([
            'retailer_store_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $sellOut->connections($this->actor($request), (int) $data['retailer_store_id']),
        ]);
    }

    public function store(Request $request, RetailSellOutIngestionService $sellOut): JsonResponse
    {
        $data = $request->validate([
            'retailer_store_id' => ['required', 'integer', 'min:1'],
            'source_type' => ['required', 'string', 'in:pos_api,api,csv'],
            'provider' => ['required', 'string', 'max:64'],
            'source_key' => ['required', 'string', 'max:128'],
            'source_timezone' => ['sometimes', 'string', 'timezone'],
        ]);

        return response()->json($sellOut->connect(
            $this->actor($request),
            (int) $data['retailer_store_id'],
            (string) $data['source_type'],
            (string) $data['provider'],
            (string) $data['source_key'],
            (string) ($data['source_timezone'] ?? RetailSellOutIngestionService::BUSINESS_TIMEZONE),
        ), 201);
    }

    public function mapSku(
        Request $request,
        int $connection,
        RetailSellOutIngestionService $sellOut,
    ): JsonResponse {
        $data = $request->validate([
            'external_sku' => ['required', 'string', 'max:128'],
            'product_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($sellOut->mapSku(
            $this->actor($request),
            $connection,
            (string) $data['external_sku'],
            (int) $data['product_id'],
        ));
    }

    public function ingest(
        Request $request,
        int $connection,
        RetailSellOutIngestionService $sellOut,
    ): JsonResponse {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['required', 'array'],
        ]);

        return response()->json($sellOut->ingest(
            $this->actor($request),
            $connection,
            (string) $data['idempotency_key'],
            array_values($data['rows']),
        ));
    }

    public function run(Request $request, int $run, RetailSellOutIngestionService $sellOut): JsonResponse
    {
        return response()->json($sellOut->run($this->actor($request), $run));
    }

    public function destroy(Request $request, int $connection, RetailSellOutIngestionService $sellOut): JsonResponse
    {
        $sellOut->revoke($this->actor($request), $connection);

        return response()->json(['status' => 'revoked']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
