<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Mail\Design;
use Naf\Board\Services\ApplicationSettings;
use Psr\Http\Message\ResponseInterface;

use function Naf\I18n\t;
use function Naf\route;

/** One authorized designer; previews render samples without sending mail. @internal */
final class EmailDesignController
{
    public function __construct(private AccessInterface $access, private ApplicationSettings $settings)
    {
    }

    public function save(): ResponseInterface
    {
        return Respond::mutation($this->access, function (array $data): array {
            $this->settings->authorize();
            $revision = $data['configurationRevision'] ?? null;
            if (!is_string($revision)) {
                throw new Failure(t('Bitte lade die Einstellungen neu.'), 409);
            }
            if (($data['action'] ?? '') === 'reset') {
                $this->settings->save([], [Design::KEY], $revision);
            } else {
                $this->settings->save([Design::KEY => Design::normalize($data['design'] ?? null)], [], $revision);
            }

            return ['url' => route('installation.settings') . '#email-design'];
        })->withHeader('Cache-Control', 'private, no-store');
    }

    public function preview(): ResponseInterface
    {
        return Respond::mutation($this->access, function (array $data): array {
            $this->settings->authorize();
            $template = $data['sample'] ?? 'invitation';
            if (!is_string($template)) {
                throw new Failure(t('Diese E-Mail-Vorschau ist nicht verfügbar.'), 422);
            }

            return ['html' => Design::sample($template, Design::normalize($data['design'] ?? null))];
        })->withHeader('Cache-Control', 'private, no-store');
    }
}
