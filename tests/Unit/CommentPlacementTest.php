<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A block comment belongs to the code below it.
 *
 * Two block comments in a row, with no blank line between them, mean one of
 * them lost its code -- a method moved or deleted, its explanation left behind
 * above somebody else's. That reads as documentation of the wrong thing, which
 * is worse than none. A file's own header stands apart by a blank line.
 */
final class CommentPlacementTest extends TestCase
{
    public function testNoBlockCommentIsFollowedByAnother(): void
    {
        $root     = dirname(__DIR__, 2);
        $stranded = [];

        foreach ($this->sources($root) as $file) {
            $previous = null;
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && $token[0] === T_WHITESPACE) {
                    if (substr_count($token[1], "\n") > 1) {
                        $previous = null;
                    }
                    continue;
                }
                $block = is_array($token)
                    && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    && str_starts_with($token[1], '/*');

                if ($block && $previous !== null) {
                    $stranded[] = substr($file, strlen($root) + 1) . ':' . $previous;
                }
                $previous = $block ? $token[2] : null;
            }
        }

        $this->assertSame([], $stranded, 'a block comment directly above another has lost its code');
    }

    /** @return iterable<string> */
    private function sources(string $root): iterable
    {
        yield $root . '/bootstrap.php';

        foreach (['src', 'examples'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($files as $file) {
                if (in_array($file->getExtension(), ['php', 'phtml'], true)) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
