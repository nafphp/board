<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\Locales;
use PHPUnit\Framework\TestCase;

/**
 * Which languages the picker may offer.
 *
 * Only those a translation file exists for, so the picker can never promise a
 * language that would fall back to German keys. The names are written in the
 * language itself: someone who cannot read the current one still recognises
 * their own.
 */
final class LocalesTest extends TestCase
{
    public function testOnlyLanguagesWithATranslationFileAreOffered(): void
    {
        $this->assertSame(
            ['de', 'en'],
            array_keys(Locales::available()),
            'the offered languages do not match the translation files that exist',
        );
    }

    public function testEachLanguageIsNamedInItself(): void
    {
        $available = Locales::available();

        $this->assertSame('Deutsch', $available['de']);
        $this->assertSame('English', $available['en']);
    }

    public function testOnlyAnOfferedLanguageIsSupported(): void
    {
        $this->assertTrue(Locales::supports('de'));
        $this->assertFalse(Locales::supports('xx'), 'a language with no translation file was accepted');
        $this->assertFalse(Locales::supports(null));
        $this->assertFalse(Locales::supports(42));
    }

    /**
     * Flags name countries, not languages, so they are a convenience for
     * recognition and never the identity of a locale -- the written name beside
     * one carries that. An unknown code still gets something to draw.
     */
    public function testEveryOfferedLanguageHasAFlagAndAnUnknownOneFallsBack(): void
    {
        foreach (array_keys(Locales::available()) as $code) {
            $this->assertNotSame('🏳', Locales::flag($code), "no flag for {$code}");
        }
        $this->assertSame('🏳', Locales::flag('xx'));
    }

    public function testAnUnknownCodeIsNamedAsItself(): void
    {
        $this->assertSame('zz', Locales::name('zz'));
    }
}
