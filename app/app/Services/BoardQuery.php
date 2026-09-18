<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Support\Input;
use App\Support\TicketFilter;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\BoardQueryInterface;
use Nafinity\Contracts\TicketServiceInterface;
use Nafinity\Contracts\TimerServiceInterface;
use PDO;

final class BoardQuery implements BoardQueryInterface
{
    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private TicketServiceInterface $tickets,
        private TimerServiceInterface $timers,
    ) {
    }

    public function projects(): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT p.*,
                   COALESCE(r.name, m.role) AS role,

                (SELECT COUNT(*)
                 FROM tickets t
                 WHERE t.project_id = p.id
                     AND t.archived_at IS NULL
                     AND t.status = 'open') AS open_count
            FROM projects p
            JOIN project_members m ON m.project_id = p.id
            LEFT JOIN project_roles r ON r.project_id=m.project_id AND r.id=m.custom_role_id
            WHERE m.user_id = ?
                AND m.active = 1
            ORDER BY CASE
                         WHEN p.archived_at IS NULL THEN 0
                         ELSE 1
                     END,
                     p.id
            SQL,
        );
        $statement->execute([$this->access->actor()]);

        return $statement->fetchAll();
    }

    /**
     * The projects a ticket could be moved into: every other project the person is an active
     * member of and may write in. Rights are read per project because a custom role can
     * grant less than its name suggests.
     */
    public function transferTargets(int $exclude): array
    {
        $rows = $this->rows(
            <<<'SQL'
            SELECT p.id,
                   p.name,
                   p.ticket_key,
                   m.role,
                   m.custom_role_id
            FROM projects p
            JOIN project_members m ON m.project_id = p.id
            WHERE m.user_id = ?
                AND m.active = 1
                AND p.archived_at IS NULL
                AND p.id <> ?
            ORDER BY p.name
            SQL,
            [$this->access->actor(), $exclude],
        );

        return array_values(array_filter($rows, fn(array $row) => in_array(
            'write',
            $this->access->permissions(
                (int) $row['id'],
                $row['role'],
                $row['custom_role_id'] === null ? null : (int) $row['custom_role_id'],
            ),
            true,
        )));
    }

    public function board(int $project, array $query = []): array
    {
        $scope   = $this->access->project($project);
        $board   = $this->tickets->board($project);
        $query   = TicketFilter::from($query)->values;
        $filters = [];
        $where   = [
            't.project_id=?',
            ($query['status'] ?? '') === 'archived'
                ? 't.archived_at IS NOT NULL'
                : 't.archived_at IS NULL',
        ];
        $params = [$project];
        if (($query['status'] ?? '') === 'archived') {
            $filters['status'] = 'archived';
            unset($query['status']);
        }
        foreach (['column' => 'column_id', 'swimlane' => 'swimlane_id'] as $filter => $column) {
            if (isset($query[$filter]) && $query[$filter] !== '') {
                $filters[$filter] = Input::id($query[$filter], $filter);
                $where[]          = 't.' . $column . '=?';
                $params[]         = $filters[$filter];
            }
        }
        $relationFilters = [
            'assignee' => ['ticket_assignees', 'user_id'],
            'label'    => ['ticket_labels', 'label_id'],
        ];

        foreach ($relationFilters as $filter => [$table, $column]) {
            if (isset($query[$filter]) && $query[$filter] !== '') {
                $filters[$filter] = Input::id($query[$filter], $filter);
                $where[]          = "EXISTS(SELECT 1 FROM $table f WHERE f.project_id=t.project_id AND f.ticket_id=t.id AND f.$column=?)";
                $params[]         = $filters[$filter];
            }
        }
        $choiceFilters = [
            'status'   => ['open', 'closed'],
            'priority' => ['low', 'normal', 'high', 'urgent'],
        ];

        foreach ($choiceFilters as $filter => $allowed) {
            if (isset($query[$filter]) && $query[$filter] !== '') {
                if (!is_string($query[$filter]) || !in_array($query[$filter], $allowed, true)) {
                    throw new Failure('Ungültiger Filter: ' . $filter);
                }
                $filters[$filter] = $query[$filter];
                $where[]          = 't.' . $filter . '=?';
                $params[]         = $query[$filter];
            }
        }
        if (isset($query['q']) && $query['q'] !== '') {
            $search       = Input::validate($query, ['q' => 'string|max:200'])['q'];
            $filters['q'] = $search;
            $isMysql      = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $where[]      = $isMysql
                ? 'MATCH(t.title,t.description) AGAINST(? IN NATURAL LANGUAGE MODE)'
                : "to_tsvector('simple',t.title || ' ' || t.description) @@ plainto_tsquery('simple',?)";
            $params[] = $search;
        }
        $clause    = implode(' AND ', $where);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM tickets t WHERE ' . $clause);
        $statement->execute($params);
        $total     = (int) $statement->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT t.* FROM tickets t WHERE ' . $clause . ' ORDER BY t.position,t.id LIMIT 300',
        );
        $statement->execute($params);
        $cards   = $statement->fetchAll();
        $labels  = $this->rows('SELECT * FROM labels WHERE project_id=? ORDER BY name', [$project]);
        $members = $this->rows(
            <<<'SQL'
            SELECT u.id,
                   u.name,
                   u.email,
                   COALESCE(r.name, m.role) AS role,
                   m.custom_role_id
            FROM project_members m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN project_roles r ON r.project_id=m.project_id AND r.id=m.custom_role_id
            WHERE m.project_id = ?
                AND m.active = 1
                AND u.active = 1
            ORDER BY u.name
            SQL,
            [$project],
        );
        $assignments = [];
        $tags        = [];
        if ($cards) {
            $ids   = array_column($cards, 'id');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            foreach (
                $this->rows(
                    "SELECT ticket_id,user_id FROM ticket_assignees WHERE project_id=? AND ticket_id IN ($marks)",
                    [$project, ...$ids],
                ) as $item
            ) {
                $assignments[$item['ticket_id']][] = $item['user_id'];
            }
            foreach (
                $this->rows(
                    "SELECT ticket_id,label_id FROM ticket_labels WHERE project_id=? AND ticket_id IN ($marks)",
                    [$project, ...$ids],
                ) as $item
            ) {
                $tags[$item['ticket_id']][] = $item['label_id'];
            }
        }

        return [
            'scope'   => $scope,
            'project' => $scope->project,
            'board'   => $board,
            'columns' => $this->rows(
                'SELECT * FROM board_columns WHERE project_id=? ORDER BY position,id',
                [$project],
            ),
            'swimlanes' => $this->rows(
                'SELECT * FROM swimlanes WHERE project_id=? ORDER BY position,id',
                [$project],
            ),
            'cards'          => $cards,
            'labels'         => $labels,
            'members'        => $members,
            'assignments'    => $assignments,
            'tags'           => $tags,
            'filters'        => $filters,
            'total'          => $total,
            'running_timers' => $this->timers->runningIn($project),
        ];
    }

    public function detail(int $project, int $ticket): array
    {
        $this->access->project($project);
        $row = $this->tickets->ticket($project, $ticket);

        return [
            'ticket'         => $row,
            'creator'        => $this->rows('SELECT name FROM users WHERE id=?', [$row['created_by']])[0]['name'],
            'linked_tickets' => $this->rows(
                <<<'SQL'
                SELECT t.id,
                       t.number,
                       t.title,
                       t.status
                FROM ticket_links l
                JOIN tickets t ON t.project_id = l.project_id
                    AND t.id = CASE WHEN l.ticket_id = ? THEN l.related_id ELSE l.ticket_id END
                WHERE l.project_id = ?
                    AND (l.ticket_id = ? OR l.related_id = ?)
                ORDER BY t.number
                SQL,
                [$ticket, $project, $ticket, $ticket],
            ),
            'comments' => $this->rows(
                <<<'SQL'
                SELECT c.id, c.project_id, c.ticket_id, c.author_id, c.parent_id,
                       c.created_at, c.updated_at, c.deleted_at, c.version,
                       CASE WHEN c.deleted_at IS NULL THEN c.body ELSE '' END AS body,
                       u.name AS author_name
                FROM comments c
                JOIN users u ON u.id = c.author_id
                WHERE c.project_id = ?
                    AND c.ticket_id = ?
                ORDER BY c.id
                SQL,
                [$project, $ticket],
            ),
            'activity' => $this->rows(
                <<<'SQL'
                SELECT a.id,
                       a.event_type,
                       a.payload,
                       a.created_at,
                       u.name AS actor_name
                FROM activities a
                JOIN users u ON u.id = a.actor_id
                WHERE a.project_id = ?
                    AND a.ticket_id = ?
                ORDER BY a.id DESC
                LIMIT 50
                SQL,
                [$project, $ticket],
            ),
            'attachments' => $this->rows(
                <<<'SQL'
                SELECT id,
                       original_name,
                       mime_type,
                       byte_size,
                       created_at,
                       state
                FROM attachments
                WHERE project_id = ?
                    AND ticket_id = ?
                    AND state <> 'deleting'
                ORDER BY id
                SQL,
                [$project, $ticket],
            ),
            'selected_labels' => array_column(
                $this->rows(
                    'SELECT label_id FROM ticket_labels WHERE project_id=? AND ticket_id=?',
                    [$project, $ticket],
                ),
                'label_id',
            ),
            'selected_assignees' => array_column(
                $this->rows(
                    'SELECT user_id FROM ticket_assignees WHERE project_id=? AND ticket_id=?',
                    [$project, $ticket],
                ),
                'user_id',
            ),
        ];
    }

    public function activity(int $project): array
    {
        $this->access->project($project);

        return $this->rows(
            <<<'SQL'
            SELECT a.*,
                   u.name AS actor_name,
                   t.number AS ticket_number
            FROM activities a
            JOIN users u ON u.id = a.actor_id
            LEFT JOIN tickets t ON t.id = a.ticket_id
            AND t.project_id = a.project_id
            WHERE a.project_id = ?
            ORDER BY a.id DESC
            LIMIT 100
            SQL,
            [$project],
        );
    }

    public function preferences(): array
    {
        return $this->rows('SELECT * FROM user_preferences WHERE user_id=?', [
            $this->access->actor(),
        ])[0] ?? [
            'theme'         => 'system',
            'locale'        => 'de',
            'timezone'      => 'Europe/Berlin',
            'notify_in_app' => 1,
            'notify_mail'   => 0,
        ];
    }

    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }
}
