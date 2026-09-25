<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ManagementReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagementReportController extends Controller
{
    public function __construct(private ManagementReportService $reports) {}

    public function show(Request $request, string $report): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $filters = $request->validate($this->rules());

        return response()->json([
            'data' => $this->reports->run($user, $report, $filters),
        ]);
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'min:1', 'exists:stores,id'],
            'channel' => ['nullable', 'in:b2b,b2c'],
            'status' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'integer', 'min:1', 'exists:products,id'],
            'category_id' => ['nullable', 'integer', 'min:1', 'exists:categories,id'],
            'customer_id' => ['nullable', 'integer', 'min:1', 'exists:customers,id'],
            'payment_provider' => ['nullable', 'string', 'max:64'],
        ];
    }
}
