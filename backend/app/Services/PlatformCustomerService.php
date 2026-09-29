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

final class PlatformCustomerService
{
    /** @param array{name:string,email:string,phone?:?string,password:string,locale?:string} $data */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
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
            ]);

            $legacy = Customer::query()->create([
                'user_id' => $user->getKey(),
                'type' => 'platform',
                'name' => $user->name,
                'phone' => $data['phone'] ?? null,
                'email' => $email,
            ]);

            PlatformCustomer::query()->create([
                'user_id' => $user->getKey(),
                'legacy_customer_id' => $legacy->getKey(),
                'name' => $user->name,
                'phone' => $data['phone'] ?? null,
                'email' => $email,
                'is_active' => true,
            ]);

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
            $customer = B2bCustomer::query()->firstOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'legacy_customer_id' => $platform->legacy_customer_id,
                    'name' => $platform->name,
                    'phone' => $platform->phone,
                    'email' => $platform->email,
                ],
            );

            $tierId = DB::table('b2b_price_tiers')
                ->orderBy('priority')
                ->orderBy('id')
                ->value('id');

            B2bAccount::query()->firstOrCreate(
                ['b2b_customer_id' => $customer->getKey()],
                [
                    'customer_id' => $platform->legacy_customer_id,
                    'price_tier_id' => $tierId === null ? null : (int) $tierId,
                    'company_name' => $platform->name,
                    'status' => 'active',
                    'tax_number' => null,
                    'credit_limit' => 0,
                ],
            );

            return $customer->refresh();
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
        return $this->forUser($user) instanceof PlatformCustomer;
    }
}
