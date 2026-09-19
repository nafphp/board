<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Board\Domain\Failure;

/**
 * Material Symbols are drawn by a ligature: the element's text is the icon's name and the
 * font turns it into the glyph. That text must never reach a screen reader as a word, so
 * every icon is hidden from the accessibility tree here and the surrounding control carries
 * the label instead.
 */
final class Icon
{
    public static function mark(string $name, string $size = 'md', string $class = ''): string
    {
        if (!preg_match('/^[a-z0-9_]+$/D', $name)) {
            throw new Failure('Unbekannter Symbolname: ' . $name);
        }
        if (!in_array($size, ['sm', 'md', 'lg'], true)) {
            throw new Failure('Unbekannte Symbolgröße: ' . $size);
        }
        $classes = trim('mi mi-' . $size . ' ' . $class);

        return '<span class="' . htmlspecialchars($classes, ENT_QUOTES) . '" aria-hidden="true">'
            . $name . '</span>';
    }
}
