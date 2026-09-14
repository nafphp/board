<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Change;
use PDO;

final class ActivityListener
{
    public function __construct(private PDO $pdo, private \App\Services\NotificationService $notifications)
    {
    }
    public function record(Change $change): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Activity requires the domain transaction.');
        }
        $sql = 'INSERT INTO activities(project_id,ticket_id,actor_id,event_type,payload,created_at) VALUES(?,?,?,?,?,?)';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $sql .= ' RETURNING id';
        }
        $q = $this->pdo->prepare($sql);
        $q->execute([$change->projectId,$change->ticketId,$change->actorId,$change->type,json_encode($change->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),gmdate('Y-m-d H:i:s')]);
        $id = (int)($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? $q->fetchColumn() : $this->pdo->lastInsertId());
        $this->notifications->record($change, $id);
    }
}
