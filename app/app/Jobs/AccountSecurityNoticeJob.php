<?php

declare(strict_types=1);

namespace App\Jobs;

use Naf\CLI\Core\Output;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Models\Mail;
use Naf\Queue\Core\QueueJobInterface;
use RuntimeException;

use function Naf\config;

/** The old address is intentional: security notices survive an address change. */
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
            'password.changed' => 'Das Passwort deines Nafinity-Kontos wurde geändert. Alle bisherigen Anmeldungen wurden beendet.',
            'email.requested'  => 'Für dein Nafinity-Konto wurde eine Änderung der E-Mail-Adresse angefordert. Bis zur Bestätigung bleibt diese Adresse gültig.',
            'email.changed'    => 'Die E-Mail-Adresse deines Nafinity-Kontos wurde geändert. Alle bisherigen Anmeldungen wurden beendet.',
            default            => throw new RuntimeException('Unknown account security notice.'),
        };
        $mail = (new Mail())
            ->setFrom(config('nafinity:mail_from'))
            ->addTo($this->recipient)
            ->setSubject('Nafinity · Sicherheit deines Kontos')
            ->setContent($message . "\n\nFalls du das nicht selbst warst, kontaktiere bitte deine Administration.\n", false);
        if (!$this->mailer->send($mail)) {
            throw new RuntimeException('Account security notice could not be delivered.');
        }
    }
}
