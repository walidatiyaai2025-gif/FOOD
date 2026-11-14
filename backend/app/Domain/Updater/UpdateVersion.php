<?php

namespace App\Domain\Updater;

use InvalidArgumentException;

final class UpdateVersion
{
    public static function normalizeInstalled(string $version): string
    {
        $version = trim($version);

        if (preg_match('/^(0|[1-9]\d*)\.0(\d{2,})$/', $version, $matches) === 1) {
            // A legacy compact patch can be longer than PHP_INT_MAX. Integer casts
            // silently saturate and could rewrite SystemVersion/history to a
            // different installed release before updater compatibility checks.
            $patch = ltrim($matches[2], '0');

            return $matches[1].'.0.'.($patch === '' ? '0' : $patch);
        }

        return $version;
    }

    public static function isCanonical(string $version): bool
    {
        // Absolute anchors reject trailing newlines in signed manifest versions.
        return preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/', $version) === 1;
    }

    /**
     * Compare unbounded canonical SemVer numeric fields without integer casts.
     * PHP version_compare treats distinct numbers above PHP_INT_MAX as equal.
     */
    public static function compareCanonical(string $left, string $right): int
    {
        if (! self::isCanonical($left) || ! self::isCanonical($right)) {
            throw new InvalidArgumentException('Versions must use canonical major.minor.patch format.');
        }

        $leftParts = explode('.', $left);
        $rightParts = explode('.', $right);

        foreach ($leftParts as $index => $part) {
            $other = $rightParts[$index];
            $lengthComparison = strlen($part) <=> strlen($other);
            if ($lengthComparison !== 0) {
                return $lengthComparison;
            }

            $digitComparison = strcmp($part, $other);
            if ($digitComparison !== 0) {
                return $digitComparison <=> 0;
            }
        }

        return 0;
    }
}
