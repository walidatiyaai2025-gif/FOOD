<?php

namespace App\Domain\SmartOrdering;

use App\Models\SmartOrderingBranchScope;
use App\Models\SmartOrderingSite;

final readonly class ResolvedSmartOrderingContext
{
    public function __construct(
        public SmartOrderingSite $site,
        public ?SmartOrderingBranchScope $branch = null,
        public bool $resolvedFromAlias = false,
        public bool $resolvedFromCustomDomain = false,
    ) {}
}
