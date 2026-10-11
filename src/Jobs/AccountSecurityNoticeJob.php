<?php

declare(strict_types=1);

namespace Naf\Board\Jobs;

use Naf\Board\Mail\Messages;
use Naf\Board\Support\Branding;
use Naf\CLI\Core\Output;
use Naf\Mail\Core\Mailer;
use Naf\Queue\Core\QueueJobInterface;
use RuntimeException;

use function Naf\I18n\t;

/** The old address is intentional: security notices survive an address change. *
 * @internal
 */
final class AccountSecurityNoticeJob implements QueueJobInterface
{
    public function __construct(
        private string $recipient,
        private string $event,
        private Mailer $mailer,
    ) {
    }

    public function execute(Output $output): void
    {
        $message = match ($this->event) {
            'password.changed' => t('Das Passwort deines Kontos bei :name wurde geändert. Alle bisherigen Anmeldungen wurden beendet.', ['name' => Branding::name()]),
            'email.requested'  => t('Für dein Konto bei :name wurde eine Änderung der E-Mail-Adresse angefordert. Bis zur Bestätigung bleibt diese Adresse gültig.', ['name' => Branding::name()]),
            'email.changed'    => t('Die E-Mail-Adresse deines Kontos bei :name wurde geändert. Alle bisherigen Anmeldungen wurden beendet.', ['name' => Branding::name()]),
            default            => throw new RuntimeException('Unknown account security notice.'),
        };
        $mail = Messages::create($this->mailer, 'security-notice', ['message' => $message])
            ->addTo($this->recipient)
            ->setSubject(Branding::subject(t('Sicherheit deines Kontos')));
        if (!$this->mailer->send($mail)) {
            throw new RuntimeException('Account security notice could not be delivered.');
        }
    }
}
