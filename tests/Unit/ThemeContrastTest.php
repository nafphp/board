<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\Themes;
use PHPUnit\Framework\TestCase;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Property\Selector;

final class ThemeContrastTest extends TestCase
{
    public function testBuiltInReadingAndActionTextMeetsMinimumContrast(): void
    {
        foreach (Themes::schema()['palettes'] as $name => $palette) {
            foreach (['light', 'dark'] as $mode) {
                $colors = $palette[$mode];
                foreach (['text', 'muted'] as $foreground) {
                    foreach (['bg', 'surface', 'sidebar', 'input', 'hover'] as $background) {
                        self::assertGreaterThanOrEqual(4.5, $this->contrast(
                            $this->rgb($colors[$foreground]),
                            $this->rgb($colors[$background]),
                        ), "{$name}/{$mode}: {$foreground} on {$background}");
                    }
                }
                self::assertGreaterThanOrEqual(4.5, $this->contrast(
                    $this->rgb($colors['on-accent']),
                    $this->rgb($colors['accent']),
                ), "{$name}/{$mode}: primary action");
            }
        }
    }

    public function testLabelTextRemainsReadableWithArbitraryLabelColorsInEveryBuiltInPalette(): void
    {
        $rules = [];
        $css   = file_get_contents(__DIR__ . '/../../src/Resources/public/assets/app.css');
        foreach ((new Parser($css))->parse()->getAllDeclarationBlocks() as $block) {
            if (in_array('.label-tag', array_map(static fn(Selector $selector): string => $selector->getSelector(), $block->getSelectors()), true)) {
                foreach ($block->getRules() as $rule) {
                    $value                   = $rule->getValue();
                    $rules[$rule->getRule()] = is_string($value) ? $value : $value->render(OutputFormat::create());
                }
            }
        }
        self::assertArrayHasKey('color', $rules);
        self::assertArrayHasKey('background', $rules);

        // Cube corners cover both the lightest and darkest possible tints;
        // the two original failures retain their own explicit regressions.
        $labels = ['#000000', '#ffffff', '#ff0000', '#00ff00', '#0000ff', '#ffff00', '#ff00ff', '#00ffff', '#ec4899', '#8b5cf6'];
        foreach (Themes::schema()['palettes'] as $name => $palette) {
            foreach (['light', 'dark'] as $mode) {
                foreach ($labels as $label) {
                    $colors = [...$palette[$mode], 'tag-color' => $label];
                    self::assertGreaterThanOrEqual(4.5, $this->contrast(
                        $this->color($rules['color'], $colors),
                        $this->color($rules['background'], $colors),
                    ), "{$name}/{$mode}: label {$label}");
                }
            }
        }
    }

    private function color(string $value, array $colors): array
    {
        $value = preg_replace_callback('/var\(--([a-z-]+)\)/', static fn(array $match): string => $colors[$match[1]], $value);
        if (preg_match('/^color-mix\(in srgb,\s*(#[a-f0-9]{6})\s+(\d+)%,\s*(#[a-f0-9]{6})\)$/iD', $value, $match)) {
            $weight = (int) $match[2] / 100;

            return array_map(static fn(float $a, float $b): float => $a * $weight + $b * (1 - $weight), $this->rgb($match[1]), $this->rgb($match[3]));
        }

        return $this->rgb($value);
    }

    private function rgb(string $hex): array
    {
        self::assertMatchesRegularExpression('/^#[a-f0-9]{6}$/iD', $hex);

        return array_map(static fn(int $offset): float => hexdec(substr($hex, $offset, 2)) / 255, [1, 3, 5]);
    }

    private function contrast(array $a, array $b): float
    {
        $luminance = static function (array $rgb): float {
            $linear = array_map(static fn(float $channel): float => $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4, $rgb);

            return $linear[0] * 0.2126 + $linear[1] * 0.7152 + $linear[2] * 0.0722;
        };
        $values = [$luminance($a), $luminance($b)];

        return (max($values) + 0.05) / (min($values) + 0.05);
    }
}
