<?php

namespace App\Enterprise\Finance;

final readonly class FinancePostingApprovalContext
{
    public function __construct(
        public string $action,
        public string $entityType,
        public string $entityId,
        public int $makerId,
        public string $amount,
        public ?string $currencyCode = null,
    ) {}
}
