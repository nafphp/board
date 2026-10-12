<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use PDO;

/** Relevance is shared by recording and the worker's final delivery check. @internal */
final class NotificationAudience
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<int> The caller still checks current membership, channel preferences and mute state. */
    public function users(?int $project, ?int $ticket, array $data): array
    {
        if ($project === null || $ticket === null) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT created_by AS user_id FROM tickets WHERE project_id=? AND id=?'
            . ' UNION SELECT user_id FROM ticket_assignees WHERE project_id=? AND ticket_id=?'
            . ' UNION SELECT user_id FROM ticket_subscriptions WHERE project_id=? AND ticket_id=?',
        );
        $statement->execute([$project, $ticket, $project, $ticket, $project, $ticket]);

        return array_values(array_unique([
            ...array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)),
            ...array_map('intval', $data['mentioned_user_ids'] ?? []),
        ]));
    }
}
