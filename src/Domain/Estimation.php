<?php

declare(strict_types=1);

namespace Naf\Board\Domain;

use function Naf\Board\extensions;

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
        'tshirt'     => [1, 2, 3, 5, 8],
    ];

    /**
     * What a scale's numbers are shown as, where a number is not the point.
     *
     * T-shirt sizes are stored as the numbers above so a column still sums and a
     * project can switch scales without losing an estimate. The spacing is the
     * same as the points scale, because that is what people mean by the sizes.
     */
    public const NAMES = [
        'tshirt' => [1 => 'XS', 2 => 'S', 3 => 'M', 5 => 'L', 8 => 'XL'],
    ];

    public const LABELS = [
        'none'       => 'Keine Schätzung',
        'complexity' => 'Komplexität',
        'points'     => 'Story-Points',
        'tshirt'     => 'T-Shirt-Größen',
    ];

    /** What a column sum is called once it is more than a ticket count. */
    public const UNITS = [
        'complexity' => 'Kp',
        'points'     => 'SP',
    ];

    /** The range the database accepts, independent of the scale a project offers. */
    public const MAX = 1000;

    /**
     * The scale a project has chosen, if it is one that exists right now.
     *
     * A stored id whose plugin is missing stays stored; it simply resolves to
     * nothing offerable here, and the settings surface says so.
     */
    public static function scale(mixed $name): string
    {
        return is_string($name) && extensions()->estimationScales()->has($name) ? $name : 'none';
    }

    /** Whether a stored scale id has a definition at the moment. */
    public static function available(mixed $name): bool
    {
        return is_string($name) && extensions()->estimationScales()->has($name);
    }

    /** The unit a scale's numbers are counted in. */
    public static function unit(mixed $scale): string
    {
        return extensions()->estimationScales()->get(self::scale($scale))?->unit ?? '';
    }

    /** The translated name of a scale. */
    public static function label(mixed $scale): string
    {
        return extensions()->estimationScales()->get(self::scale($scale))?->label
            ?? self::LABELS['none'];
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
        return extensions()->estimationScales()->get(self::scale($scale))?->values ?? [];
    }

    /**
     * What a value is shown as: the scale's name for it, or the number itself.
     *
     * A value from an earlier scale has no name here and falls back to the
     * number, which is exactly what should happen -- it is off-scale and the
     * caller marks it as such.
     */
    public static function display(mixed $scale, int $value): string
    {
        $names = extensions()->estimationScales()->get(self::scale($scale))?->names ?? [];

        return $names[$value] ?? (string) $value;
    }

    /** Whether a scale names its values instead of counting in them. */
    public static function named(mixed $scale): bool
    {
        return (extensions()->estimationScales()->get(self::scale($scale))?->names ?? []) !== [];
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
