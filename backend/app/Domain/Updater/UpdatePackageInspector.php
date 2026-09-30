<?php

namespace App\Domain\Updater;

use RuntimeException;
use ZipArchive;

final class UpdatePackageInspector
{
    public function containsMigrations(string $packagePath): bool
    {
        $archive = new ZipArchive;

        if ($archive->open($packagePath) !== true) {
            throw new RuntimeException('Update package is not a valid ZIP archive.');
        }

        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = $archive->getNameIndex($index);
                if (! is_string($name)) {
                    continue;
                }

                $normalized = str_replace('\\', '/', ltrim($name, '/'));
                if (str_starts_with($normalized, 'backend/database/migrations/')
                    && ! str_ends_with($normalized, '/')) {
                    return true;
                }
            }

            return false;
        } finally {
            $archive->close();
        }
    }
}
