<?php

namespace App\Domain\SmartOrdering;

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
}
