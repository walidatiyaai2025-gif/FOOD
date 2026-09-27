<?php

namespace App\Services;

use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\User;
use App\Repositories\B2bCustomerRepository;
use App\Repositories\B2cCustomerRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CustomerDomainResolver
{
    public function __construct(
        private readonly B2bCustomerRepository $b2b,
        private readonly B2cCustomerRepository $b2c,
    ) {}

    public function b2b(User $user): B2bCustomer
    {
        $customer = $this->b2b->forUser($user);
        abort_unless($customer instanceof B2bCustomer, 403, 'B2B customer profile is required.');

        return $customer;
    }

    public function b2c(User $user, int $storeId): B2cCustomer
    {
        $this->assertStoreChannel($storeId, 'B2C');

        $customer = $this->b2c->forUserAndStore($user, $storeId);
        abort_unless($customer instanceof B2cCustomer, 404);

        return $customer;
    }

    public function b2cFromRequest(User $user, Request $request): B2cCustomer
    {
        $storeId = $this->requestedStoreId($request);

        if ($storeId === null) {
            $ids = $this->b2c->storeIdsForUser($user);
            abort_if($ids === [], 403, 'B2C customer profile is required.');
            abort_if(count($ids) !== 1, 409, 'Select a retail store.');

            $storeId = $ids[0];
        }

        return $this->b2c($user, $storeId);
    }

    /** @return array{0: B2bCustomer|B2cCustomer, 1: string} */
    public function profile(User $user, Request $request): array
    {
        $requestedStoreId = $this->requestedStoreId($request);
        if ($requestedStoreId !== null) {
            return [$this->b2c($user, $requestedStoreId), 'b2c'];
        }

        $b2b = $this->b2b->forUser($user);
        $b2cStoreIds = $this->b2c->storeIdsForUser($user);
        $requestedDomain = strtolower(trim((string) (
            $request->input('customer_domain')
            ?? $request->query('channel')
            ?? $request->header('X-FOODEX-Customer-Domain', '')
        )));

        if ($requestedDomain === 'b2b') {
            abort_unless($b2b instanceof B2bCustomer, 404);

            return [$b2b, 'b2b'];
        }

        if ($requestedDomain === 'b2c') {
            abort_if(count($b2cStoreIds) !== 1, 409, 'Select a retail store.');

            return [$this->b2c($user, $b2cStoreIds[0]), 'b2c'];
        }

        if ($b2b instanceof B2bCustomer && $b2cStoreIds === []) {
            return [$b2b, 'b2b'];
        }

        if (! $b2b instanceof B2bCustomer && count($b2cStoreIds) === 1) {
            return [$this->b2c($user, $b2cStoreIds[0]), 'b2c'];
        }

        abort_if($b2b instanceof B2bCustomer || $b2cStoreIds !== [], 409, 'Select a customer domain.');
        abort(403, 'Customer profile is required.');
    }

    /** @return array{0: B2bCustomer|B2cCustomer, 1: string} */
    public function forStore(User $user, int $storeId): array
    {
        $channel = strtolower((string) DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->value('store_types.code'));

        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 404);

        return $channel === 'b2b'
            ? [$this->b2b($user), 'b2b']
            : [$this->b2c($user, $storeId), 'b2c'];
    }

    public function legacyId(B2bCustomer|B2cCustomer $customer): int
    {
        abort_if(
            $customer->legacy_customer_id === null,
            409,
            'Customer compatibility mapping is incomplete.',
        );

        return (int) $customer->legacy_customer_id;
    }

    private function requestedStoreId(Request $request): ?int
    {
        foreach ([
            $request->input('store_id'),
            $request->query('store'),
            $request->header('X-FOODEX-Store-ID'),
        ] as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function assertStoreChannel(int $storeId, string $channel): void
    {
        $exists = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', strtoupper($channel))
            ->exists();

        abort_unless($exists, 404);
    }
}
