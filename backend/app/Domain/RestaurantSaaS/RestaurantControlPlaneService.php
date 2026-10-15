<?php

namespace App\Domain\RestaurantSaaS;

use App\Domain\RestaurantSaaS\Models\RestaurantLocationProfile;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseOrganization;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RestaurantControlPlaneService
{
    public function __construct(
        private readonly OrganizationHierarchyService $organizations,
        private readonly SmartOrderingSiteService $sites,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Provision only mapping/profile records. Existing Store and Enterprise identities remain authoritative.
     *
     * @return array<string, mixed>
     */
    public function provision(Store $store, User $actor, ?Request $request = null): array
    {
        DB::transaction(function () use ($store, $actor, $request): void {
            $this->organizations->ensureDefaultScopeForStore($store, $actor);

            $profile = RestaurantLocationProfile::query()->firstOrCreate(
                ['store_id' => $store->getKey()],
                ['lifecycle_state' => 'DRAFT'],
            );

            $this->sites->ensureForStore((int) $store->getKey(), $actor, $request);

            if ($profile->wasRecentlyCreated) {
                $this->audit->record(
                    'restaurant_saas.location.provisioned',
                    $actor,
                    $store,
                    null,
                    [
                        'store_id' => (int) $store->getKey(),
                        'lifecycle_state' => 'DRAFT',
                    ],
                    $request,
                );
            }
        });

        return $this->context($store);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function updateLocationProfile(
        Store $store,
        User $actor,
        array $values,
        ?Request $request = null,
    ): array {
        $profile = RestaurantLocationProfile::query()
            ->where('store_id', $store->getKey())
            ->first();

        abort_unless($profile instanceof RestaurantLocationProfile, 409, 'Restaurant location is not provisioned.');

        $before = $this->profileSnapshot($profile);

        $profile->fill($values);
        $profile->save();

        $after = $this->profileSnapshot($profile->refresh());

        if ($before !== $after) {
            $this->audit->record(
                'restaurant_saas.location.profile_updated',
                $actor,
                $store,
                $before,
                $after,
                $request,
            );
        }

        return $this->context($store);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(Store $store): array
    {
        $scope = $this->organizations->scopeForStore((int) $store->getKey());
        $organization = $scope === null
            ? null
            : EnterpriseOrganization::query()->find($scope['organization_id']);
        $brand = $scope === null
            ? null
            : EnterpriseCompany::query()->find($scope['company_id']);
        $profile = RestaurantLocationProfile::query()
            ->where('store_id', $store->getKey())
            ->first();
        $site = SmartOrderingSite::query()
            ->where('store_id', $store->getKey())
            ->first();

        return [
            'provisioned' => $scope !== null && $profile instanceof RestaurantLocationProfile,
            'account' => $organization === null ? null : [
                'id' => (int) $organization->getKey(),
                'code' => (string) $organization->code,
                'name' => (string) $organization->name,
            ],
            'organization' => $organization === null ? null : [
                'id' => (int) $organization->getKey(),
                'code' => (string) $organization->code,
                'name' => (string) $organization->name,
            ],
            'brand' => $brand === null ? null : [
                'id' => (int) $brand->getKey(),
                'code' => (string) $brand->code,
                'name' => (string) $brand->name,
            ],
            'location' => [
                'id' => (int) $store->getKey(),
                'code' => (string) $store->code,
                'name' => (string) $store->name,
                'is_active' => (bool) $store->is_active,
                'lifecycle_state' => $profile?->lifecycle_state,
                'timezone' => $profile?->timezone,
                'currency' => $profile?->currency,
                'country_code' => $profile?->country_code,
                'business_name' => $profile?->business_name,
                'legal_name' => $profile?->legal_name,
                'tax_registration_number' => $profile?->tax_registration_number,
            ],
            'scope' => $scope,
            'entitlement_scope' => $scope === null
                ? null
                : (new EntitlementScope(EntitlementScope::STORE, (int) $store->getKey()))->key(),
            'smart_ordering_site' => $site === null ? null : [
                'id' => (string) $site->public_id,
                'slug' => (string) $site->slug,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileSnapshot(RestaurantLocationProfile $profile): array
    {
        return [
            'store_id' => (int) $profile->store_id,
            'lifecycle_state' => (string) $profile->lifecycle_state,
            'timezone' => $profile->timezone,
            'currency' => $profile->currency,
            'country_code' => $profile->country_code,
            'business_name' => $profile->business_name,
            'legal_name' => $profile->legal_name,
            'tax_registration_number' => $profile->tax_registration_number,
        ];
    }
}
