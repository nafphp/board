<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Services\ThemeSettings;
use Naf\Board\Support\Themes;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

use function Naf\Board\template;
use function Naf\json;
use function Naf\response;
use function Naf\route;
use function Naf\View\render;

/** Public compiled CSS; administration and preview require installation settings rights. @internal */
final class ThemeController
{
    public function __construct(private AccessInterface $access, private ThemeSettings $settings)
    {
    }

    public function save(): ResponseInterface
    {
        return Respond::mutation($this->access, function (array $data): array {
            $this->settings->save($data);

            return ['url' => route('installation.settings') . '?appearance=default#themes'];
        })->withHeader('Cache-Control', 'private, no-store');
    }

    public function preview(): ResponseInterface
    {
        return Respond::mutation($this->access, function (array $data): array {
            $this->settings->authorize();

            return ['css' => Themes::compile($this->settings->draft($data))];
        })->withHeader('Cache-Control', 'private, no-store');
    }

    public function sample(): ResponseInterface
    {
        return Respond::read(function (): ResponseInterface {
            $this->settings->authorize();

            return render(template('settings/theme-preview'))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'none'; base-uri 'none'");
        });
    }

    public function css(string $theme, string $version): ResponseInterface
    {
        return Respond::read(function () use ($theme, $version): ResponseInterface {
            if (!isset(Themes::choices(false)[$theme])) {
                throw new Failure('Theme not found.', 404);
            }
            $css = Themes::compile(Themes::definition($theme));
            if (!hash_equals(hash('sha256', $css), $version)) {
                throw new Failure('Theme not found.', 404);
            }

            return response()->withBody(Stream::create($css))
                ->withHeader('Content-Type', 'text/css; charset=utf-8')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
        });
    }

    public function export(string $theme): ResponseInterface
    {
        return Respond::read(function () use ($theme): ResponseInterface {
            $this->settings->authorize();

            return json(['version' => 1, 'theme' => Themes::definition($theme)])
                ->withHeader('Content-Disposition', 'attachment; filename="theme.json"')
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        });
    }
}
