<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Change;
use App\Domain\Failure;
use App\Jobs\FinalizeAttachmentJob;
use App\Support\AttachmentStorage;
use InvalidArgumentException;
use Naf\ORM\Core\EntityManager;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\AttachmentServiceInterface;
use Nafinity\Contracts\TicketServiceInterface;
use PDO;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

use function Naf\event;
use function Naf\Queue\queue;

final class AttachmentService implements AttachmentServiceInterface
{
    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private TicketServiceInterface $tickets,
        private AttachmentStorage $storage,
        private EntityManager $entityManager,
    ) {
    }

    public function upload(int $project, int $ticket, UploadedFileInterface $upload): void
    {
        $this->access->project($project, 'upload');
        $this->tickets->ticket($project, $ticket);

        try {
            $file = $this->storage->stage($upload);
        } catch (InvalidArgumentException $exception) {
            throw new Failure($exception->getMessage());
        }
        $committed = false;

        try {
            $id = $this->access->write($project, 'upload', function () use (
                $project,
                $ticket,
                $file,
            ) {
                if ($this->tickets->ticket($project, $ticket)['archived_at'] !== null) {
                    throw new Failure('Archivierte Tickets nehmen keine neuen Anhänge an.');
                }
                $statement = $this->pdo->prepare(
                    "SELECT COALESCE(SUM(byte_size),0) FROM attachments WHERE project_id=? AND state<>'deleting'",
                );
                $statement->execute([$project]);
                $projectBytesAfterUpload = (int) $statement->fetchColumn() + $file['size'];

                if ($projectBytesAfterUpload > 200 * 1024 * 1024) {
                    throw new Failure('Das Projektlimit von 200 MiB ist erreicht.');
                }
                $statement = $this->pdo->prepare(
                    "SELECT COALESCE(SUM(byte_size),0) FROM attachments WHERE project_id=? AND ticket_id=? AND state<>'deleting'",
                );
                $statement->execute([$project, $ticket]);
                $ticketBytesAfterUpload = (int) $statement->fetchColumn() + $file['size'];

                if ($ticketBytesAfterUpload > 30 * 1024 * 1024) {
                    throw new Failure('Das Ticketlimit von 30 MiB ist erreicht.');
                }
                $sql = <<<'SQL'
                INSERT INTO attachments(project_id, ticket_id, uploaded_by, storage_key, original_name, mime_type, byte_size, sha256, state, created_at)
                VALUES(?,?,?,?,?,?,?,?,'staged',?)
                SQL;
                if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                    $sql .= ' RETURNING id';
                }
                $statement = $this->pdo->prepare($sql);
                $statement->execute([
                    $project,
                    $ticket,
                    $this->access->actor(),
                    $file['key'],
                    $file['name'],
                    $file['mime'],
                    $file['size'],
                    $file['sha256'],
                    gmdate('Y-m-d H:i:s'),
                ]);
                $id = (int) ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
                        ? $statement->fetchColumn()
                        : $this->pdo->lastInsertId());
                queue()->push(FinalizeAttachmentJob::class, [
                    'attachmentId' => $id,
                    '_job_id'      => 'attachment:' . $id,
                ]);

                return $id;
            });
            $committed = true;

            // Fast path. The durable job remains available if promotion or the request crashes.
            try {
                $this->finalize($id);
            } catch (Throwable $error) {
                \Naf\log()->warning('Attachment finalization deferred to its durable job.', [
                    'attachment_id' => $id,
                ]);
            }
        } finally {
            if (!$committed) {
                $this->storage->delete($file['key']);
            }
        }
    }

    public function remove(int $project, int $ticket, int $id): void
    {
        $this->access->write($project, 'upload', function () use ($project, $ticket, $id) {
            $row = $this->row($project, $ticket, $id);
            $this->pdo
                ->prepare("UPDATE attachments SET state='deleting' WHERE id=?")
                ->execute([$id]);
            queue()->push(FinalizeAttachmentJob::class, [
                'attachmentId' => $id,
                '_job_id'      => 'attachment-delete:' . $id,
            ]);
            event()->dispatch(
                'nafinity.changed',
                new Change($project, $ticket, $this->access->actor(), 'attachment.deleted', [
                    'name' => $row['original_name'],
                ]),
            );
            $this->pdo
                ->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')
                ->execute([$project]);
        });
        $this->finalize($id);
    }

    public function download(int $project, int $ticket, int $id): array
    {
        $this->access->project($project);
        $this->tickets->ticket($project, $ticket);
        $row = $this->row($project, $ticket, $id);
        if ($row['state'] !== 'ready') {
            throw new Failure('Anhang ist noch nicht verfügbar.', 404);
        }

        try {
            $row['stream'] = $this->storage->open($row['storage_key']);
        } catch (RuntimeException) {
            throw new Failure('Anhang ist nicht verfügbar.', 404);
        }

        return $row;
    }

    /** Trusted job entry point; derives all scope from stored metadata and rechecks uploader membership. */
    public function finalize(int $id): void
    {
        $statement = $this->pdo->prepare('SELECT project_id FROM attachments WHERE id=?');
        $statement->execute([$id]);
        $project = $statement->fetchColumn();
        if (!$project) {
            return;
        }
        $this->entityManager->begin();

        try {
            $statement = $this->pdo->prepare('SELECT id FROM projects WHERE id=? FOR UPDATE');
            $statement->execute([$project]);
            $statement = $this->pdo->prepare('SELECT * FROM attachments WHERE id=? FOR UPDATE');
            $statement->execute([$id]);
            $row = $statement->fetch();
            if (!$row || $row['state'] === 'ready') {
                $this->entityManager->commit();

                return;
            }
            $statement = $this->pdo->prepare(
                <<<'SQL'
                SELECT m.role, m.custom_role_id
                FROM project_members m
                JOIN users u ON u.id = m.user_id
                JOIN projects p ON p.id = m.project_id
                WHERE m.project_id = ?
                    AND m.user_id = ?
                    AND m.active = 1
                    AND u.active = 1
                    AND p.archived_at IS NULL
                SQL,
            );
            $statement->execute([$project, $row['uploaded_by']]);
            $membership = $statement->fetch();
            $rights     = $membership ? $this->access->permissions($project, $membership['role'], $membership['custom_role_id'] === null ? null : (int) $membership['custom_role_id']) : [];
            if ($row['state'] === 'deleting' || !in_array('upload', $rights, true)) {
                $this->storage->delete($row['storage_key']);
                $this->pdo->prepare('DELETE FROM attachments WHERE id=?')->execute([$id]);
            } else {
                $this->storage->promote($row['storage_key']);
                $this->pdo
                    ->prepare("UPDATE attachments SET state='ready' WHERE id=?")
                    ->execute([$id]);
                $this->pdo
                    ->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')
                    ->execute([$project]);
                event()->dispatch(
                    'nafinity.changed',
                    new Change(
                        (int) $project,
                        (int) $row['ticket_id'],
                        (int) $row['uploaded_by'],
                        'attachment.added',
                        ['name' => $row['original_name']],
                    ),
                );
            }
            $this->entityManager->commit();
        } catch (Throwable $exception) {
            $this->entityManager->rollback();
            throw $exception;
        }
    }

    public function cleanup(): int
    {
        $pending = $this->pdo->query(
            "SELECT id FROM attachments WHERE state IN ('staged','deleting') ORDER BY id LIMIT 100",
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($pending as $id) {
            $this->finalize((int) $id);
        }

        return $this->storage->cleanup(function ($key) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM attachments WHERE storage_key=?');
            $statement->execute([$key]);

            return (int) $statement->fetchColumn() > 0;
        }, time() - 86400);
    }

    private function row(int $project, int $ticket, int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM attachments WHERE project_id=? AND ticket_id=? AND id=?',
        );
        $statement->execute([$project, $ticket, $id]);

        return $statement->fetch() ?: throw new Failure('Anhang nicht gefunden.', 404);
    }
}
