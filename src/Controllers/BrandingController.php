<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Services\BrandingSettings;
use Naf\Board\Support\Branding;
use Naf\Board\Support\BrandLogoStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

use function Naf\I18n\t;
use function Naf\request;
use function Naf\route;

/** @internal */
final class BrandingController
{
    public function __construct(
        private AccessInterface $access,
        private BrandingSettings $settings,
        private BrandLogoStorage $logos,
    ) {
    }

    public function save(): ResponseInterface
    {
        return Respond::mutation($this->access, function (array $data): array {
            $upload = request()->getUploadedFiles()['logo'] ?? null;
            if ($upload !== null && !$upload instanceof UploadedFileInterface) {
                throw new Failure(t('Bitte lade genau ein Logo hoch.'), 422);
            }
            $this->settings->save($data, $upload);

            return ['url' => route('installation.settings') . '#branding'];
        })->withHeader('Cache-Control', 'private, no-store');
    }

    /** Public for the sign-in page; this route can only serve the current logo. */
    public function logo(string $logo): ResponseInterface
    {
        return Respond::read(function () use ($logo): ResponseInterface {
            if ($logo !== Branding::logoKey()) {
                throw new Failure(t('Dieses Logo ist nicht verfügbar.'), 404);
            }

            return Respond::download($this->logos->open($logo), BrandLogoStorage::contentType($logo), 'inline')
                ->withHeader('Cache-Control', 'public, max-age=86400, immutable');
        });
    }
}
