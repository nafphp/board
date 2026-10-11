<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

use Naf\Board\Contracts\TicketMetadataStoreInterface;
use Naf\Board\Domain\Change;
use Naf\Board\Mail\Jobs\ConfirmationJob;
use Naf\Queue\Core\Queue;
use PDO;

final class CaseListener
{
    public function __construct(
        private PDO $pdo,
        private TicketMetadataStoreInterface $metadata,
        private Queue $queue,
        private Settings $settings,
    ) {
    }

    public function record(Change $change): void
    {
        if ($change->ticketId === null || $change->projectId === null) {
            return;
        }
        if (!in_array($change->type, ['ticket.created', 'ticket.updated', 'ticket.transferred'], true)) {
            return;
        }

        $values = $this->metadata->read($change->projectId, [$change->ticketId])[$change->ticketId] ?? [];
        $email  = EmailType::address($values['board_mail.requester'] ?? null);
        $query  = $this->pdo->prepare('SELECT * FROM board_mail_cases WHERE ticket_id=? FOR UPDATE');
        $query->execute([$change->ticketId]);
        $old = $query->fetch(PDO::FETCH_ASSOC);

        if ($old && $old['recipient'] === $email) {
            return;
        }
        if (!$old && $email === null) {
            return;
        }

        if (!$this->settings->enabled && !$old) {
            return;
        }
        // Revoke an existing reference even when mail is temporarily disabled.
        $email = $this->settings->enabled ? $email : null;

        // Changing or clearing the requester revokes the previous reference.
        $reference = 'V-' . strtoupper(bin2hex(random_bytes(12)));
        $this->pdo->prepare(
            'INSERT INTO board_mail_cases(ticket_id,reference,recipient) VALUES(?,?,?)'
            . ' ON DUPLICATE KEY UPDATE reference=VALUES(reference),recipient=VALUES(recipient),notified_at=NULL',
        )->execute([$change->ticketId, $reference, $email]);
        $this->metadata->write($change->projectId, $change->ticketId, ['board_mail.reference' => $email === null ? null : $reference]);

        if ($email !== null) {
            $this->queue->push(ConfirmationJob::class, ['ticketId' => $change->ticketId, 'reference' => $reference]);
        }
    }
}
