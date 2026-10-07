<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\CommercialPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class VanCommercialPolicyController extends Controller
{
    public function quote(
        Request $request,
        string $type,
        int $customer,
        CommercialPolicyService $commercial,
    ): JsonResponse {
        $actor = $this->actor($request);
        $storeId = $this->assertCustomerScope($request, $actor, $type, $customer);
        $legacyCustomerId = $this->legacyCustomerId($type, $customer);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'selling_unit_code' => ['required', 'string', 'max:80'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $productId = (int) $data['product_id'];
        $sellingUnit = $commercial->sellingUnit(
            $productId,
            (string) $data['selling_unit_code'],
            (float) $data['quantity'],
        );
        $decision = $commercial->evaluate(
            $productId,
            $legacyCustomerId,
            'van',
            (float) $sellingUnit['base_quantity'],
        );

        $overrideApplied = false;
        $overrideReason = trim((string) ($data['override_reason'] ?? ''));

        if (! $decision['allowed'] && $overrideReason !== '') {
            abort_unless(
                Gate::forUser($actor)->allows('orders.approve', $storeId)
                || Gate::forUser($actor)->allows('platform.manage'),
                403,
            );

            $commercial->recordOverride(
                $productId,
                $actor,
                $overrideReason,
                [
                    'source' => 'van_app',
                    'store_id' => $storeId,
                    'customer_type' => $type,
                    'customer_id' => $customer,
                    'legacy_customer_id' => $legacyCustomerId,
                    'channel' => 'van',
                    'selling_unit_code' => (string) $sellingUnit['code'],
                    'selling_quantity' => (float) $sellingUnit['selling_quantity'],
                    'base_quantity' => (float) $sellingUnit['base_quantity'],
                    'reason_codes' => $decision['reason_codes'],
                ],
            );
            $overrideApplied = true;
        }

        return response()->json([
            'data' => [
                'store_id' => $storeId,
                'customer_type' => $type,
                'customer_id' => $customer,
                'product_id' => $productId,
                'selling_unit' => $sellingUnit,
                'selling_units' => $commercial->sellingUnits($productId),
                'decision' => $decision,
                'override_applied' => $overrideApplied,
                'effective_allowed' => (bool) $decision['allowed'] || $overrideApplied,
            ],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function assertCustomerScope(
        Request $request,
        User $actor,
        string $type,
        int $customer,
    ): int {
        abort_unless(in_array($type, ['b2b', 'b2c'], true), 404);

        $storeIds = VanVisit::query()
            ->where('actor_user_id', $actor->getKey())
            ->where('customer_type', $type)
            ->where('customer_id', $customer)
            ->whereNotNull('store_id')
            ->distinct()
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        abort_if($storeIds->isEmpty(), 404);

        $requested = $request->input('store_id');
        if ($requested === null) {
            if ($storeIds->count() !== 1) {
                throw ValidationException::withMessages([
                    'store_id' => ['Store is required when the customer is in more than one assigned Van scope.'],
                ]);
            }

            return (int) $storeIds->first();
        }

        $storeId = (int) $requested;
        abort_unless($storeIds->contains($storeId), 404);

        return $storeId;
    }

    private function legacyCustomerId(string $type, int $customer): int
    {
        $legacyId = match ($type) {
            'b2b' => B2bCustomer::query()->whereKey($customer)->value('legacy_customer_id'),
            'b2c' => B2cCustomer::query()->whereKey($customer)->value('legacy_customer_id'),
            default => null,
        };

        if ($legacyId === null) {
            throw ValidationException::withMessages([
                'customer_id' => ['The selected customer is not linked to the canonical commercial customer domain.'],
            ]);
        }

        return (int) $legacyId;
    }
}
