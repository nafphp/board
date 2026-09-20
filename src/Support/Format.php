<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use DateTimeImmutable;
use DateTimeZone;

use function Naf\I18n\t;

/** @internal */
final class Format
{
    public static function dateTime(string $value, array $preferences): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($preferences['timezone']))
            ->format($preferences['locale'] === 'en' ? 'Y-m-d H:i T' : 'd.m.Y H:i T');
    }

    /**
     * The same moment, split into the day it belongs to and the time of day.
     *
     * A log reads as days: one heading, then the times under it. Splitting here
     * rather than in the template keeps the timezone in one place -- a log whose
     * headings and rows disagree about which day it is would be worse than one
     * without headings.
     *
     * @return array{day: string, date: string, time: string}
     */
    public static function moment(string $value, array $preferences): array
    {
        $local = (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($preferences['timezone']));
        $english = $preferences['locale'] === 'en';

        return [
            'day'  => $local->format('Y-m-d'),
            'date' => $local->format($english ? 'D, j M Y' : 'D, j.n.Y'),
            'time' => $local->format('H:i'),
        ];
    }

    /**
     * How a ticket is named everywhere: in the interface and in its address.
     */
    public static function ticket(string $key, int|string $number): string
    {
        return $key . '-' . (int) $number;
    }

    /**
     * Counts read as sentences, and the translator has no plural forms of its own.
     */
    public static function count(int $value, string $singular, string $plural): string
    {
        return $value . ' ' . t($value === 1 ? $singular : $plural);
    }
}
