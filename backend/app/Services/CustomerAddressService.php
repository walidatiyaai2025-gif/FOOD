<?php

namespace App\Services;

use App\Models\Address;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\PlatformCustomer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class CustomerAddressService
{
    public function platformCustomer(User $user): ?PlatformCustomer
    {
        return app(PlatformCustomerService::class)->forUser($user);
    }

    /**
     * @return Builder<Address>
     */
    public function queryFor(
        User $user,
        B2bCustomer|B2cCustomer|null $domainCustomer = null,
        ?string $channel = null,
    ): Builder {
        $channel = $this->normalizeChannel($channel);
        $platformCustomer = $this->platformCustomer($user);

        if ($platformCustomer instanceof PlatformCustomer) {
            return Address::query()
                ->where('commerce_channel', $channel)
                ->where(function (Builder $query) use ($platformCustomer): void {
                    $query->where('platform_customer_id', $platformCustomer->getKey())
                        ->orWhere(function (Builder $legacy) use ($platformCustomer): void {
                            // Backward-compatible adoption path for addresses created by
                            // pre-unification code after the migration has already run.
                            $legacy->whereNull('platform_customer_id')
                                ->where('customer_id', $platformCustomer->legacy_customer_id);
                        });
                });
        }

        abort_unless(
            $domainCustomer instanceof B2bCustomer || $domainCustomer instanceof B2cCustomer,
            403,
            'Customer profile is required.',
        );
        // Domain-owned legacy rows are already partitioned by their explicit
        // B2B/B2C foreign key. Do not require the new marker here so existing
        // integrations that insert those rows directly remain compatible.
        return Address::query()
            ->where(
                $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id',
                $domainCustomer->getKey(),
            );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function ownerAttributes(
        User $user,
        B2bCustomer|B2cCustomer|null $domainCustomer = null,
        ?string $channel = null,
    ): array {
        $channel = $this->normalizeChannel($channel);
        $platformCustomer = $this->platformCustomer($user);

        if ($platformCustomer instanceof PlatformCustomer) {
            return [
                'customer_id' => (int) $platformCustomer->legacy_customer_id,
                'platform_customer_id' => (int) $platformCustomer->getKey(),
                'b2b_customer_id' => $channel === 'b2b' && $domainCustomer instanceof B2bCustomer
                    ? (int) $domainCustomer->getKey()
                    : null,
                'b2c_customer_id' => $channel === 'b2c' && $domainCustomer instanceof B2cCustomer
                    ? (int) $domainCustomer->getKey()
                    : null,
                'commerce_channel' => $channel,
            ];
        }

        abort_unless(
            $domainCustomer instanceof B2bCustomer || $domainCustomer instanceof B2cCustomer,
            403,
            'Customer profile is required.',
        );
        return [
            'customer_id' => app(CustomerDomainResolver::class)->legacyId($domainCustomer),
            'platform_customer_id' => null,
            'b2b_customer_id' => $channel === 'b2b' ? (int) $domainCustomer->getKey() : null,
            'b2c_customer_id' => $channel === 'b2c' ? (int) $domainCustomer->getKey() : null,
            'commerce_channel' => $channel,
        ];
    }

    public function findOwned(
        User $user,
        int $addressId,
        B2bCustomer|B2cCustomer|null $domainCustomer = null,
        ?string $channel = null,
    ): Address {
        return $this->queryFor($user, $domainCustomer, $channel)
            ->whereKey($addressId)
            ->firstOrFail();
    }

    private function normalizeChannel(?string $channel): string
    {
        $normalized = strtolower(trim((string) $channel));

        abort_unless(
            in_array($normalized, ['b2b', 'b2c'], true),
            400,
            'Customer address commerce channel is required.',
        );

        return $normalized;
    }
}
