<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;

final class RestaurantSiteResolver
{
    public function __construct(private readonly SmartOrderingRuntimePolicy $runtime) {}

    public function fromHost(string $host): ?SmartOrderingSite
    {
        return $this->runtime->resolveByHost($host);
    }

    public function fromSlug(string $slug): ?SmartOrderingSite
    {
        return $this->runtime->resolveBySlug($slug);
    }

    public function branch(SmartOrderingSite $site, string $publicId): SmartOrderingBranch
    {
        return $this->runtime->branch($site, $publicId);
    }
}
