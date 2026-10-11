<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

use Naf\Board\Domain\Failure;
use Naf\Board\Support\Branding;
use Naf\Board\Support\Themes;

use function Naf\config;
use function Naf\I18n\t;

/** Shared tokens, validation and resolved email CSS for sending and previews. @internal */
final class Design
{
    public const string KEY     = 'appearance.email';
    public const string PATH    = 'nafinity:email_design';
    public const array DEFAULTS = [
        'common' => ['font-family' => 'system', 'font-size' => 16, 'radius' => 16, 'density' => 1],
        'colors' => ['bg' => '#f3f5f8', 'surface' => '#ffffff', 'text' => '#243047', 'muted' => '#68768c', 'line' => '#dde3ec', 'accent' => '#6558e8', 'on-accent' => '#ffffff'],
        'css'    => '',
    ];
    public const array SAMPLES = [
        'invitation'         => 'Einladung', 'password-reset' => 'Passwort zurücksetzen',
        'email-verification' => 'E-Mail-Adresse bestätigen', 'security-notice' => 'Sicherheitshinweis',
        'notification'       => 'Benachrichtigung',
    ];

    public static function current(): array
    {
        return self::normalize(config(self::PATH, self::DEFAULTS));
    }

    public static function normalize(mixed $design): array
    {
        if (!is_array($design) || array_diff(array_keys($design), ['common', 'colors', 'css'])
            || !is_array($design['common'] ?? []) || !is_array($design['colors'] ?? [])
            || array_diff(array_keys($design['colors'] ?? []), array_keys(self::DEFAULTS['colors']))) {
            throw new Failure(t('Bitte prüfe das E-Mail-Design.'), 422);
        }
        foreach ($design['colors'] ?? [] as $color) {
            if (!is_string($color) || preg_match('/^#[a-f0-9]{6}$/iD', $color) !== 1) {
                throw new Failure(t('Bitte wähle gültige Theme-Farben.'), 422);
            }
        }
        $theme = Themes::normalize([
            'name'   => 'Email', 'base' => 'classic', 'enabled' => true,
            'common' => array_replace(self::DEFAULTS['common'], $design['common'] ?? []),
            'light'  => array_replace(self::DEFAULTS['colors'], $design['colors'] ?? []),
            'css'    => $design['css'] ?? '',
        ]);
        $result = ['common' => $theme['common'], 'colors' => $theme['light'], 'css' => $theme['css']];
        self::resolve($result['css'], self::variables($result));

        return $result;
    }

    private static function variables(array $design): array
    {
        $common    = $design['common'];
        $variables = $design['colors'] + [
            'font-family' => Themes::schema()['fonts'][$common['font-family']],
            'font-size'   => $common['font-size'] . 'px', 'radius' => $common['radius'] . 'px',
            'density'     => (string) $common['density'],
        ];
        foreach (Themes::schema()['scales']['space'] as $space) {
            $variables['space-' . str_replace('.', '-', (string) $space)] = round($space * $common['density'], 2) . 'px';
        }
        foreach (Themes::schema()['scales']['radius'] as $radius) {
            $variables['radius-' . str_replace('.', '-', (string) $radius)] = round($radius * $common['radius'] / 12, 2) . 'px';
        }
        foreach (Themes::schema()['scales']['font'] as $size) {
            $variables['font-' . str_replace('.', '-', (string) $size)] = round($size * $common['font-size'] / 14, 2) . 'px';
        }
        foreach (Themes::schema()['fonts'] as $id => $font) {
            $variables['font-' . $id] = $font;
        }

        return $variables;
    }

    private static function resolve(string $css, array $variables): string
    {
        return preg_replace_callback('/var\(\s*--([a-z0-9-]+)\s*\)/i', static function (array $match) use ($variables): string {
            if (!array_key_exists($match[1], $variables)) {
                throw new Failure(t('Dieses E-Mail-CSS enthält eine unbekannte Variable: :name.', ['name' => '--' . $match[1]]), 422);
            }

            return $variables[$match[1]];
        }, $css);
    }

    public static function css(array $design): string
    {
        $css = <<<'CSS'
        body { margin:0; padding:0; background:var(--bg); color:var(--text); font-family:var(--font-family); font-size:var(--font-size); -webkit-text-size-adjust:100%; }
        .email-page { width:100%; background:var(--bg); }
        .email-outer { padding:var(--space-32) var(--space-12); }
        .email-card { width:100%; max-width:600px; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); overflow:hidden; }
        .email-header { padding:var(--space-24) var(--space-32); background:var(--accent); color:var(--on-accent); }
        .email-logo { display:inline-block; width:40px; height:40px; object-fit:contain; vertical-align:middle; margin-right:var(--space-12); }
        .email-symbol { display:inline-block; vertical-align:middle; font-size:32px; margin-right:var(--space-12); }
        .email-brand-name { font-size:var(--font-20); line-height:1.4; font-weight:bold; vertical-align:middle; }
        .email-category { display:block; margin-top:var(--space-8); font-size:var(--font-11); line-height:1.6; letter-spacing:1px; text-transform:uppercase; }
        .email-content { padding:var(--space-32); }
        .email-title { margin:0 0 var(--space-16); color:var(--text); font-size:var(--font-24); line-height:1.35; font-weight:bold; }
        .email-intro, .email-paragraph { margin:0 0 var(--space-20); line-height:1.7; color:var(--text); }
        .email-highlight { width:100%; margin:0 0 var(--space-24); border:1px solid var(--line); border-radius:var(--radius-8); background:var(--bg); }
        .email-highlight td { padding:var(--space-20); line-height:1.7; overflow-wrap:anywhere; word-break:break-word; }
        .email-highlight-label { display:block; color:var(--muted); font-size:var(--font-11); text-transform:uppercase; }
        .email-code { font-family:var(--font-mono); font-weight:bold; word-break:break-all; }
        .email-action { margin:var(--space-24) 0; }
        .email-action td { border-radius:var(--radius-8); text-align:center; background:var(--accent); }
        .email-button { display:inline-block; padding:var(--space-12) var(--space-20); border:1px solid var(--accent); border-radius:var(--radius-8); color:var(--on-accent); background:var(--accent); text-decoration:none; line-height:1.5; font-weight:bold; }
        .email-fallback { margin:0; font-size:var(--font-11); line-height:1.7; color:var(--muted); overflow-wrap:anywhere; word-break:break-all; }
        .email-link { color:var(--accent); text-decoration:underline; }
        .email-footer { padding:var(--space-20) var(--space-32); border-top:1px solid var(--line); }
        .email-footer p { margin:0; font-size:var(--font-11); line-height:1.7; color:var(--muted); }
        .email-signature { margin:var(--space-20) 0 0; font-size:var(--font-11); line-height:1.6; color:var(--muted); }
        CSS;
        $css .= "\n.email-brand-name{font-family:" . Themes::schema()['fonts'][Branding::font()] . ';';
        if (Branding::color() !== null) {
            $css .= 'color:' . Branding::color() . ';';
        }
        $css .= "}\n" . $design['css'];

        return self::resolve($css, self::variables($design));
    }

    public static function sample(string $template, ?array $design = null): string
    {
        if (!isset(self::SAMPLES[$template])) {
            throw new Failure(t('Diese E-Mail-Vorschau ist nicht verfügbar.'), 422);
        }

        return Messages::html($template, [
            'projectName' => t('Beispielprojekt'), 'url' => 'https://example.test/preview',
            'code'        => 'ABCD-EFGH-2345',
            'message'     => t('Das Passwort deines Kontos bei :name wurde geändert. Alle bisherigen Anmeldungen wurden beendet.', ['name' => Branding::name()]),
        ], $design);
    }
}
