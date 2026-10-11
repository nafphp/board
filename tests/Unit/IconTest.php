<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class IconTest extends TestCase
{
    public function testEveryLiteralIconHasAGlyphInTheBundledSubset(): void
    {
        $source  = __DIR__ . '/../../src';
        $catalog = file_get_contents($source . '/Resources/public/assets/vendor/material-symbols/README.md');
        $glyphs  = trim(explode('```', explode('## Included glyphs', $catalog)[1])[1]);
        $glyphs  = preg_split('/[\s,]+/', $glyphs);
        $used    = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!in_array($file->getExtension(), ['php', 'phtml'], true)) {
                continue;
            }
            preg_match_all('/Icon::mark\([\'"]([a-z_]+)[\'"]/', file_get_contents($file->getPathname()), $matches);
            $used = array_merge($used, $matches[1]);
        }
        self::assertNotEmpty($used);
        self::assertSame([], array_values(array_diff(array_unique($used), $glyphs)), 'Extend the bundled Material Symbols subset before using a new icon.');
    }
}
