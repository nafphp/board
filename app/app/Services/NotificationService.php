<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Change;
use App\Jobs\DeliverNotificationJob;
use LogicException;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Models\Mail;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\NotificationServiceInterface;
use PDO;
use RuntimeException;
use Throwable;

use function Naf\config;
use function Naf\Queue\queue;

final class NotificationService implements NotificationServiceInterface
{
    public function __construct(private PDO $pdo, private AccessInterface $access)
    {
    }

    public function record(Change $change, int $activity): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Notifications require the domain transaction.');
        }
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT m.user_id,
                   COALESCE(up.notify_in_app, 1) AS in_app,
                   COALESCE(up.notify_mail, 0) AS mail
            FROM project_members m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN user_preferences up ON up.user_id = m.user_id
            LEFT JOIN project_preferences pp ON pp.project_id = m.project_id
            AND pp.user_id = m.user_id
            WHERE m.project_id = ?
                AND m.active = 1
                AND u.active = 1
                AND m.user_id <> ?
                AND COALESCE(pp.muted, 0) = 0
            SQL,
        );
        $statement->execute([$change->projectId, $change->actorId]);
        foreach ($statement->fetchAll() as $recipient) {
            if (!(int) $recipient['in_app'] && !(int) $recipient['mail']) {
                continue;
            }
            $sql = 'INSERT INTO notifications(project_id,ticket_id,user_id,activity_id,title,created_at) VALUES(?,?,?,?,?,?)';
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $sql .= ' RETURNING id';
            }
            $insert = $this->pdo->prepare($sql);
            $insert->execute([
                $change->projectId,
                $change->ticketId,
                $recipient['user_id'],
                $activity,
                $change->type,
                gmdate('Y-m-d H:i:s'),
            ]);
            $id = (int) ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
                    ? $insert->fetchColumn()
                    : $this->pdo->lastInsertId());
            if ((int) $recipient['mail'] && config('nafinity:mail_enabled', false)) {
                $this->pdo
                    ->prepare(
                        "INSERT INTO notification_deliveries(notification_id,channel,state) VALUES(?,'mail','pending')",
                    )
                    ->execute([$id]);
                queue()->push(DeliverNotificationJob::class, [
                    'notificationId' => $id,
                    '_job_id'        => 'notification:' . $id,
                ]);
            }
        }
    }

    public function list(): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT n.*,
                   p.name AS project_name,
                   p.ticket_key,
                   t.number AS ticket_number
            FROM notifications n
            JOIN projects p ON p.id = n.project_id
            LEFT JOIN tickets t ON t.project_id = n.project_id
            AND t.id = n.ticket_id
            JOIN project_members m ON m.project_id = n.project_id
            AND m.user_id = n.user_id
            LEFT JOIN user_preferences up ON up.user_id = n.user_id
            LEFT JOIN project_preferences pp ON pp.project_id = n.project_id
            AND pp.user_id = n.user_id
            WHERE n.user_id = ?
                AND m.active = 1
                AND COALESCE(up.notify_in_app, 1) = 1
                AND COALESCE(pp.muted, 0) = 0
            ORDER BY n.id DESC
            LIMIT 100
            SQL,
        );
        $statement->execute([$this->access->actor()]);

        return $statement->fetchAll();
    }

    public function markRead(): void
    {
        $this->pdo
            ->prepare('UPDATE notifications SET read_at=? WHERE user_id=? AND read_at IS NULL')
            ->execute([gmdate('Y-m-d H:i:s'), $this->access->actor()]);
    }

    /** At-least-once mail: the ledger avoids normal duplicates, but SMTP cannot be made atomic with PDO. */
    public function deliver(int $id, Mailer $mailer): void
    {
        if (!config('nafinity:mail_enabled', false)) {
            return;
        }
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                "SELECT * FROM notification_deliveries WHERE notification_id=? AND channel='mail' FOR UPDATE",
            );
            $statement->execute([$id]);
            $delivery = $statement->fetch();
            if (!$delivery || in_array($delivery['state'], ['sent', 'skipped'], true)) {
                $this->pdo->commit();

                return;
            }
            // Recheck current membership and preferences immediately before delivery.
            $statement = $this->pdo->prepare(
                <<<'SQL'
                SELECT n.*,
                       u.email,
                       u.active,
                       m.active AS member_active,
                       COALESCE(up.notify_mail, 0) AS notify_mail,
                       COALESCE(pp.muted, 0) AS muted
                FROM notifications n
                JOIN users u ON u.id = n.user_id
                JOIN project_members m ON m.project_id = n.project_id
                AND m.user_id = n.user_id
                LEFT JOIN user_preferences up ON up.user_id = n.user_id
                LEFT JOIN project_preferences pp ON pp.project_id = n.project_id
                AND pp.user_id = n.user_id
                WHERE n.id = ?
                SQL,
            );
            $statement->execute([$id]);
            $notification = $statement->fetch();
            if (
                !$notification
                || !(int) $notification['active']
                || !(int) $notification['member_active']
                || !(int) $notification['notify_mail']
                || (int) $notification['muted']
            ) {
                $this->pdo
                    ->prepare(
                        "UPDATE notification_deliveries SET state='skipped' WHERE notification_id=? AND channel='mail'",
                    )
                    ->execute([$id]);
                $this->pdo->commit();

                return;
            }
            $this->pdo
                ->prepare(
                    <<<'SQL'
                    UPDATE notification_deliveries
                    SET state = 'sending',
                        attempts = attempts + 1,
                        last_error = NULL
                    WHERE notification_id = ?
                        AND channel = 'mail'
                    SQL,
                )
                ->execute([$id]);
            // Generic text limits exposure if access is revoked while an external service is sending.
            $mail = (new Mail())
                ->setFrom(config('nafinity:mail_from'))
                ->addTo($notification['email'])
                ->setSubject('Neuigkeiten in Nafinity')
                ->setContent(
                    'In deinem Workspace gibt es ein Update. Öffne Nafinity: '
                        . config('app:url')
                        . '/notifications',
                    false,
                );
            if (!$mailer->send($mail)) {
                throw new RuntimeException('Mail transport rejected delivery.');
            }
            $this->pdo
                ->prepare(
                    "UPDATE notification_deliveries SET state='sent',sent_at=? WHERE notification_id=? AND channel='mail'",
                )
                ->execute([gmdate('Y-m-d H:i:s'), $id]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo
                ->prepare(
                    <<<'SQL'
                    UPDATE notification_deliveries
                    SET state = 'failed',
                        attempts = attempts + 1,
                        last_error = ?
                    WHERE notification_id = ?
                        AND channel = 'mail'
                        AND state <> 'sent'
                    SQL,
                )
                ->execute([substr($exception->getMessage(), 0, 255), $id]);
            throw $exception;
        }
    }
}
