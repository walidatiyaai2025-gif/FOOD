<?php

namespace App\Support;

/**
 * Technical target-language code for the existing audited profile locale
 * mutation. Never render language codes as translated user-facing labels.
 */
final class V2SidebarUtilityLocaleTarget
{
    public function forLocale(string $locale): string
    {
        return $locale === 'ar' ? 'en' : 'ar';
    }
}
