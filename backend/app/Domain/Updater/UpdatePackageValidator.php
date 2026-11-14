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

        $this->rejectSymbolicLinkPackagePath($packagePath);

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

        // A matching SHA-256 does not prove that the package is a valid ZIP.
        // Verify its structure before reporting success; real runtime preflight
        // must never be the first phase that discovers a corrupt archive.
        if (! class_exists(\ZipArchive::class)) {
            throw new InvalidArgumentException('ZIP extension is required to validate update packages.');
        }

        $archive = new \ZipArchive;
        if ($archive->open($packagePath) !== true) {
            throw new InvalidArgumentException('Update package is not a readable ZIP archive.');
        }

        try {
            // Standalone PHPUnit unit tests exercise this validator
            // without booting Laravel's config repository.
            $configuredMaxMembers = app()->bound('config')
                ? config('foodex.update_max_zip_members', 50000)
                : 50000;
            $maxMembers = max(1, (int) $configuredMaxMembers);
            if ($archive->numFiles > $maxMembers) {
                throw new InvalidArgumentException('Update archive exceeds the allowed member count.');
            }

            if (! $manifest->containsMigrations) {
                for ($index = 0; $index < $archive->numFiles; $index++) {
                    $name = $archive->getNameIndex($index);

                    if (! is_string($name)) {
                        continue;
                    }

                    // Normalize source spellings before the backup declaration check.
                    // Case-insensitive hosts can resolve Backend/Database/Migrations
                    // to the canonical migration directory: fail closed for either.
                    $relativePath = strtolower(str_replace('\\', '/', trim($name)));
                    if (str_starts_with($relativePath, 'backend/database/migrations/')
                        && str_ends_with($relativePath, '.php')) {
                        throw new InvalidArgumentException(
                            'Update package contains migrations but database backup was not declared.'
                        );
                    }
                }
            }
        } finally {
            $archive->close();
        }

        if (UpdateVersion::compareCanonical($currentVersion, $manifest->minimumCurrentVersion) < 0) {
            throw new InvalidArgumentException('Current version is not supported by this update.');
        }

        if (UpdateVersion::compareCanonical($manifest->targetVersion, $currentVersion) <= 0) {
            throw new InvalidArgumentException('Target version must be newer than the installed version.');
        }

        return [
            'current_version' => $currentVersion,
            'target_version' => $manifest->targetVersion,
            'package_hash' => strtolower($manifest->sha256),
            'contains_migrations' => $manifest->containsMigrations,
        ];
    }

    /**
     * Do not follow a linked ZIP or a linked parent directory when hashing.
     * A matching caller-supplied checksum does not make a filesystem alias safe.
     */
    private function rejectSymbolicLinkPackagePath(string $packagePath): void
    {
        for ($candidate = $packagePath; ; $candidate = dirname($candidate)) {
            clearstatcache(true, $candidate);

            if (is_link($candidate)) {
                throw new InvalidArgumentException('Update package path cannot be a symbolic link.');
            }

            if (dirname($candidate) === $candidate) {
                break;
            }
        }
    }
}
