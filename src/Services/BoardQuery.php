<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Contracts\TimerServiceInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Rbac\Installation;
use Naf\Board\Rbac\Project;
use Naf\Board\Support\BoardFilterContext;
use Naf\Rbac\Scope;
use PDO;

use function Naf\Board\extensions;
use function Naf\I18n\t;
use function Naf\Rbac\rbac;

/** @internal */
final class BoardQuery implements BoardQueryInterface
{
    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private TicketServiceInterface $tickets,
        private TimerServiceInterface $timers,
        private TicketMetadataWriter $metadata,
    ) {
    }

    public function projects(): array
    {
        $actor = $this->access->actor();

        /*
         * Somebody who administers every board sees every board. Reaching one by
         * its address and having it listed are the same right: a list that hides
         * what the next click opens is not a smaller permission, only a worse
         * way to use it.
         *
         * The membership join becomes a LEFT JOIN for them, so a board they are
         * actually in still shows the role they hold there rather than the one
         * they fall back to. `is_member` says which of the two a row is, because
         * "may open it" and "holds a role in it" are different questions and the
         * surfaces that ask them are different too.
         */
        $administers = rbac()->allows($actor, Installation::ADMIN_PROJECTS);
        $join        = $administers ? 'LEFT JOIN' : 'JOIN';

        $statement = $this->pdo->prepare(
            <<<SQL
            SELECT p.*,
                   COALESCE(r.name, m.role, ?) AS role,
                   CASE WHEN m.user_id IS NULL THEN 0 ELSE 1 END AS is_member,

                (SELECT COUNT(*)
                 FROM tickets t
                 WHERE t.project_id = p.id
                     AND t.archived_at IS NULL
                     AND t.status = 'open') AS open_count
            FROM projects p
            $join project_members m ON m.project_id = p.id AND m.user_id = ? AND m.active = 1
            LEFT JOIN project_roles r ON r.project_id=m.project_id AND r.id=m.custom_role_id
            ORDER BY CASE
                         WHEN p.archived_at IS NULL THEN 0
                         ELSE 1
                     END,
                     p.id
            SQL,
        );
        $statement->execute([Installation::ADMIN_ROLE_NAME, $actor]);

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
        $scope = $this->access->project($project);
        $board = $this->tickets->board($project);

        // The archive rule decides which tickets exist at all, so it stays out of
        // the filter registry and is applied before any fragment.
        $archived = ($query['status'] ?? '') === 'archived';
        $where    = [
            't.project_id=?',
            $archived ? 't.archived_at IS NOT NULL' : 't.archived_at IS NULL',
        ];
        $params  = [$project];
        $filters = [];

        if ($archived) {
            $filters['status'] = 'archived';
            unset($query['status']);
        }

        foreach ($this->requested($query) as $id => $value) {
            $definition = extensions()->boardFilters()->get($id);

            if ($definition === null) {
                throw new Failure(t('Unbekannter Filter: :filter', ['filter' => $id]), 422);
            }

            $normalized   = $definition->normalize($value);
            $filters[$id] = $normalized;
            $condition    = $definition->condition(
                $normalized,
                new BoardFilterContext($project, $scope),
            );
            $where[] = '(' . $condition->sql . ')';
            $params  = [...$params, ...$condition->parameters];
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
            'card_metadata'  => $this->metadata->readable(
                $scope,
                $project,
                array_map('intval', array_column($cards, 'id')),
            ),
        ];
    }

    /**
     * The filters a request actually asks for, core names and plugin ids alike
     *
     * Core query parameters keep their own names; a contributed filter arrives
     * as filters[<id>]. An id nobody registered is reported rather than ignored.
     *
     * @param array $query The request's query parameters
     *
     * @return array<string, mixed>
     */
    private function requested(array $query): array
    {
        $requested = [];

        foreach (extensions()->boardFilters()->all() as $id => $definition) {
            if (isset($query[$id]) && $query[$id] !== '') {
                $requested[$id] = $query[$id];
            }
        }

        $contributed = $query['filters'] ?? [];

        if (!is_array($contributed)) {
            throw new Failure(t('Ungültige Filter.'), 422);
        }

        foreach ($contributed as $id => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            if (!is_string($id)) {
                throw new Failure(t('Ungültiger Filtername.'), 422);
            }

            $requested[$id] = $value;
        }

        return $requested;
    }

    public function detail(int $project, int $ticket): array
    {
        $scope = $this->access->project($project);
        $row   = $this->tickets->ticket($project, $ticket);

        return [
            'ticket'   => $row,
            'metadata' => $this->metadata->readable($scope, $project, [$ticket])[$ticket] ?? [],
            // Only definitions this actor may read; an unknown stored key is
            // reported as a missing extension, never as a value.
            'metaDefinitions' => array_filter(
                extensions()->ticketFields()->metadata(),
                static fn($field) => $scope->allows($field->readPermission),
            ),
            'metaUnknown'    => $this->metadata->unknownKeys($project, $ticket),
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
            LEFT JOIN users u ON u.id = a.actor_id
            LEFT JOIN tickets t ON t.id = a.ticket_id
            AND t.project_id = a.project_id
            WHERE a.scope = ?
            ORDER BY a.id DESC
            LIMIT 100
            SQL,
            [(string) Scope::of(Project::SCOPE, $project)],
        );
    }

    /**
     * The entries recorded after the one a page already shows.
     *
     * Oldest first, because they are put on top one after another and the last
     * one placed has to end up highest. Capped, because a page that was left
     * open over a weekend should not be handed a weekend.
     *
     * @return list<array<string,mixed>>
     */
    public function activitySince(int $project, int $after, int $limit = 50): array
    {
        $this->access->project($project);

        return $this->rows(
            <<<'SQL'
            SELECT a.*,
                   u.name AS actor_name,
                   t.number AS ticket_number
            FROM activities a
            LEFT JOIN users u ON u.id = a.actor_id
            LEFT JOIN tickets t ON t.id = a.ticket_id
            AND t.project_id = a.project_id
            WHERE a.scope = ?
                AND a.id > ?
            ORDER BY a.id ASC
            SQL
            // Appended rather than bound: a LIMIT is not a value to every driver,
            // and it is not interpolated into the query above -- that block is a
            // nowdoc, which is the point of writing SQL in one.
            . ' LIMIT ' . max(1, min(200, $limit)),
            [(string) Scope::of(Project::SCOPE, $project), $after],
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
