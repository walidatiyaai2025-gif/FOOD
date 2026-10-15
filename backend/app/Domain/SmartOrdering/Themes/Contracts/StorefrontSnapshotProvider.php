<?php

namespace App\Domain\SmartOrdering\Themes\Contracts;

interface StorefrontSnapshotProvider
{
    public function published(string $siteKey): ?array;

    public function revision(string $siteKey, string $revision): ?array;
}
