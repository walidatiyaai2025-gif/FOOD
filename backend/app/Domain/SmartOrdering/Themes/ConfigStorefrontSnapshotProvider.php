<?php

namespace App\Domain\SmartOrdering\Themes;

use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;

final class ConfigStorefrontSnapshotProvider implements StorefrontSnapshotProvider
{
    public function published(string $siteKey): ?array
    {
        $snapshot = config("smart_ordering_storefront.storefronts.{$siteKey}.published");

        return is_array($snapshot) ? $snapshot : null;
    }

    public function revision(string $siteKey, string $revision): ?array
    {
        $revision = $revision === 'draft' ? 'draft' : 'published';
        $snapshot = config("smart_ordering_storefront.storefronts.{$siteKey}.{$revision}");

        return is_array($snapshot) ? $snapshot : null;
    }
}
