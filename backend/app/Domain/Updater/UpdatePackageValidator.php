<?php

namespace App\Domain\Updater;

use InvalidArgumentException;

final class UpdatePackageValidator
{
    public function validate(UpdatePackageManifest $manifest, string $currentVersion, string $packagePath): array
    {
        $currentVersion = UpdateVersion::normalizeInstalled($currentVersion);

        if (! UpdateVersion::isCanonical($currentVersion)) {
            throw new InvalidArgumentException('Installed version is not a canonical major.minor.patch version.');
        }

        if (! UpdateVersion::isCanonical($manifest->targetVersion)
            || ! UpdateVersion::isCanonical($manifest->minimumCurrentVersion)) {
            throw new InvalidArgumentException('Update versions must use canonical major.minor.patch format.');
        }

        if (! is_file($packagePath) || ! is_readable($packagePath)) {
            throw new InvalidArgumentException('Update package is unavailable.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $manifest->sha256)) {
            throw new InvalidArgumentException('Update package hash is invalid.');
        }

        $actualHash = hash_file('sha256', $packagePath);
        if (! hash_equals(strtolower($manifest->sha256), strtolower((string) $actualHash))) {
            throw new InvalidArgumentException('Update package integrity check failed.');
        }

        if (version_compare($currentVersion, $manifest->minimumCurrentVersion, '<')) {
            throw new InvalidArgumentException('Current version is not supported by this update.');
        }

        if (version_compare($manifest->targetVersion, $currentVersion, '<=')) {
            throw new InvalidArgumentException('Target version must be newer than the installed version.');
        }

        return [
            'current_version' => $currentVersion,
            'target_version' => $manifest->targetVersion,
            'package_hash' => strtolower($manifest->sha256),
            'contains_migrations' => $manifest->containsMigrations,
        ];
    }
}
