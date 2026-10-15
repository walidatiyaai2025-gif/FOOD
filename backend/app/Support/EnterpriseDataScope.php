<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class EnterpriseDataScope
{
    public function __construct(
        public int $organizationId,
        public int $companyId,
        public int $legalEntityId,
        public int $businessUnitId,
        public int $storeId,
    ) {
        foreach ([
            $this->organizationId,
            $this->companyId,
            $this->legalEntityId,
            $this->businessUnitId,
            $this->storeId,
        ] as $id) {
            if ($id < 1) {
                throw new InvalidArgumentException('Enterprise data scope identifiers must be positive.');
            }
        }
    }

    /**
     * @param  array{organization_id:int,company_id:int,legal_entity_id:int,business_unit_id:int,store_id:int}  $scope
     */
    public static function fromHierarchy(array $scope): self
    {
        return new self(
            organizationId: $scope['organization_id'],
            companyId: $scope['company_id'],
            legalEntityId: $scope['legal_entity_id'],
            businessUnitId: $scope['business_unit_id'],
            storeId: $scope['store_id'],
        );
    }

    public function contains(string $type, int $id): bool
    {
        return match ($type) {
            'organization' => $this->organizationId === $id,
            'company' => $this->companyId === $id,
            'legal_entity' => $this->legalEntityId === $id,
            'business_unit' => $this->businessUnitId === $id,
            'store' => $this->storeId === $id,
            default => false,
        };
    }
}
