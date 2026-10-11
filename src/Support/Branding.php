<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use function Naf\config;
use function Naf\route;

/** The installation's public identity, shared by the shell and account pages. @internal */
final class Branding
{
    public const string NAME       = 'config.app:name';
    public const string HOME       = 'branding.home_project';
    public const string COLOR      = 'branding.color';
    public const string FONT       = 'branding.font';
    public const string COLOR_PATH = 'nafinity:branding:color';
    public const string FONT_PATH  = 'nafinity:branding:font';
    public const array FONTS       = ['system' => 'Systemschrift', 'serif' => 'Serif', 'mono' => 'Monospace'];
    public const string LOGO       = 'branding.logo';
    public const string HOME_PATH  = 'nafinity:branding:home_project';
    public const string LOGO_PATH  = 'nafinity:branding:logo';

    public static function name(): string
    {
        $name = config('app:name', 'Nafinity');

        return is_string($name) && trim($name) !== '' ? trim($name) : 'Nafinity';
    }

    public static function logoKey(): ?string
    {
        $key = config(self::LOGO_PATH);

        return is_string($key) && BrandLogoStorage::validKey($key) ? $key : null;
    }

    public static function logoUrl(): ?string
    {
        $key = self::logoKey();

        return $key === null ? null : route('branding.logo', ['logo' => $key]);
    }

    public static function color(): ?string
    {
        $color = config(self::COLOR_PATH);

        return is_string($color) && preg_match('/^#[0-9a-f]{6}$/iD', $color) === 1 ? strtolower($color) : null;
    }

    public static function font(): string
    {
        $font = config(self::FONT_PATH, 'system');

        return is_string($font) && isset(self::FONTS[$font]) ? $font : 'system';
    }

    /** Only a current, readable and active project may become the landing page. */
    public static function home(array $projects): string
    {
        $selected = (int) config(self::HOME_PATH, 0);
        foreach ($projects as $project) {
            if ((int) $project['id'] === $selected && empty($project['archived_at'])) {
                return route('board', ['project' => $selected]);
            }
        }

        return route('projects');
    }
}
