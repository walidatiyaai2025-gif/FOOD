<?php

namespace App\Enterprise\Search;

/**
 * Encode user text as a literal substring for SQL LIKE on MySQL/MariaDB and SQLite.
 *
 * The explicit ! escape marker avoids database-specific backslash behavior.
 */
final class LiteralLikePattern
{
    public static function contains(string $query): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query).'%';
    }
}
