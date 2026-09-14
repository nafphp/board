<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\{Change,Failure};
use App\Jobs\FinalizeAttachmentJob;
use App\Support\AttachmentStorage;
use Naf\ORM\Core\EntityManager;
use PDO;
use Psr\Http\Message\UploadedFileInterface;

use function Naf\event;
use function Naf\Queue\queue;

final class AttachmentService
{
    public function __construct(private PDO $pdo, private Access $access, private TicketService $tickets, private AttachmentStorage $storage, private EntityManager $em)
    {
    }
    public function upload(int $project, int $ticket, UploadedFileInterface $upload): void
    {
        $this->access->project($project, 'write');
        $this->tickets->ticket($project, $ticket);
        try {
            $file = $this->storage->stage($upload);
        } catch (\InvalidArgumentException $e) {
            throw new Failure($e->getMessage());
        }
        $committed = false;
        try {
            $id = $this->access->write($project, 'write', function () use ($project, $ticket, $file) {
                if ($this->tickets->ticket($project, $ticket)['archived_at'] !== null) {
                    throw new Failure('Archivierte Tickets nehmen keine neuen Anhänge an.');
                }
                $q = $this->pdo->prepare("SELECT COALESCE(SUM(byte_size),0) FROM attachments WHERE project_id=? AND state<>'deleting'");
                $q->execute([$project]);
                if ((int)$q->fetchColumn() + $file['size'] > 200 * 1024 * 1024) {
                    throw new Failure('Das Projektlimit von 200 MiB ist erreicht.');
                }
                $q = $this->pdo->prepare("SELECT COALESCE(SUM(byte_size),0) FROM attachments WHERE project_id=? AND ticket_id=? AND state<>'deleting'");
                $q->execute([$project,$ticket]);
                if ((int)$q->fetchColumn() + $file['size'] > 30 * 1024 * 1024) {
                    throw new Failure('Das Ticketlimit von 30 MiB ist erreicht.');
                }
                $sql = "INSERT INTO attachments(project_id,ticket_id,uploaded_by,storage_key,original_name,mime_type,byte_size,sha256,state,created_at) VALUES(?,?,?,?,?,?,?,?,'staged',?)";
                if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                    $sql .= ' RETURNING id';
                }
                $q = $this->pdo->prepare($sql);
                $q->execute([$project,$ticket,$this->access->actor(),$file['key'],$file['name'],$file['mime'],$file['size'],$file['sha256'],gmdate('Y-m-d H:i:s')]);
                $id = (int)($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? $q->fetchColumn() : $this->pdo->lastInsertId());
                queue()->push(FinalizeAttachmentJob::class, ['attachmentId' => $id,'_job_id' => 'attachment:'.$id]);
                return $id;
            });
            $committed = true;
            // Fast path. The durable job remains available if promotion or the request crashes.
            try {
                $this->finalize($id);
            } catch (\Throwable $error) {
                \Naf\log()->warning('Attachment finalization deferred to its durable job.', ['attachment_id' => $id]);
            }
        } finally {
            if (!$committed) {
                $this->storage->delete($file['key']);
            }
        }
    }
    public function remove(int $project, int $ticket, int $id): void
    {
        $this->access->write($project, 'write', function () use ($project, $ticket, $id) {
            $row = $this->row($project, $ticket, $id);
            $this->pdo->prepare("UPDATE attachments SET state='deleting' WHERE id=?")->execute([$id]);
            queue()->push(FinalizeAttachmentJob::class, ['attachmentId' => $id,'_job_id' => 'attachment-delete:'.$id]);
            event()->dispatch('nafinity.changed', new Change($project, $ticket, $this->access->actor(), 'attachment.deleted', ['name' => $row['original_name']]));
            $this->pdo->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')->execute([$project]);
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
        } catch (\RuntimeException) {
            throw new Failure('Anhang ist nicht verfügbar.', 404);
        }return $row;
    }
    /** Trusted job entry point; derives all scope from stored metadata and rechecks uploader membership. */
    public function finalize(int $id): void
    {
        $q = $this->pdo->prepare('SELECT project_id FROM attachments WHERE id=?');
        $q->execute([$id]);
        $project = $q->fetchColumn();
        if (!$project) {
            return;
        }
        $this->em->begin();
        try {
            $q = $this->pdo->prepare('SELECT id FROM projects WHERE id=? FOR UPDATE');
            $q->execute([$project]);
            $q = $this->pdo->prepare('SELECT * FROM attachments WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $row = $q->fetch();
            if (!$row || $row['state'] === 'ready') {
                $this->em->commit();
                return;
            }
            $q = $this->pdo->prepare("SELECT COUNT(*) FROM project_members m JOIN users u ON u.id=m.user_id JOIN projects p ON p.id=m.project_id WHERE m.project_id=? AND m.user_id=? AND m.active=1 AND u.active=1 AND m.role<>'viewer' AND p.archived_at IS NULL");
            $q->execute([$project,$row['uploaded_by']]);
            if ($row['state'] === 'deleting' || !(int)$q->fetchColumn()) {
                $this->storage->delete($row['storage_key']);
                $this->pdo->prepare('DELETE FROM attachments WHERE id=?')->execute([$id]);
            } else {
                $this->storage->promote($row['storage_key']);
                $this->pdo->prepare("UPDATE attachments SET state='ready' WHERE id=?")->execute([$id]);
                $this->pdo->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')->execute([$project]);
                event()->dispatch('nafinity.changed', new Change((int)$project, (int)$row['ticket_id'], (int)$row['uploaded_by'], 'attachment.added', ['name' => $row['original_name']]));
            }
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }
    public function cleanup(): int
    {
        foreach ($this->pdo->query("SELECT id FROM attachments WHERE state IN ('staged','deleting') ORDER BY id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->finalize((int)$id);
        }
        return $this->storage->cleanup(function ($key) {
            $q = $this->pdo->prepare('SELECT COUNT(*) FROM attachments WHERE storage_key=?');
            $q->execute([$key]);
            return (int)$q->fetchColumn() > 0;
        }, time() - 86400);
    }
    private function row(int $project, int $ticket, int $id): array
    {
        $q = $this->pdo->prepare('SELECT * FROM attachments WHERE project_id=? AND ticket_id=? AND id=?');
        $q->execute([$project,$ticket,$id]);
        return $q->fetch() ?: throw new Failure('Anhang nicht gefunden.', 404);
    }
}
