<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\RichText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a description may contain.
 *
 * The editor is in the browser and can be made to send anything at all, so what
 * is kept is decided here rather than trusted: formatting survives, everything
 * that could execute does not. A second, plain rendering is what search reads --
 * people look for words, not for the tags around them.
 */
final class RichTextTest extends TestCase
{
    private const UNSAFE_INPUT = '<h2>Goal</h2><p><strong>Bold</strong> <em>Italic</em></p>'
        . '<ol><li>Task</li></ol><script>alert(1)</script><img src=x onerror=alert(2)>'
        . '<a href="javascript:alert(3)" onclick="alert(4)">unsafe</a>';

    /**
     * @return array<string,array{string}>
     */
    public static function formatting(): array
    {
        return [
            'a heading'       => ['<h2>Goal</h2>'],
            'bold'            => ['<strong>Bold</strong>'],
            'italics'         => ['<em>Italic</em>'],
            'a numbered list' => ['<li>Task</li>'],
        ];
    }

    #[DataProvider('formatting')]
    public function testFormattingSurvives(string $fragment): void
    {
        $this->assertStringContainsString($fragment, RichText::clean(self::UNSAFE_INPUT));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function unsafeFragments(): array
    {
        return [
            'a script element'      => ['<script'],
            'an image element'      => ['<img'],
            'a javascript: address' => ['javascript:'],
            'an onclick handler'    => ['onclick'],
            'an onerror handler'    => ['onerror'],
        ];
    }

    #[DataProvider('unsafeFragments')]
    public function testNothingThatCouldExecuteIsKept(string $fragment): void
    {
        $this->assertStringNotContainsString($fragment, RichText::clean(self::UNSAFE_INPUT));
    }

    public function testThePlainRenderingCarriesTheWordsWithoutTheTags(): void
    {
        $plain = RichText::plain(self::UNSAFE_INPUT);

        $this->assertStringContainsString('Bold', $plain);
        $this->assertStringNotContainsString('<strong>', $plain);
        $this->assertStringNotContainsString('<h2>', $plain);
    }

    /**
     * A character is not a byte. Forty thousand Chinese characters are 120,000
     * bytes, and a limit counted in bytes would cut the text mid-character.
     */
    public function testLengthIsCountedInCharactersRatherThanBytes(): void
    {
        $plain = RichText::plain('<p>' . str_repeat('界', 40000) . '</p>');

        $this->assertSame(40000, mb_strlen($plain));
    }

    /**
     * A description written before rich text existed is plain text, and text
     * that happens to look like markup has to keep being text.
     */
    public function testOlderPlainTextIsShownAsTextRatherThanMarkup(): void
    {
        $shown = RichText::description([
            'description'      => 'Legacy <script> remains text',
            'description_html' => null,
        ]);

        $this->assertStringNotContainsString('<script>', $shown);
        $this->assertStringContainsString('script', $shown, 'the text itself disappeared with the markup');
    }

    public function testWhereThereIsFormattingItIsWhatGetsShown(): void
    {
        $shown = RichText::description([
            'description'      => 'Bold',
            'description_html' => '<p><strong>Bold</strong></p>',
        ]);

        $this->assertStringContainsString('<strong>Bold</strong>', $shown);
    }
}
