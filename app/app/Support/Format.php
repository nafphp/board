<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

use function Naf\I18n\t;

final class Format
{
    public static function dateTime(string $value, array $preferences): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($preferences['timezone']))
            ->format($preferences['locale'] === 'en' ? 'Y-m-d H:i T' : 'd.m.Y H:i T');
    }

    /**
     * Counts read as sentences, and the translator has no plural forms of its own.
     */
    public static function count(int $value, string $singular, string $plural): string
    {
        return $value . ' ' . t($value === 1 ? $singular : $plural);
    }
}
