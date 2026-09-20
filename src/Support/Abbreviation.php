<?php

declare(strict_types=1);

namespace Naf\Board\Support;

/**
 * What a project is called when there is no room to call it anything.
 *
 * Both of these are offered, never imposed: somebody who types a key or a mark
 * keeps it. This is only what a project gets when the field is left alone, which
 * is what most people do -- and an empty ticket key used to be refused outright,
 * under a placeholder that promised it would be taken from the name.
 */
final class Abbreviation
{
    private const string VOWELS = 'AEIOU';

    /** Umlauts are not letters to a pattern of A-Z; they are the same letter wearing a hat. */
    private const array PLAIN = [
        'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ß' => 'SS',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ø' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U',
        'Ñ' => 'N', 'Ç' => 'C',
    ];

    /**
     * The key in front of every ticket number, from the project's name.
     *
     * Consonants, because they are what a word can be read back from: FLNCR is
     * Faulancer to anybody who knows the project, and FAULAN is not obviously
     * anything. The first letter stays whatever it is -- a name beginning with a
     * vowel would otherwise lose the one letter people recognise it by.
     *
     * A name with no consonants to spare keeps its letters as they are. One with
     * no letters at all gives nothing back rather than something invented, and
     * the caller decides what a project with such a name is called.
     */
    public static function key(string $name): string
    {
        $letters = self::letters($name);
        if ($letters === '') {
            return '';
        }

        $lean = $letters[0] . str_replace(str_split(self::VOWELS), '', substr($letters, 1));

        return substr($lean === '' ? $letters : $lean, 0, 6);
    }

    /** The one or two characters a project wears where its name will not fit. */
    public static function mark(string $name): string
    {
        $name = trim($name);

        return $name === '' ? '' : mb_substr($name, 0, 1);
    }

    /** Upper case, unaccented, and nothing but the characters a key may contain. */
    private static function letters(string $name): string
    {
        $upper = strtr(mb_strtoupper(trim($name)), self::PLAIN);

        return (string) preg_replace('/[^A-Z0-9]/', '', $upper);
    }
}
