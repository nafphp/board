<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\Abbreviation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The key a project gets when nobody types one.
 *
 * Consonants, because they are what a word reads back from: FLNCR is Faulancer
 * to anybody who knows the project, and FAULAN is not obviously anything. What
 * these hold is that the rule survives the names people actually give things --
 * two words, an umlaut, a digit, a leading vowel, and a name with no letters in
 * it at all.
 */
final class AbbreviationTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'the one this was built for'      => ['Faulancer', 'FLNCR'],
            'a name that is mostly consonant' => ['Nafinity', 'NFNTY'],
            'two words run together'          => ['Studio Nord', 'STDNRD'],
            'longer than a key may be'        => ['Kundenverwaltung', 'KNDNVR'],
            // The first letter is what people recognise a name by, vowel or not.
            'beginning on a vowel'                  => ['Archiv', 'ARCHV'],
            'an umlaut is a letter wearing a hat'   => ['Übersicht', 'UBRSCH'],
            'digits are not vowels'                 => ['Messe 2025', 'MSS202'],
            'a name with nothing left after vowels' => ['Aioli', 'AL'],
            'nothing to abbreviate'                 => ['#!?', ''],
        ];
    }

    #[DataProvider('names')]
    public function testAKeyIsTakenFromTheName(string $name, string $expected): void
    {
        $this->assertSame($expected, Abbreviation::key($name));
    }

    public function testEveryKeyFitsWhatTheColumnAndTheAddressAllow(): void
    {
        foreach (self::names() as [$name, $key]) {
            if ($key === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{1,6}$/', $key, "für: $name");
        }
    }

    public function testTheMarkIsTheFirstCharacterOfTheName(): void
    {
        $this->assertSame('F', Abbreviation::mark('Faulancer'));
        $this->assertSame('Ü', Abbreviation::mark('  Übersicht'), 'it is a character, not a byte');
        $this->assertSame('', Abbreviation::mark('   '));
    }
}
