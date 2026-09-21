<?php

declare(strict_types=1);

namespace Naf\Board\Domain;

/**
 * Where a new ticket is put in its column: at the top or at the bottom.
 *
 * Constants and not a registry, unlike the priorities and the estimation scales
 * next door. Those are open because somebody will always want one more; this is
 * a list with two entries and no third -- the only other place a card could go
 * is somewhere in the middle, which nobody means when they say where new
 * tickets should land. A set that is genuinely closed is worth closing.
 *
 * Two questions, not one. A board answers "what happens here", a person answers
 * "what happens to the ones I create", and a person who has not answered defers
 * to the board. That is what `INHERIT` is: not a third place, but the absence of
 * an opinion.
 */
final class Placement
{
    public const string TOP     = 'top';
    public const string BOTTOM  = 'bottom';
    public const string INHERIT = 'inherit';

    /** What a board may be set to; anything else is the way it has always been. */
    public static function board(mixed $value): string
    {
        return $value === self::TOP ? self::TOP : self::BOTTOM;
    }

    /** What a person may be set to, including having no opinion. */
    public static function person(mixed $value): string
    {
        return in_array($value, [self::TOP, self::BOTTOM], true) ? (string) $value : self::INHERIT;
    }

    /** The one that actually applies. */
    public static function resolve(mixed $person, mixed $board): string
    {
        $mine = self::person($person);

        return $mine === self::INHERIT ? self::board($board) : $mine;
    }

    /** @return array<string, string> the choices a board is offered */
    public static function forBoard(): array
    {
        return [self::BOTTOM => 'Unten anhängen', self::TOP => 'Oben einfügen'];
    }

    /** @return array<string, string> the choices a person is offered */
    public static function forPerson(): array
    {
        return [self::INHERIT => 'Wie im Projekt eingestellt'] + self::forBoard();
    }
}
