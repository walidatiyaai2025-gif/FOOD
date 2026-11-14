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

        // Every opened ZIP must satisfy the same central-directory member budget
        // as the runtime parser. The validator runs before runtime preflight and
        // must not scan an unbounded number of zero-byte members to check whether
        // a caller concealed migrations in the manifest.
        if (class_exists(\ZipArchive::class)) {
            $archive = new \ZipArchive;

            if ($archive->open($packagePath) === true) {
                try {
                    $maxMembers = max(1, (int) config('foodex.update_max_zip_members', 50000));
                    if ($archive->numFiles > $maxMembers) {
                        throw new InvalidArgumentException('Update archive exceeds the allowed member count.');
                    }

                    if (! $manifest->containsMigrations) {
                        for ($index = 0; $index < $archive->numFiles; $index++) {
                            $name = $archive->getNameIndex($index);

                            if (! is_string($name)) {
                                continue;
                            }

                            // Match LaravelUpdateRuntime::safeRelativePath: a ZIP name
                            // padded with whitespace must not hide a migration
                            // from the mandatory database-backup declaration.
                            $relativePath = str_replace('\\', '/', trim($name));
                            if (str_starts_with($relativePath, 'backend/database/migrations/')
                                && str_ends_with(strtolower($relativePath), '.php')) {
                                throw new InvalidArgumentException(
                                    'Update package contains migrations but database backup was not declared.'
                                );
                            }
                        }
                    }
                } finally {
                    $archive->close();
                }
            }
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
