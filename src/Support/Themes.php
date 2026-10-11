<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use InvalidArgumentException;
use Naf\Board\Domain\Failure;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Settings;
use Throwable;

use function Naf\app;
use function Naf\config;
use function Naf\I18n\t;
use function Naf\request;
use function Naf\route;

/** One token catalog and compiler for built-ins, saved themes and isolated previews. @internal */
final class Themes
{
    public const string KEY     = 'appearance.themes';
    public const string PATH    = 'nafinity:themes';
    public const array DEFAULTS = ['default' => 'classic', 'items' => []];

    public static function schema(): array
    {
        static $schema;

        return $schema ??= json_decode(file_get_contents(__DIR__ . '/../Resources/public/assets/theme-tokens.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    public static function previewPolicy(UriInterface $uri): string
    {
        if (!in_array($uri->getScheme(), ['http', 'https'], true)
            || preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:.]+\])$/iD', $uri->getHost()) !== 1) {
            throw new InvalidArgumentException('Theme previews require an HTTP origin.');
        }
        // Firefox resolves 'self' against about:srcdoc in a meta policy. Name only
        // the current HTTP origin, including its port, for local assets and fonts.
        $origin = (string) $uri->withUserInfo('')->withPath('')->withQuery('')->withFragment('');

        return "default-src 'none'; style-src {$origin} 'unsafe-inline'; img-src {$origin} data:; font-src {$origin}; form-action 'none'; base-uri 'none'";
    }

    public static function catalog(): array
    {
        static $previous, $catalog;
        $raw = config(self::PATH, self::DEFAULTS);
        if ($catalog === null || $raw !== $previous) {
            $catalog  = self::validate($raw);
            $previous = $raw;
        }

        return $catalog;
    }

    public static function choices(bool $inherit = true): array
    {
        $choices = $inherit ? ['inherit' => t('Installationsstandard')] : [];
        foreach (self::schema()['palettes'] as $id => $palette) {
            $choices[$id] = t($palette['name']);
        }
        foreach (self::catalog()['items'] as $id => $theme) {
            if ($theme['enabled']) {
                $choices[$id] = $theme['name'];
            }
        }

        return $choices;
    }

    public static function selected(mixed $id): string
    {
        $choices = self::choices(false);

        return is_string($id) && isset($choices[$id]) ? $id : self::catalog()['default'];
    }

    public static function safe(): bool
    {
        // Templates may also be rendered from a worker or CLI without an HTTP request.
        if (!app()->container()->has(RequestInterface::class)) {
            return false;
        }
        $request = request();

        return $request instanceof ServerRequestInterface && ($request->getQueryParams()['appearance'] ?? null) === 'default';
    }

    public static function definition(string $id): array
    {
        $palettes = self::schema()['palettes'];
        if (isset($palettes[$id])) {
            return ['name' => $palettes[$id]['name'], 'base' => $id, 'enabled' => true, 'common' => [], 'light' => [], 'dark' => [], 'css' => ''];
        }

        return self::catalog()['items'][$id] ?? throw new Failure(t('Dieses Theme ist nicht verfügbar.'), 404);
    }

    public static function url(string $id): string
    {
        return route('themes.css', ['theme' => $id, 'version' => hash('sha256', self::compile(self::definition($id)))]);
    }

    public static function validate(mixed $catalog): array
    {
        if (!is_array($catalog) || array_diff(array_keys($catalog), ['default', 'items'])
            || !is_string($catalog['default'] ?? null) || !is_array($catalog['items'] ?? null)
            || count($catalog['items']) > 16) {
            throw new Failure(t('Bitte prüfe die Theme-Konfiguration.'), 422);
        }
        $items = [];
        foreach ($catalog['items'] as $id => $theme) {
            if (!is_string($id) || preg_match('/^theme-[a-f0-9]{12}$/D', $id) !== 1) {
                throw new Failure(t('Bitte prüfe die Theme-Konfiguration.'), 422);
            }
            $items[$id] = self::normalize($theme);
        }
        $default = $catalog['default'];
        if (!isset(self::schema()['palettes'][$default]) && !($items[$default]['enabled'] ?? false)) {
            throw new Failure(t('Der Installationsstandard muss ein freigegebenes Theme sein.'), 422);
        }

        return ['default' => $default, 'items' => $items];
    }

    public static function normalize(mixed $theme): array
    {
        if (!is_array($theme) || array_diff(array_keys($theme), ['name', 'base', 'enabled', 'common', 'light', 'dark', 'css'])
            || !is_string($theme['name'] ?? null) || trim($theme['name']) === '' || mb_strlen($theme['name']) > 80
            || !is_string($theme['base'] ?? null) || !isset(self::schema()['palettes'][$theme['base']])
            || !is_bool($theme['enabled'] ?? null) || !is_string($theme['css'] ?? '')) {
            throw new Failure(t('Bitte prüfe Name, Basis und Freigabe des Themes.'), 422);
        }
        $result = ['name' => trim($theme['name']), 'base' => $theme['base'], 'enabled' => $theme['enabled']];
        foreach (['common', 'light', 'dark'] as $group) {
            $values  = $theme[$group] ?? [];
            $allowed = $group === 'common' ? self::schema()['common'] : self::schema()['palettes']['classic'][$group];
            if (!is_array($values) || array_diff(array_keys($values), array_keys($allowed))) {
                throw new Failure(t('Dieses Theme enthält unbekannte CSS-Variablen.'), 422);
            }
            foreach ($values as $key => &$value) {
                if ($group !== 'common') {
                    $sample = $allowed[$key];
                    // Color tokens are editable; shadow recipes are shared derived styles.
                    if (!str_starts_with($sample, '#') || !is_string($value) || preg_match('/^#[a-f0-9]{6}(?:[a-f0-9]{2})?$/iD', $value) !== 1) {
                        throw new Failure(t('Bitte wähle gültige Theme-Farben.'), 422);
                    }
                    $value = strtolower($value);
                } elseif ($key === 'font-family') {
                    if (!is_string($value) || !isset(self::schema()['fonts'][$value])) {
                        throw new Failure(t('Bitte wähle eine verfügbare Schriftart.'), 422);
                    }
                } else {
                    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)
                        || !is_finite((float) $value) || (float) $value < $allowed[$key]['min'] || (float) $value > $allowed[$key]['max']) {
                        throw new Failure(t('Bitte prüfe Schriftgröße, Rundungen und Abstände.'), 422);
                    }
                    $value = (float) $value;
                }
            }
            unset($value);
            ksort($values);
            $result[$group] = $values;
        }
        $result['css'] = self::customCss($theme['css'] ?? '');

        return $result;
    }

    public static function customCss(string $css): string
    {
        if (strlen($css) > 32768 || !mb_check_encoding($css, 'UTF-8')) {
            throw new Failure(t('Eigenes CSS darf höchstens 32 KB groß sein.'), 422);
        }
        // Decode escapes and remove comments before checking network-capable syntax.
        // The parser then checks grammar. CSS remains CSS; no script or HTML is evaluated.
        $decoded = preg_replace_callback('/\\\\([a-f0-9]{1,6})\s?|\\\\(.)/isu', static fn(array $m): string
            => $m[1] !== '' ? (mb_chr(hexdec($m[1]), 'UTF-8') ?: '') : $m[2], $css);
        $decoded = preg_replace('#/\*.*?\*/#s', '', $decoded);
        if (preg_match('/(?:url|image|(?:-webkit-)?image-set|src|expression)\s*\(|@(?:import|namespace)|(?:behavior|-moz-binding)\s*:|https?:|data:|javascript:/i', $decoded)) {
            throw new Failure(t('Eigenes CSS darf keine externen Ressourcen, Imports oder aktiven Inhalte laden.'), 422);
        }
        if ($css !== '') {
            try {
                (new Parser($css, Settings::create()->beStrict()))->parse();
            } catch (Throwable) {
                throw new Failure(t('Das eigene CSS ist ungültig. Bitte prüfe die Syntax.'), 422);
            }
        }

        return $css;
    }

    public static function compile(array $theme, string $selector = 'html'): string
    {
        $theme  = self::normalize($theme);
        $schema = self::schema();
        $base   = $schema['palettes'][$theme['base']];
        $common = [];
        foreach ($schema['common'] as $key => $field) {
            $value        = $theme['common'][$key] ?? $field['default'];
            $common[$key] = $field['type'] === 'font' ? $schema['fonts'][$value] : $value . $field['unit'];
        }
        $rule = static function (string $selector, array $values, string $mode): string {
            $css = $selector . "{\ncolor-scheme:" . $mode . ";\n";
            foreach ($values as $key => $value) {
                $css .= '--' . $key . ':' . $value . ";\n";
            }

            return $css . "}\n";
        };
        $light = array_replace($base['light'], $theme['light']);
        $dark  = array_replace($base['dark'], $theme['dark']);
        $css   = $rule($selector, [...$common, ...$light], 'light');
        $css .= $rule($selector . "[data-theme='dark']", $dark, 'dark');
        $css .= '@media(prefers-color-scheme:dark){' . $rule($selector . "[data-theme='system']", $dark, 'dark') . "}\n";

        return $css . $theme['css'];
    }

    public static function baseCss(): string
    {
        $css = "/* Generated from theme-tokens.json by tools/build-themes.php. */\n";
        foreach (self::schema()['palettes'] as $id => $palette) {
            $theme = ['name' => $palette['name'], 'base' => $id, 'enabled' => true, 'css' => ''];
            $css .= self::compile($theme, $id === 'classic' ? 'html' : "html[data-palette='anthracite']");
        }
        $css .= "html{--radius-full:999px;\n";
        foreach (self::schema()['fonts'] as $id => $stack) {
            $css .= '--font-' . $id . ':' . $stack . ";\n";
        }
        foreach (self::schema()['scales'] as $scale => $values) {
            foreach ($values as $value) {
                $key = str_replace('.', '-', (string) $value);
                $css .= '--' . $scale . '-' . $key . ':calc(' . match ($scale) {
                    'space'  => $value . 'px * var(--density)',
                    'font'   => 'var(--font-size) * ' . $value . ' / 14',
                    'radius' => 'var(--radius) * ' . $value . ' / 12',
                } . ");\n";
            }
        }

        return $css . "}\n";
    }
}
