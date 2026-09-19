<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Board\Domain\Failure;

/**
 * Durations are stored as whole minutes but written the way people say them: 2h 40m, 90m,
 * 1:30 or a bare 90. A bare decimal stays refused, because "1.5" alone says neither
 * minutes nor hours; with a unit, "1.5h" is unambiguous and welcome.
 */
final class Duration
{
    public const MAX = 10_000_000;

    public static function parse(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return self::bounded($value, $field);
        }
        if (!is_string($value)) {
            throw self::refuse($field);
        }
        $text = strtolower(preg_replace('/\s+/', '', $value) ?? '');
        if ($text === '') {
            return null;
        }
        // 1:30 — hours and minutes, the way a clock writes them.
        if (preg_match('/^(\d{1,6}):([0-5]\d)$/D', $text, $found)) {
            return self::bounded((int) $found[1] * 60 + (int) $found[2], $field);
        }
        // A bare number is minutes, and only whole ones.
        if (preg_match('/^\d{1,9}$/D', $text)) {
            return self::bounded((int) $text, $field);
        }
        // 2h30 — the minutes keep their unit implied by the hours in front of them.
        if (preg_match('/^(\d{1,6})h(\d{1,2})$/D', $text, $found)) {
            return self::bounded((int) $found[1] * 60 + (int) $found[2], $field);
        }
        // 2h40m, 2h, 40m, 45min, 1.5h — either part may be left out, but not both.
        $number = '\d+(?:[.,]\d+)?';
        if (
            preg_match('/^(?:(' . $number . ')h)?(?:(' . $number . ')m(?:in)?)?$/D', $text, $found)
            && (($found[1] ?? '') !== '' || ($found[2] ?? '') !== '')
        ) {
            $hours   = (float) str_replace(',', '.', $found[1] ?? '0');
            $minutes = (float) str_replace(',', '.', $found[2] ?? '0');

            return self::bounded((int) round($hours * 60 + $minutes), $field);
        }

        throw self::refuse($field);
    }

    /** The same shape the input accepts, so what is shown can be edited as it stands. */
    public static function format(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;
        if ($hours && $rest) {
            return $hours . 'h ' . $rest . 'm';
        }

        return $hours ? $hours . 'h' : $rest . 'm';
    }

    private static function bounded(int $minutes, string $field): int
    {
        if ($minutes < 0 || $minutes > self::MAX) {
            throw self::refuse($field);
        }

        return $minutes;
    }

    private static function refuse(string $field): Failure
    {
        return new Failure('Bitte gib eine Dauer wie 2h 40m, 90m oder 1:30 an.', 422, [
            $field => ['Ungültige Dauer.'],
        ]);
    }
}
