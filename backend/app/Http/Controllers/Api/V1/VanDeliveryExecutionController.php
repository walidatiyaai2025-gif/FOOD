<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\VanDeliveryExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VanDeliveryExecutionController extends Controller
{
    public function __construct(private readonly VanDeliveryExecutionService $execution) {}

    public function show(Request $request, int $order): JsonResponse
    {
        return response()->json([
            'data' => $this->execution->snapshot(
                $this->actor($request),
                $this->runtime($request),
                $order,
            ),
        ]);
    }

    public function transition(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(['accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed']),
            ],
            'failure_reason' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:1000'],
            'proof_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $key = $this->idempotencyKey($request);

        return response()->json([
            'data' => $this->execution->transition(
                $this->actor($request),
                $this->runtime($request),
                $order,
                (string) $data['status'],
                $data['note'] ?? null,
                $request,
                $request->file('proof_image'),
                $data['failure_reason'] ?? null,
                $key,
            ),
            'meta' => ['idempotency_key' => $key],
        ]);
    }

    public function proof(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'proof_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $proof = $request->file('proof_image');
        abort_unless($proof !== null, 422);
        $key = $this->idempotencyKey($request);

        return response()->json([
            'data' => $this->execution->uploadProof(
                $this->actor($request),
                $this->runtime($request),
                $order,
                $proof,
                $data['note'] ?? null,
                $request,
                $key,
            ),
            'meta' => ['idempotency_key' => $key],
        ]);
    }

    public function fail(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'failure_reason' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:1000'],
            'proof_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $key = $this->idempotencyKey($request);

        return response()->json([
            'data' => $this->execution->transition(
                $this->actor($request),
                $this->runtime($request),
                $order,
                'failed',
                $data['note'] ?? null,
                $request,
                $request->file('proof_image'),
                (string) $data['failure_reason'],
                $key,
            ),
            'meta' => ['idempotency_key' => $key],
        ]);
    }

    public function retry(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $key = $this->idempotencyKey($request);

        return response()->json([
            'data' => $this->execution->transition(
                $this->actor($request),
                $this->runtime($request),
                $order,
                'out_for_delivery',
                $data['note'] ?? null,
                $request,
                null,
                null,
                $key,
            ),
            'meta' => ['idempotency_key' => $key],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @return array<string,mixed> */
    private function runtime(Request $request): array
    {
        $runtime = $request->attributes->get('van_runtime_context');
        abort_unless(is_array($runtime), 403, 'A valid Van runtime context is required.');

        return $runtime;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if (
            strlen($key) < 8
            || strlen($key) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'Idempotency-Key must be 8-128 characters using letters, numbers, dot, underscore, colon or dash.',
                ],
            ]);
        }

        return $key;
    }
}
