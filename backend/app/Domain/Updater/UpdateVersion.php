<?php

namespace App\Domain\Updater;

final class UpdateVersion
{
    public static function normalizeInstalled(string $version): string
    {
        $version = trim($version);

        if (preg_match('/^(0|[1-9]\d*)\.0(\d{2,})$/', $version, $matches) === 1) {
            return ((string) ((int) $matches[1])).'.0.'.((string) ((int) $matches[2]));
        }

        return $version;
    }

    public static function isCanonical(string $version): bool
    {
        return preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', trim($version)) === 1;
    }
}
