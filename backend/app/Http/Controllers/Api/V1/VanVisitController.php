<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\User;
use App\Models\VanNoOrderReason;
use App\Models\VanVisit;
use App\Services\VanRuntimeVisitScope;
use App\Services\VanVisitLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VanVisitController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $visits = app(VanRuntimeVisitScope::class)
            ->query($request, $actor)
            ->select(['customer_type', 'customer_id', 'store_id'])
            ->distinct()
            ->orderBy('customer_type')
            ->orderBy('customer_id')
            ->get();

        $data = $visits->map(function (VanVisit $visit): array {
            return $this->customerPayload(
                (string) $visit->customer_type,
                (int) $visit->customer_id,
                $visit->store_id === null ? null : (int) $visit->store_id,
            );
        })->values();

        return response()->json(['data' => $data]);
    }

    public function customer(Request $request, string $type, int $customer): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertCustomerInActorScope($request, $actor, $type, $customer);

        $storeId = app(VanRuntimeVisitScope::class)
            ->query($request, $actor)
            ->where('customer_type', $type)
            ->where('customer_id', $customer)
            ->latest('id')
            ->value('store_id');

        return response()->json($this->customerPayload(
            $type,
            $customer,
            $storeId === null ? null : (int) $storeId,
        ));
    }

    public function visits(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                'planned',
                'started',
                'completed_with_order',
                'completed_no_order',
                'customer_unavailable',
                'closed',
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = app(VanRuntimeVisitScope::class)
            ->query($request, $actor)
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', $validated['status']),
            )
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (VanVisit $visit): array => $this->visitPayload($visit),
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'customer_type' => ['required', Rule::in(['b2b', 'b2c'])],
            'customer_id' => ['required', 'integer', 'min:1'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'planned_at' => ['nullable', 'date'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ]);

        $type = (string) $validated['customer_type'];
        $customerId = (int) $validated['customer_id'];
        $this->assertCustomerExists($type, $customerId);

        $visit = DB::transaction(function () use ($request, $validated, $actor, $type, $customerId): VanVisit {
            $existing = VanVisit::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing instanceof VanVisit) {
                abort_unless((int) $existing->actor_user_id === (int) $actor->getKey(), 409);

                return $existing;
            }

            $runtimeContext = app(VanRuntimeVisitScope::class)->context($request);
            $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
            if ($runtimeContext !== null) {
                $metadata['van_id'] = $runtimeContext['van_id'];
                $metadata['van_assignment_id'] = $runtimeContext['assignment_id'];
            }

            return VanVisit::query()->create([
                'actor_user_id' => $actor->getKey(),
                'customer_type' => $type,
                'customer_id' => $customerId,
                'store_id' => $validated['store_id'] ?? null,
                'status' => 'planned',
                'planned_at' => $validated['planned_at'] ?? null,
                'idempotency_key' => $validated['idempotency_key'],
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        }, 3);

        return response()->json($this->visitPayload($visit), 201);
    }

    public function transition(
        Request $request,
        VanVisit $visit,
        VanVisitLifecycleService $service,
    ): JsonResponse {
        $actor = $this->actor($request);
        abort_unless(app(VanRuntimeVisitScope::class)->contains($request, $actor, $visit), 404);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                'started',
                'completed_with_order',
                'completed_no_order',
                'customer_unavailable',
                'closed',
            ])],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'no_order_reason_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $updated = $service->transition(
            $visit,
            (string) $validated['status'],
            $actor,
            isset($validated['order_id']) ? (int) $validated['order_id'] : null,
            isset($validated['no_order_reason_id']) ? (int) $validated['no_order_reason_id'] : null,
        );

        return response()->json($this->visitPayload($updated));
    }

    public function noOrderReasons(Request $request): JsonResponse
    {
        $this->actor($request);

        return response()->json([
            'data' => VanNoOrderReason::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(['id', 'code', 'label_en', 'label_ar'])
                ->values(),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function assertCustomerInActorScope(
        Request $request,
        User $actor,
        string $type,
        int $customerId,
    ): void {
        abort_unless(in_array($type, ['b2b', 'b2c'], true), 404);

        abort_unless(
            app(VanRuntimeVisitScope::class)
                ->query($request, $actor)
                ->where('customer_type', $type)
                ->where('customer_id', $customerId)
                ->exists(),
            404,
        );
    }

    private function assertCustomerExists(string $type, int $customerId): void
    {
        $exists = match ($type) {
            'b2b' => B2bCustomer::query()->whereKey($customerId)->exists(),
            'b2c' => B2cCustomer::query()->whereKey($customerId)->exists(),
            default => false,
        };

        if ($exists === false) {
            throw ValidationException::withMessages([
                'customer_id' => ['The selected customer does not exist in the authoritative customer domain.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function customerPayload(string $type, int $customerId, ?int $storeId): array
    {
        $customer = match ($type) {
            'b2b' => B2bCustomer::query()->findOrFail($customerId),
            'b2c' => B2cCustomer::query()->findOrFail($customerId),
            default => abort(404),
        };

        return [
            'type' => $type,
            'id' => (int) $customer->getKey(),
            'name' => (string) ($customer->name ?? ''),
            'phone' => $customer->phone ?? null,
            'email' => $customer->email ?? null,
            'store_id' => $storeId,
        ];
    }

    /** @return array<string, mixed> */
    private function visitPayload(VanVisit $visit): array
    {
        $rawMetadata = $visit->getAttribute('metadata');
        $metadata = is_array($rawMetadata) ? $rawMetadata : [];
        $routeKey = collect([
            $metadata['route_key'] ?? null,
            $metadata['route_code'] ?? null,
            $metadata['route'] ?? null,
        ])->map(static fn (mixed $value): string => trim((string) $value))
            ->first(static fn (string $value): bool => $value !== '');

        $customerType = (string) $visit->customer_type;
        $customerId = (int) $visit->customer_id;
        $customerColumn = $customerType === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
        $address = DB::table('addresses')
            ->where($customerColumn, $customerId)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first(['latitude', 'longitude', 'label', 'line1', 'area', 'city']);

        $latitude = $address?->latitude === null ? null : (float) $address->latitude;
        $longitude = $address?->longitude === null ? null : (float) $address->longitude;
        $addressText = $address === null
            ? null
            : collect([$address->label, $address->line1, $address->area, $address->city])
                ->map(static fn (mixed $value): string => trim((string) $value))
                ->filter(static fn (string $value): bool => $value !== '')
                ->unique()
                ->implode(' · ');

        return [
            'id' => (int) $visit->getKey(),
            'customer_type' => $customerType,
            'customer_id' => $customerId,
            'store_id' => $visit->store_id === null ? null : (int) $visit->store_id,
            'route_key' => $routeKey === null || $routeKey === '' ? null : $routeKey,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $addressText === '' ? null : $addressText,
            'status' => (string) $visit->status,
            'order_id' => $visit->order_id === null ? null : (int) $visit->order_id,
            'no_order_reason_id' => $visit->no_order_reason_id === null ? null : (int) $visit->no_order_reason_id,
            'planned_at' => $visit->planned_at?->toIso8601String(),
            'started_at' => $visit->started_at?->toIso8601String(),
            'completed_at' => $visit->completed_at?->toIso8601String(),
            'closed_at' => $visit->closed_at?->toIso8601String(),
            'allowed_transitions' => VanVisitLifecycleService::allowedTransitions((string) $visit->status),
        ];
    }
}
