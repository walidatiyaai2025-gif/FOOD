<?php

namespace App\Domain\Assistant\Data;

final readonly class AiPrincipalContext
{
    /**
     * @param  list<string>  $roleCodes
     * @param  list<string>  $permissionCodes
     * @param  list<int>  $permittedStoreIds
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $retailerDataGrants
     */
    public function __construct(
        public int $actorUserId,
        public string $locale,
        public array $roleCodes,
        public array $permissionCodes,
        public string $businessScope,
        public ?string $channel,
        public ?int $activeStoreId,
        public array $permittedStoreIds,
        public array $pageContext,
        public array $retailerDataGrants,
        public string $correlationId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_user_id' => $this->actorUserId,
            'locale' => $this->locale,
            'role_codes' => $this->roleCodes,
            'permission_codes' => $this->permissionCodes,
            'business_scope' => $this->businessScope,
            'channel' => $this->channel,
            'active_store_id' => $this->activeStoreId,
            'permitted_store_ids' => $this->permittedStoreIds,
            'page_context' => $this->pageContext,
            'retailer_data_grants' => $this->retailerDataGrants,
            'correlation_id' => $this->correlationId,
        ];
    }
}
