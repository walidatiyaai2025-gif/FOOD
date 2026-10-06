<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AddressQualityReview;
use App\Services\AddressQualityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AddressQualityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('customers.view');

        $data = $request->validate([
            'status' => ['nullable', Rule::in(['unmapped', 'confirmed', 'rejected'])],
            'quality_class' => ['nullable', 'string', 'max:32'],
            'subject_type' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = AddressQualityReview::query()
            ->with('events')
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['quality_class']), fn ($query) => $query->where('quality_class', $data['quality_class']))
            ->when(isset($data['subject_type']), fn ($query) => $query->where('subject_type', $data['subject_type']))
            ->when(isset($data['search']), function ($query) use ($data): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $data['search']).'%';
                $query->where(function ($scope) use ($search): void {
                    $scope
                        ->where('public_id', 'like', $search)
                        ->orWhere('territory_key', 'like', $search)
                        ->orWhere('reason', 'like', $search);
                });
            })
            ->orderByDesc('created_at')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json($rows);
    }

    public function show(AddressQualityReview $review): JsonResponse
    {
        Gate::authorize('customers.view');

        return response()->json(['data' => $review->load('events')]);
    }

    public function confirm(
        Request $request,
        AddressQualityReview $review,
        AddressQualityService $service,
    ): JsonResponse {
        Gate::authorize('customers.edit');

        $data = $request->validate([
            'territory_key' => ['required', 'string', 'max:150'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $service->confirm(
                $request->user(),
                $review,
                (string) $data['territory_key'],
                (string) $data['reason'],
            ),
        ]);
    }

    public function reject(
        Request $request,
        AddressQualityReview $review,
        AddressQualityService $service,
    ): JsonResponse {
        Gate::authorize('customers.edit');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $service->reject($request->user(), $review, (string) $data['reason']),
        ]);
    }

    public function reopen(
        Request $request,
        AddressQualityReview $review,
        AddressQualityService $service,
    ): JsonResponse {
        Gate::authorize('customers.edit');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $service->reopen($request->user(), $review, (string) $data['reason']),
        ]);
    }
}
