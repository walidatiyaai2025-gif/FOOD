<?php

namespace App\Domain\Updater;

final readonly class UpdatePackageManifest
{
    public function __construct(
        public string $targetVersion,
        public string $minimumCurrentVersion,
        public string $sha256,
        public string $releaseNotes,
        public bool $containsMigrations,
    ) {}
}
