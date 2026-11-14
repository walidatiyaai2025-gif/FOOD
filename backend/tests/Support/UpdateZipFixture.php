<?php

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Produce an actual harmless ZIP for simulated updater lifecycle tests.
 *
 * A plain-text placeholder with a matching hash is not a valid update package:
 * validation must reject it before reaching any mocked runtime stages.
 */
final class UpdateZipFixture
{
    public static function write(string $path): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Updater test fixtures require the ZIP extension.');
        }

        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open updater test ZIP.');
        }

        if (! $archive->addFromString('release-notes.txt', 'Synthetic update test fixture')) {
            $archive->close();

            throw new RuntimeException('Could not populate updater test ZIP.');
        }

        if (! $archive->close()) {
            throw new RuntimeException('Could not finalize updater test ZIP.');
        }
    }

    public static function bytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-update-zip-');
        if ($path === false) {
            throw new RuntimeException('Could not allocate updater test ZIP.');
        }

        try {
            self::write($path);
            $contents = file_get_contents($path);
            if (! is_string($contents)) {
                throw new RuntimeException('Could not read updater test ZIP.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }
}
