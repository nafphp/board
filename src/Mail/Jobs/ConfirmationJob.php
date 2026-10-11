<?php

declare(strict_types=1);

namespace Naf\Board\Mail\Jobs;

use Naf\Board\Mail\Messages;
use Naf\Board\Mail\Settings;
use Naf\CLI\Core\Output;
use Naf\Mail\Core\Mailer;
use Naf\Queue\Core\QueueJobInterface;
use PDO;
use RuntimeException;

final class ConfirmationJob implements QueueJobInterface
{
    public function __construct(
        private int $ticketId,
        private string $reference,
        private PDO $pdo,
        private Settings $settings,
        private Mailer $mailer,
    ) {
    }

    public function execute(Output $output): void
    {
        if (!$this->settings->enabled) {
            return;
        }

        $query = $this->pdo->prepare('SELECT recipient FROM board_mail_cases WHERE ticket_id=? AND reference=? AND notified_at IS NULL');
        $query->execute([$this->ticketId, $this->reference]);
        $recipient = $query->fetchColumn();
        if (!$recipient) {
            return;
        }

        $mail = Messages::create($this->mailer, 'case-confirmation', ['reference' => $this->reference])
            ->setFrom($this->settings->from)
            ->setReplyTo($this->settings->replyTo)
            ->addTo($recipient)
            ->setSubject('Vorgangsnummer: ' . $this->reference);
        if (!$this->mailer->send($mail)) {
            throw new RuntimeException('The case confirmation could not be delivered.');
        }

        $this->pdo->prepare('UPDATE board_mail_cases SET notified_at=? WHERE ticket_id=? AND reference=? AND recipient=?')
            ->execute([gmdate('Y-m-d H:i:s'), $this->ticketId, $this->reference, $recipient]);
    }
}
