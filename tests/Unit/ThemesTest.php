<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Domain\Failure;
use Naf\Board\Support\Themes;
use PHPUnit\Framework\TestCase;

final class ThemesTest extends TestCase
{
    private function theme(array $values = []): array
    {
        return $values + ['name' => 'Studio', 'base' => 'classic', 'enabled' => true, 'css' => ''];
    }

    public function testCompilerUsesTheSameTokensForLightDarkAndSystemAndAppendsCss(): void
    {
        $css = Themes::compile($this->theme([
            'common' => ['font-family' => 'serif', 'density' => 1.1],
            'light'  => ['accent' => '#112233'], 'dark' => ['accent' => '#aabbcc'],
            'css'    => '.ticket { border-width: 2px; }',
        ]));
        self::assertStringContainsString('--font-family:Georgia', $css);
        self::assertStringContainsString('--density:1.1', $css);
        self::assertStringContainsString('color-scheme:light', $css);
        self::assertSame(2, substr_count($css, 'color-scheme:dark;'));
        self::assertStringContainsString('--accent:#112233', $css);
        self::assertSame(2, substr_count($css, '--accent:#aabbcc'));
        self::assertStringEndsWith('.ticket { border-width: 2px; }', $css);
    }

    public function testGeneratedBaseStylesAndComponentTokensDoNotDrift(): void
    {
        $assets = __DIR__ . '/../../src/Resources/public/assets/';
        self::assertSame(preg_replace('/\s+/', '', Themes::baseCss()), preg_replace('/\s+/', '', file_get_contents($assets . 'tokens.css')));
        $defined = file_get_contents($assets . 'tokens.css');
        foreach (glob($assets . '*.css') as $path) {
            if (basename($path) === 'tokens.css') {
                continue;
            }
            $css = file_get_contents($path);
            self::assertDoesNotMatchRegularExpression('/(?:font-size|border-radius|(?:row-|column-)?gap|margin(?:-[\w-]+)?|padding(?:-[\w-]+)?):[^;{}]*\b\d+(?:\.\d+)?px\b/', $css, basename($path));
            preg_match_all('/var\((--(?:space|font|radius)-[\d-]+)\)/', $css, $tokens);
            foreach ($tokens[1] as $token) {
                self::assertStringContainsString($token . ':', $defined, $token . ' in ' . basename($path));
            }
            self::assertDoesNotMatchRegularExpression('/#[a-f0-9]{3,8}\b/i', preg_replace('/mask-image:[^;]+;/', '', $css), basename($path));
        }
    }

    public function testUnknownTokensBadValuesAndUnpublishedDefaultsAreRejected(): void
    {
        foreach ([
            ['light' => ['unknown' => '#112233']],
            ['dark' => ['text' => 'red;display:none']],
            ['common' => ['density' => 0]],
            ['common' => ['font-family' => 'external']],
        ] as $invalid) {
            try {
                Themes::normalize($this->theme($invalid));
                self::fail('Invalid theme accepted.');
            } catch (Failure $failure) {
                self::assertSame(422, $failure->status);
            }
        }
        $this->expectException(Failure::class);
        Themes::validate(['default' => 'theme-aaaaaaaaaaaa', 'items' => ['theme-aaaaaaaaaaaa' => $this->theme(['enabled' => false])]]);
    }

    public function testCustomCssRejectsNetworkSyntaxIncludingEscapesAndComments(): void
    {
        foreach ([
            '@import "https://example.test/x.css";',
            'body { background: url(//example.test/a); }',
            'body { background: u\\72l(//example.test/a); }',
            '@im\\70ort "x.css";',
            'body { background: u/**/rl(//example.test/a); }',
            'body { background: image-set("//example.test/a" 1x); }',
            'body { background: image("//example.test/a"); }',
            'body { background: expression(alert(1)); }',
            str_repeat('a', 32769),
            'body { color: red;',
        ] as $css) {
            try {
                Themes::customCss($css);
                self::fail('Unsafe or invalid CSS accepted: ' . $css);
            } catch (Failure $failure) {
                self::assertSame(422, $failure->status);
            }
        }
        self::assertSame('@media(min-width:600px){.ticket{color:var(--accent);}}', Themes::customCss('@media(min-width:600px){.ticket{color:var(--accent);}}'));
    }
}
