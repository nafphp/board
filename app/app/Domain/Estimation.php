<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Complexity and story points are the same number read on two different scales, so a
 * project picks one and the stored value is interpreted through it. Switching the scale
 * reinterprets what is already there instead of moving it into a second field.
 */
final class Estimation
{
    public const SCALES = [
        'none'       => [],
        'complexity' => [1, 2, 3, 4, 5],
        'points'     => [1, 2, 3, 5, 8, 13, 21],
    ];

    public const LABELS = [
        'none'       => 'Keine Schätzung',
        'complexity' => 'Komplexität',
        'points'     => 'Story-Points',
    ];

    /** What a column sum is called once it is more than a ticket count. */
    public const UNITS = [
        'complexity' => 'Kp',
        'points'     => 'SP',
    ];

    /** The range the database accepts, independent of the scale a project offers. */
    public const MAX = 1000;

    public static function scale(mixed $name): string
    {
        return is_string($name) && isset(self::SCALES[$name]) ? $name : 'none';
    }

    public static function active(mixed $scale): bool
    {
        return self::scale($scale) !== 'none';
    }

    /**
     * @return list<int>
     */
    public static function values(mixed $scale): array
    {
        return self::SCALES[self::scale($scale)];
    }

    /** A value kept from an earlier scale, which this project no longer offers. */
    public static function offScale(mixed $scale, ?int $value): bool
    {
        return $value !== null
            && self::active($scale)
            && !in_array($value, self::values($scale), true);
    }

    /** The closest value the current scale does offer, for the one-off remap. */
    public static function nearest(mixed $scale, int $value): int
    {
        $values = self::values($scale);
        if (!$values) {
            return $value;
        }
        usort($values, static fn($a, $b) => abs($a - $value) <=> abs($b - $value) ?: $a <=> $b);

        return $values[0];
    }
}
