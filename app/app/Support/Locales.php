<?php

declare(strict_types=1);

namespace App\Support;

use Naf\I18n\Support\Language;

/**
 * The languages this application actually speaks, which is a shorter list than the ones the
 * framework knows: a locale is on offer only once a translation file exists for it, so the
 * picker can never promise a language that would fall back to German keys.
 *
 * Names come from the framework, where each language is written in itself — someone who
 * cannot read the current language still recognises their own.
 */
final class Locales
{
    /**
     * Flags name countries, not languages, so this is a convenience for recognition and
     * never the identity of a locale; the written name beside it carries that.
     */
    private const FLAGS = [
        'de' => '🇩🇪',
        'en' => '🇬🇧',
        'fr' => '🇫🇷',
        'es' => '🇪🇸',
        'it' => '🇮🇹',
        'pt' => '🇵🇹',
        'nl' => '🇳🇱',
        'pl' => '🇵🇱',
        'sv' => '🇸🇪',
        'tr' => '🇹🇷',
        'uk' => '🇺🇦',
    ];

    /**
     * @return array<string,string> locale code to its name in that language
     */
    public static function available(): array
    {
        $names  = Language::labels();
        $found  = [];
        $folder = dirname(__DIR__) . '/Resources/lang';

        foreach (glob($folder . '/*.json') ?: [] as $file) {
            $code = basename($file, '.json');
            if (isset($names[$code])) {
                $found[$code] = $names[$code];
            }
        }
        ksort($found);

        return $found ?: ['de' => $names['de']];
    }

    public static function supports(mixed $locale): bool
    {
        return is_string($locale) && isset(self::available()[$locale]);
    }

    public static function name(string $locale): string
    {
        return self::available()[$locale] ?? Language::labels()[$locale] ?? $locale;
    }

    public static function flag(string $locale): string
    {
        return self::FLAGS[$locale] ?? '🏳';
    }
}
