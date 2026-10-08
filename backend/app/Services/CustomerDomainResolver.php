<?php

namespace App\Services;

use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Customer;
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

    public function existingB2b(User $user): ?B2bCustomer
    {
        $direct = $this->b2b->forUser($user);
        if ($direct instanceof B2bCustomer) {
            return $direct;
        }

        $platform = app(PlatformCustomerService::class)->forUser($user);
        if ($platform !== null) {
            $legacyLinked = B2bCustomer::query()
                ->where('legacy_customer_id', $platform->legacy_customer_id)
                ->first();

            if ($legacyLinked instanceof B2bCustomer) {
                return $legacyLinked;
            }
        }

        $linkedIds = DB::table('retail_wholesale_accounts')
            ->join(
                'b2b_accounts',
                'b2b_accounts.b2b_customer_id',
                '=',
                'retail_wholesale_accounts.b2b_customer_id',
            )
            ->where('retail_wholesale_accounts.owner_user_id', $user->getKey())
            ->where('b2b_accounts.status', 'active')
            ->pluck('retail_wholesale_accounts.b2b_customer_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        abort_if(
            $linkedIds->count() > 1,
            409,
            'Select an explicit Wholesale account context.',
        );

        if ($linkedIds->count() === 1) {
            return B2bCustomer::query()->find($linkedIds->first());
        }

        return null;
    }

    public function b2b(User $user): B2bCustomer
    {
        $customer = $this->existingB2b($user);

        if (! $customer instanceof B2bCustomer) {
            $customer = app(PlatformCustomerService::class)->materializeB2b($user);
        }

        if (! $customer instanceof B2bCustomer) {
            $legacy = Customer::query()
                ->where('user_id', $user->getKey())
                ->where('type', 'b2b')
                ->first();

            if ($legacy instanceof Customer) {
                $customer = B2bCustomer::query()->firstOrCreate(
                    ['legacy_customer_id' => $legacy->getKey()],
                    [
                        'user_id' => $user->getKey(),
                        'name' => $legacy->name,
                        'phone' => $legacy->phone,
                        'email' => $legacy->email,
                    ],
                );

                $this->reconcileLegacyB2bReferences($legacy->getKey(), $customer->getKey());
            }
        }

        abort_unless($customer instanceof B2bCustomer, 403, 'B2B customer profile is required.');

        return $customer;
    }

    public function b2bFromRequest(User $user, Request $request): B2bCustomer
    {
        $retailStoreId = $this->requestedRetailStoreId($request);
        if ($retailStoreId === null) {
            return $this->b2b($user);
        }

        $this->assertStoreChannel($retailStoreId, 'B2C');
        $supportAccess = $user->hasRole('SUPER_ADMIN')
            && filter_var($request->header('X-FOODEX-Support-Access'), FILTER_VALIDATE_BOOL);

        if (! $supportAccess) {
            abort_unless(in_array($retailStoreId, $this->entitledRetailStoreIds($user), true), 403, 'Wholesale purchasing is not enabled for this retail store manager.');
        }

        $customerId = DB::table('retail_wholesale_accounts')
            ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'retail_wholesale_accounts.b2b_customer_id')
            ->where('retail_wholesale_accounts.retail_store_id', $retailStoreId)
            ->where('b2b_accounts.status', 'active')
            ->whereNotNull('b2b_accounts.price_tier_id')
            ->value('retail_wholesale_accounts.b2b_customer_id');

        abort_if($customerId === null, 403, 'Wholesale entitlement is not configured for this retail store.');

        return B2bCustomer::query()->findOrFail((int) $customerId);
    }

    public function b2bAccountFromRequest(User $user, Request $request): B2bCustomer
    {
        $retailStoreId = $this->requestedRetailStoreId($request);
        if ($retailStoreId === null) {
            return $this->b2b($user);
        }

        $this->assertStoreChannel($retailStoreId, 'B2C');
        $supportAccess = $user->hasRole('SUPER_ADMIN')
            && filter_var($request->header('X-FOODEX-Support-Access'), FILTER_VALIDATE_BOOL);

        if (! $supportAccess) {
            abort_unless(
                in_array(
                    $retailStoreId,
                    app(RetailMerchantIdentityService::class)->ownedRetailStoreIds($user),
                    true,
                ),
                403,
                'Wholesale account and finance access is available only to the linked Retail store owner.',
            );
        }

        return $this->b2bFromRequest($user, $request);
    }

    /** @return list<int> */
    public function entitledRetailStoreIds(User $user): array
    {
        return app(RetailMerchantIdentityService::class)->wholesaleEntitledRetailStoreIds($user);
    }

    public function b2c(User $user, int $storeId): B2cCustomer
    {
        $this->assertStoreChannel($storeId, 'B2C');
        app(RetailMerchantIdentityService::class)->assertCanPurchaseFromRetailStore($user, $storeId);

        $customer = $this->b2c->forUserAndStore($user, $storeId);

        if (! $customer instanceof B2cCustomer) {
            $customer = app(PlatformCustomerService::class)->materializeB2c($user, $storeId);
        }

        if (! $customer instanceof B2cCustomer) {
            $legacy = Customer::query()
                ->where('user_id', $user->getKey())
                ->where('type', 'b2c')
                ->first();

            if ($legacy instanceof Customer && $this->legacyB2cCanMaterializeForStore($legacy->getKey(), $storeId)) {
                $customer = app(B2cCustomerService::class)->create($storeId, [
                    'name' => (string) $legacy->name,
                    'phone' => $legacy->phone,
                    'email' => $legacy->email,
                ], $user);

                DB::table('orders')
                    ->where('customer_id', $legacy->getKey())
                    ->where('store_id', $storeId)
                    ->where('channel', 'b2c')
                    ->whereNull('b2c_customer_id')
                    ->update(['b2c_customer_id' => $customer->getKey(), 'updated_at' => now()]);

                DB::table('carts')
                    ->where('customer_id', $legacy->getKey())
                    ->where('store_id', $storeId)
                    ->where('channel', 'b2c')
                    ->whereNull('b2c_customer_id')
                    ->update(['b2c_customer_id' => $customer->getKey(), 'updated_at' => now()]);
            }
        }

        abort_unless($customer instanceof B2cCustomer, 404);

        return $customer;
    }

    public function b2cFromRequest(User $user, Request $request): B2cCustomer
    {
        $storeId = $this->requestedStoreId($request);

        if ($storeId === null) {
            $ids = $this->b2c->storeIdsForUser($user);

            if ($ids === []) {
                $legacy = Customer::query()
                    ->where('user_id', $user->getKey())
                    ->where('type', 'b2c')
                    ->first();

                $candidateStores = DB::table('stores')
                    ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                    ->where('stores.is_active', true)
                    ->where('store_types.code', 'B2C')
                    ->pluck('stores.id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                if ($legacy instanceof Customer && count($candidateStores) === 1) {
                    $ids = [$candidateStores[0]];
                }
            }

            abort_if($ids === [], 403, 'B2C customer profile is required.');
            abort_if(count($ids) !== 1, 409, 'Select a retail store.');

            $storeId = $ids[0];
        }

        return $this->b2c($user, $storeId);
    }

    /** @return array{0: B2bCustomer|B2cCustomer, 1: string} */
    public function profile(User $user, Request $request): array
    {
        $requestedDomain = strtolower(trim((string) (
            $request->input('customer_domain')
            ?? $request->query('channel')
            ?? $request->header('X-FOODEX-Customer-Domain', '')
        )));
        $requestedStoreId = $this->requestedStoreId($request);

        if ($requestedDomain === 'b2b') {
            return [$this->b2bFromRequest($user, $request), 'b2b'];
        }

        if ($requestedStoreId !== null) {
            return [$this->b2c($user, $requestedStoreId), 'b2c'];
        }

        $b2b = $this->existingB2b($user);
        $b2cStoreIds = $this->b2c->storeIdsForUser($user);

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
    public function forStore(User $user, int $storeId, ?Request $request = null): array
    {
        $channel = strtolower((string) DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->value('store_types.code'));

        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 404);

        if ($channel === 'b2b') {
            abort_unless(
                $storeId === app(WholesalePrincipal::class)->storeId(),
                404,
                'Wholesale commerce is available only through the principal Wholesale Store.',
            );
        }

        return $channel === 'b2b'
            ? [($request === null ? $this->b2b($user) : $this->b2bFromRequest($user, $request)), 'b2b']
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

    private function legacyB2cCanMaterializeForStore(int $legacyCustomerId, int $storeId): bool
    {
        $hasStoreEvidence = DB::table('orders')
            ->where('customer_id', $legacyCustomerId)
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->exists()
            || DB::table('carts')
                ->where('customer_id', $legacyCustomerId)
                ->where('store_id', $storeId)
                ->where('channel', 'b2c')
                ->exists();

        if ($hasStoreEvidence) {
            return true;
        }

        $activeRetailStores = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->count();

        return $activeRetailStores === 1;
    }

    private function reconcileLegacyB2bReferences(int $legacyCustomerId, int $b2bCustomerId): void
    {
        DB::table('b2b_accounts')
            ->where('customer_id', $legacyCustomerId)
            ->whereNull('b2b_customer_id')
            ->update(['b2b_customer_id' => $b2bCustomerId, 'updated_at' => now()]);

        DB::table('orders')
            ->where('customer_id', $legacyCustomerId)
            ->where('channel', 'b2b')
            ->whereNull('b2b_customer_id')
            ->update(['b2b_customer_id' => $b2bCustomerId, 'updated_at' => now()]);

        DB::table('carts')
            ->where('customer_id', $legacyCustomerId)
            ->where('channel', 'b2b')
            ->whereNull('b2b_customer_id')
            ->update(['b2b_customer_id' => $b2bCustomerId, 'updated_at' => now()]);

        DB::table('invoices')
            ->where('customer_id', $legacyCustomerId)
            ->whereNull('b2b_customer_id')
            ->update(['b2b_customer_id' => $b2bCustomerId, 'updated_at' => now()]);

        DB::table('addresses')
            ->where('customer_id', $legacyCustomerId)
            ->whereNull('b2b_customer_id')
            ->whereNull('b2c_customer_id')
            ->update(['b2b_customer_id' => $b2bCustomerId, 'updated_at' => now()]);
    }

    private function requestedRetailStoreId(Request $request): ?int
    {
        foreach ([
            $request->input('retail_store_id'),
            $request->query('retail_store_id'),
            $request->header('X-FOODEX-Retail-Store-ID'),
        ] as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function requestedStoreId(Request $request): ?int
    {
        foreach ([
            $request->input('store_id'),
            $request->query('store'),
            $request->header('X-FOODEX-Store-ID'),
            $request->header('X-FOODEX-Retail-Store-ID'),
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
