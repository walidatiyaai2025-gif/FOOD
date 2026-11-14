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

        // An incorrect caller-supplied flag must never suppress a database
        // backup or migration step for a valid update archive. The HTTP upload
        // detects migrations too, but this is the service-level safety boundary.
        if (! $manifest->containsMigrations && class_exists(\ZipArchive::class)) {
            $archive = new \ZipArchive;

            if ($archive->open($packagePath) === true) {
                try {
                    // Migration-declaration validation runs before the update
                    // runtime's ZIP preflight. Apply the same entry-count limit
                    // here before inspecting any central-directory members.
                    // This validator is also used without an application
                    // container by its standalone PHPUnit unit suite.
                    $maxMembers = 50000;
                    if (\Illuminate\Container\Container::getInstance()->bound('config')) {
                        $maxMembers = max(1, (int) config('foodex.update_max_zip_members', 50000));
                    }

                    if ($archive->numFiles > $maxMembers) {
                        throw new InvalidArgumentException('Update archive exceeds the allowed member count.');
                    }

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
