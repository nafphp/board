<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

use LogicException;
use Naf\Auth\Auth;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\CommentServiceInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use PDO;
use RuntimeException;

final class Importer
{
    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private AccessInterface $access,
        private TicketServiceInterface $tickets,
        private CommentServiceInterface $comments,
        private Settings $settings,
    ) {
    }

    /** Returns imported, duplicate or ignored; never creates tickets. */
    public function import(IncomingMail $mail): string
    {
        if (!$this->settings->enabled || $mail->automatic || $mail->sender() === null || $mail->reference() === null
            || trim($mail->text) === '' || mb_strlen($mail->comment()) > 20000) {
            return 'ignored';
        }
        if (PHP_SAPI !== 'cli' || $this->auth->check()) {
            throw new LogicException('Mail import is only available to an unauthenticated CLI worker.');
        }

        $query = $this->pdo->prepare(
            'SELECT t.project_id,c.ticket_id FROM board_mail_cases c JOIN tickets t ON t.id=c.ticket_id'
            . ' WHERE c.reference=? AND c.recipient=?',
        );
        $query->execute([$mail->reference(), $mail->sender()]);
        $case = $query->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return 'ignored';
        }

        $actor = $this->pdo->prepare('SELECT id FROM users WHERE email=?');
        $actor->execute([$this->settings->actor]);
        $identity = $this->auth->load('users', (string) ($actor->fetchColumn() ?: ''));
        if ($identity === null) {
            throw new RuntimeException('The configured mail integration account is missing or inactive.');
        }

        $this->auth->setIdentity($identity);

        try {
            return $this->access->write((int) $case['project_id'], 'comment', function () use ($case, $mail): string {
                $project = (int) $case['project_id'];
                $ticket  = (int) $case['ticket_id'];
                $this->tickets->ticket($project, $ticket);

                // Repeat sender/reference matching under the project's write lock.
                $query = $this->pdo->prepare('SELECT reference,recipient FROM board_mail_cases WHERE ticket_id=? FOR UPDATE');
                $query->execute([$ticket]);
                $current = $query->fetch(PDO::FETCH_ASSOC);
                if (!$current || $current['reference'] !== $mail->reference() || $current['recipient'] !== $mail->sender()) {
                    return 'ignored';
                }

                $seen = $this->pdo->prepare('SELECT 1 FROM board_mail_receipts WHERE receipt_key=? OR fingerprint=?');
                $seen->execute([$mail->receiptKey, $mail->fingerprint()]);
                if ($seen->fetchColumn()) {
                    return 'duplicate';
                }

                $this->comments->save($project, $ticket, ['body' => $mail->comment()]);
                $this->pdo->prepare(
                    'INSERT INTO board_mail_receipts(receipt_key,fingerprint,ticket_id,imported_at) VALUES(?,?,?,?)',
                )->execute([$mail->receiptKey, $mail->fingerprint(), $ticket, gmdate('Y-m-d H:i:s')]);

                return 'imported';
            });
        } finally {
            $this->auth->reset();
        }
    }
}
