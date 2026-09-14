<?php

declare(strict_types=1);

namespace App\Support;

final class Format
{
    public static function dateTime(string $value, array $preferences): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($preferences['timezone']))->format($preferences['locale'] === 'en' ? 'Y-m-d H:i T' : 'd.m.Y H:i T');
    }
}
