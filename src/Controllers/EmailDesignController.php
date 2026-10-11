<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Mail\Design;
use Naf\Board\Mail\Messages;
use Naf\Board\Services\ApplicationSettings;
use Naf\Board\Support\Branding;
use Naf\Mail\Core\Mailer;
use Naf\RateLimit\PdoLimiter;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Naf\I18n\t;
use function Naf\route;

/** One authorized designer; only the explicit test action sends a sample email. @internal */
final class EmailDesignController
{
    public function __construct(
        private AccessInterface $access,
        private ApplicationSettings $settings,
        private PDO $pdo,
        private Mailer $mailer,
        private PdoLimiter $limiter,
    ) {
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

    public function sendTest(): ResponseInterface
    {
        return Respond::mutation($this->access, function (array $data): array {
            $this->settings->authorize();
            $sample = $data['sample'] ?? 'invitation';
            if (!is_string($sample) || !isset(Design::SAMPLES[$sample])) {
                throw new Failure(t('Diese E-Mail-Vorschau ist nicht verfügbar.'), 422);
            }
            $design    = Design::normalize($data['design'] ?? null);
            $actor     = $this->access->actor();
            $statement = $this->pdo->prepare('SELECT email FROM users WHERE id=? AND active=1');
            $statement->execute([$actor]);
            $email = $statement->fetchColumn();
            if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Failure(t('Für dein Konto ist keine gültige E-Mail-Adresse hinterlegt.'), 422);
            }
            if (!$this->limiter->consume('email-design:test:' . $actor, 3, 60)['allowed']) {
                throw new Failure(t('Bitte warte eine Minute, bevor du weitere Testmails sendest.'), 429);
            }

            try {
                $mail = Messages::create($this->mailer, $sample, Design::sampleData(), $design)
                    ->addTo($email)
                    ->setSubject(Branding::subject(t('Testmail · :type', ['type' => t(Design::SAMPLES[$sample])])));
                if (!$this->mailer->send($mail)) {
                    throw new Failure('Mail transport rejected the test message.', 503);
                }
            } catch (Throwable) {
                throw new Failure(t('Die Testmail konnte nicht versendet werden. Bitte prüfe die E-Mail-Einstellungen.'), 503);
            }

            return ['message' => t('Testmail an :email versendet.', ['email' => $email]), 'url' => route('installation.settings') . '#email-design'];
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
