<?php

namespace App\Services;

use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\FlashOffer;
use App\Models\VanVisit;
use Illuminate\Support\Facades\DB;

final class FlashOfferAudienceService
{
    /** @return list<int>|null */
    public function eligibleUserIds(FlashOffer $offer): ?array
    {
        $dimensions = [
            [$this->integerList($offer->audience_customer_ids), fn (array $ids): array => $this->usersForCustomers($ids)],
            [$this->integerList($offer->audience_customer_group_ids), fn (array $ids): array => $this->usersForGroups($ids)],
            [$this->stringList($offer->audience_regions), fn (array $regions): array => $this->usersForRegions($regions)],
            [$this->stringList($offer->audience_routes), fn (array $routes): array => $this->usersForRoutes($offer, $routes)],
        ];

        $eligible = null;
        $configured = false;
        foreach ($dimensions as [$values, $resolver]) {
            if ($values === []) {
                continue;
            }
            $configured = true;
            $resolved = array_values(array_unique($resolver($values)));
            $eligible = $eligible === null
                ? $resolved
                : array_values(array_intersect($eligible, $resolved));
        }

        return $configured ? array_values($eligible ?? []) : null;
    }

    public function isEligible(FlashOffer $offer, int $userId): bool
    {
        $eligible = $this->eligibleUserIds($offer);

        return $eligible === null || in_array($userId, $eligible, true);
    }

    /** @return list<int>|null */
    public function notificationUserIds(FlashOffer $offer, string $channel): ?array
    {
        $customerUsers = $this->eligibleUserIds($offer);
        if ($customerUsers === null || $channel === 'customer') {
            return $customerUsers;
        }

        if ($channel !== 'van' || $customerUsers === []) {
            return $customerUsers === [] ? [] : null;
        }

        $actors = [];
        foreach (VanVisit::query()->where('store_id', $offer->store_id)->get() as $visit) {
            $customerUserId = $this->visitCustomerUserId($visit);
            if ($customerUserId !== null && in_array($customerUserId, $customerUsers, true)) {
                $actors[] = (int) $visit->actor_user_id;
            }
        }

        return array_values(array_unique(array_filter($actors, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function usersForCustomers(array $ids): array
    {
        return DB::table('customers')
            ->whereIn('id', $ids)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function usersForGroups(array $ids): array
    {
        return DB::table('commercial_customer_group_members')
            ->join('customers', 'customers.id', '=', 'commercial_customer_group_members.customer_id')
            ->whereIn('commercial_customer_group_members.customer_group_id', $ids)
            ->whereNotNull('customers.user_id')
            ->pluck('customers.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $regions
     * @return list<int>
     */
    private function usersForRegions(array $regions): array
    {
        $wanted = array_map('mb_strtolower', $regions);
        $customerIds = DB::table('addresses')
            ->whereNotNull('customer_id')
            ->get(['customer_id', 'area', 'governorate'])
            ->filter(function (object $address) use ($wanted): bool {
                $values = array_values(array_filter([
                    mb_strtolower(trim((string) ($address->area ?? ''))),
                    mb_strtolower(trim((string) ($address->governorate ?? ''))),
                ]));

                return array_intersect($wanted, $values) !== [];
            })
            ->pluck('customer_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $customerIds === [] ? [] : $this->usersForCustomers($customerIds);
    }

    /**
     * @param  list<string>  $routes
     * @return list<int>
     */
    private function usersForRoutes(FlashOffer $offer, array $routes): array
    {
        $wanted = array_map('mb_strtolower', $routes);
        $users = [];

        foreach (VanVisit::query()->where('store_id', $offer->store_id)->get() as $visit) {
            $metadata = is_array($visit->metadata) ? $visit->metadata : [];
            $routeValues = array_values(array_filter(array_map(
                static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
                [
                    $metadata['route_id'] ?? null,
                    $metadata['route_code'] ?? null,
                    $metadata['route'] ?? null,
                ],
            )));
            if (array_intersect($wanted, $routeValues) === []) {
                continue;
            }

            $userId = $this->visitCustomerUserId($visit);
            if ($userId !== null) {
                $users[] = $userId;
            }
        }

        return array_values(array_unique($users));
    }

    private function visitCustomerUserId(VanVisit $visit): ?int
    {
        $value = match ((string) $visit->customer_type) {
            'b2b' => B2bCustomer::query()->whereKey((int) $visit->customer_id)->value('user_id'),
            'b2c' => B2cCustomer::query()->whereKey((int) $visit->customer_id)->value('user_id'),
            default => null,
        };

        return $value === null ? null : (int) $value;
    }

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(static fn ($item): string => trim((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }
}
