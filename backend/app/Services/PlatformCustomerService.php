<?php

namespace App\Services;

use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\PlatformCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PlatformCustomerService
{
    /** @param array{name:string,email:string,phone:string,password:string,locale?:string,store_id?:int|null} $data */
    public function register(array $data, string $registrationSource = 'customer_app'): User
    {
        $origin = $this->resolveRegistrationOrigin(
            isset($data['store_id']) ? (int) $data['store_id'] : null,
        );
        $registrationSource = $this->normalizeRegistrationSource($registrationSource);
        $tierId = $this->resolveInitialWholesaleTierId($origin['channel'], $origin['store_id']);

        return DB::transaction(function () use ($data, $origin, $registrationSource, $tierId): User {
            $email = strtolower(trim((string) $data['email']));
            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['This email address is already registered.'],
                ]);
            }

            $user = User::query()->create([
                'name' => trim((string) $data['name']),
                'email' => $email,
                'password' => Hash::make((string) $data['password']),
                'locale' => in_array(($data['locale'] ?? 'ar'), ['ar', 'en'], true)
                    ? (string) ($data['locale'] ?? 'ar')
                    : 'ar',
                'is_active' => true,
                'is_platform_customer' => true,
            ]);

            $legacy = Customer::query()->create([
                'user_id' => $user->getKey(),
                'type' => 'platform',
                'name' => $user->name,
                'phone' => trim((string) $data['phone']),
                'email' => $email,
            ]);

            $platform = PlatformCustomer::query()->create([
                'user_id' => $user->getKey(),
                'legacy_customer_id' => $legacy->getKey(),
                'name' => $user->name,
                'phone' => trim((string) $data['phone']),
                'email' => $email,
                'origin_channel' => $origin['channel'],
                'origin_store_id' => $origin['store_id'],
                'registration_source' => $registrationSource,
                'registered_at' => now(),
                'is_active' => true,
            ]);

            $this->ensureWholesaleAccount($user, $platform, $tierId);

            if ($origin['channel'] === 'b2c' && $origin['store_id'] !== null) {
                $this->materializeB2c($user, $origin['store_id']);
            }

            return $user;
        });
    }

    public function forUser(User $user): ?PlatformCustomer
    {
        return PlatformCustomer::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->first();
    }

    public function materializeB2b(User $user): ?B2bCustomer
    {
        $platform = $this->forUser($user);
        if (! $platform instanceof PlatformCustomer) {
            return null;
        }

        return DB::transaction(function () use ($user, $platform): B2bCustomer {
            $tierId = $this->resolveInitialWholesaleTierId(
                (string) ($platform->origin_channel ?: 'unknown'),
                $platform->origin_store_id === null ? null : (int) $platform->origin_store_id,
            );

            return $this->ensureWholesaleAccount($user, $platform, $tierId);
        });
    }

    public function materializeB2c(User $user, int $storeId): ?B2cCustomer
    {
        $platform = $this->forUser($user);
        if (! $platform instanceof PlatformCustomer) {
            return null;
        }

        $exists = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->exists();

        abort_unless($exists, 404);

        return B2cCustomer::query()->firstOrCreate(
            [
                'user_id' => $user->getKey(),
                'store_id' => $storeId,
            ],
            [
                'legacy_customer_id' => $platform->legacy_customer_id,
                'name' => $platform->name,
                'phone' => $platform->phone,
                'email' => $platform->email,
            ],
        );
    }

    public function isPlatformCustomer(User $user): bool
    {
        return (bool) $user->is_platform_customer
            || $this->forUser($user) instanceof PlatformCustomer;
    }

    /**
     * @return array{channel:string,store_id:?int}
     */
    private function resolveRegistrationOrigin(?int $storeId): array
    {
        if ($storeId === null) {
            return [
                'channel' => 'b2b',
                'store_id' => $this->mainWholesaleStoreId(),
            ];
        }

        $store = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->first([
                'stores.id',
                'stores.code',
                'store_types.code as store_type_code',
            ]);

        if ($store === null) {
            throw ValidationException::withMessages([
                'store_id' => ['The selected registration store is unavailable.'],
            ]);
        }

        $channel = strtolower((string) $store->store_type_code);
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw ValidationException::withMessages([
                'store_id' => ['The selected registration store does not support customer commerce.'],
            ]);
        }

        if ($channel === 'b2b') {
            $principalStoreId = $this->mainWholesaleStoreId();
            if ($principalStoreId !== null && $principalStoreId !== (int) $store->id) {
                throw ValidationException::withMessages([
                    'store_id' => ['Customer registration is allowed only from the main Wholesale store.'],
                ]);
            }
        }

        return [
            'channel' => $channel,
            'store_id' => (int) $store->id,
        ];
    }

    private function resolveInitialWholesaleTierId(string $originChannel, ?int $originStoreId): int
    {
        if ($originChannel === 'b2c' && $originStoreId !== null) {
            $configuredTierId = DB::table('stores')
                ->where('id', $originStoreId)
                ->value('default_customer_wholesale_price_tier_id');

            if ($configuredTierId !== null) {
                $tierExists = DB::table('b2b_price_tiers')
                    ->where('id', $configuredTierId)
                    ->exists();

                if ($tierExists) {
                    return (int) $configuredTierId;
                }
            }
        }

        $tierId = DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');

        if ($tierId === null) {
            throw ValidationException::withMessages([
                'registration' => ['The default customer price tier is not configured.'],
            ]);
        }

        return (int) $tierId;
    }

    private function ensureWholesaleAccount(
        User $user,
        PlatformCustomer $platform,
        int $preferredTierId,
    ): B2bCustomer {
        $customer = B2bCustomer::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            [
                'legacy_customer_id' => $platform->legacy_customer_id,
                'name' => $platform->name,
                'phone' => $platform->phone,
                'email' => $platform->email,
            ],
        );

        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->orWhere('customer_id', $platform->legacy_customer_id)
            ->first();

        if (! $account instanceof B2bAccount) {
            $account = new B2bAccount([
                'customer_id' => $platform->legacy_customer_id,
                'b2b_customer_id' => $customer->getKey(),
                'price_tier_id' => $preferredTierId,
                'company_name' => $platform->name,
                'status' => 'active',
                'tax_number' => null,
                'credit_limit' => 0,
            ]);
        } else {
            if (
                $account->b2b_customer_id !== null
                && (int) $account->b2b_customer_id !== (int) $customer->getKey()
            ) {
                abort(409, 'Wholesale account compatibility mapping conflicts with the Platform Customer.');
            }

            $account->b2b_customer_id = $customer->getKey();
            $account->customer_id = $platform->legacy_customer_id;
            $account->company_name = $account->company_name ?: $platform->name;
            $account->status = 'active';

            if ($account->price_tier_id === null) {
                $account->price_tier_id = $preferredTierId;
            }
        }

        $account->save();

        return $customer->refresh();
    }

    private function mainWholesaleStoreId(): ?int
    {
        $configuredCode = trim((string) config('foodex.platform_wholesale_store_code', ''));

        $query = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B');

        if ($configuredCode !== '') {
            $configuredId = (clone $query)
                ->where('stores.code', $configuredCode)
                ->value('stores.id');

            if ($configuredId !== null) {
                return (int) $configuredId;
            }
        }

        $id = $query->orderBy('stores.id')->value('stores.id');

        return $id === null ? null : (int) $id;
    }

    private function normalizeRegistrationSource(string $source): string
    {
        $source = strtolower(trim($source));

        if (! in_array($source, ['customer_app', 'dashboard', 'import', 'migration'], true)) {
            throw new InvalidArgumentException('Unsupported Platform Customer registration source.');
        }

        return $source;
    }
}
