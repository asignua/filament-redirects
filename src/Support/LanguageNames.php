<?php

declare(strict_types=1);

namespace Asignua\FilamentRedirects\Support;

use Asignua\FilamentRedirects\Redirects;

/**
 * Labels for the language selects and badges: the language's own name when ext-intl knows it,
 * the code otherwise.
 */
final class LanguageNames
{
    public static function name(string $language): string
    {
        if (function_exists('locale_get_display_name')) {
            $name = locale_get_display_name($language, $language);

            if ($name !== false && $name !== $language) {
                return mb_convert_case($name, MB_CASE_TITLE).' ('.$language.')';
            }
        }

        return $language;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (Redirects::localeUrls()->all() as $language) {
            $options[$language] = self::name($language);
        }

        return $options;
    }
}
