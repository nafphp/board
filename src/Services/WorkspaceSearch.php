<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Domain\Failure;
use PDO;

use function Naf\I18n\t;
use function Naf\route;

/** Project-grouped search of the authenticated person's readable workspace. @internal */
final class WorkspaceSearch
{
    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private BoardQueryInterface $boards,
    ) {
    }

    public function find(mixed $query, bool $suggestions = false): array
    {
        $this->access->actor();
        if (!is_string($query) || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 120) {
            throw new Failure(t('Bitte gib einen Suchbegriff mit höchstens 120 Zeichen ein.'), 422);
        }
        $query = trim($query);
        $empty = ['query' => $query, 'groups' => [], 'more' => false, 'count' => 0];
        if (mb_strlen($query) < 2) {
            return $empty;
        }

        $projects = [];
        foreach ($this->boards->projects() as $row) {
            try {
                // Reuse the same active-account, membership and administrator checks as a board visit.
                $scope                      = $this->access->project((int) $row['id']);
                $projects[(int) $row['id']] = $scope->project;
            } catch (Failure $failure) {
                if (!in_array($failure->status, [403, 404], true)) {
                    throw $failure;
                }
            }
        }
        if ($projects === []) {
            return $empty;
        }

        $limit   = $suggestions ? 8 : 50;
        $pattern = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $marks   = implode(',', array_fill(0, count($projects), '?'));
        $rows    = $this->pdo->prepare(
            "SELECT t.id,t.project_id,t.number,t.title,t.status,t.archived_at,p.ticket_key
             FROM tickets t JOIN projects p ON p.id=t.project_id
             WHERE t.project_id IN ($marks)
               AND (t.title LIKE ? ESCAPE '!' OR t.description LIKE ? ESCAPE '!'
                    OR CONCAT(p.ticket_key,'-',t.number) LIKE ? ESCAPE '!')
             ORDER BY CASE WHEN CONCAT(p.ticket_key,'-',t.number)=? THEN 0 ELSE 1 END,
                      t.updated_at DESC,t.id DESC LIMIT " . ($limit + 1),
        );
        $rows->execute([...array_keys($projects), $pattern, $pattern, $pattern, $query]);
        $tickets = $rows->fetchAll(PDO::FETCH_ASSOC);
        $more    = count($tickets) > $limit;
        $groups  = [];
        foreach (array_slice($tickets, 0, $limit) as $ticket) {
            $id = (int) $ticket['project_id'];
            $groups[$id] ??= $this->group($projects[$id]);
            $reference                = $ticket['ticket_key'] . '-' . $ticket['number'];
            $groups[$id]['tickets'][] = [
                'id'        => (int) $ticket['id'],
                'title'     => $ticket['title'],
                'reference' => $reference,
                'url'       => route('ticket', ['project' => $id, 'ticket' => $reference]),
                'archived'  => $ticket['archived_at'] !== null,
                'closed'    => $ticket['status'] === 'closed',
            ];
        }

        $boardLimit = $suggestions ? 6 : 20;
        $matched    = 0;
        foreach ($projects as $id => $project) {
            if (mb_stripos($project['name'] . ' ' . $project['ticket_key'], $query) === false) {
                continue;
            }
            if (++$matched > $boardLimit) {
                $more = true;
                continue;
            }
            $groups[$id] ??= $this->group($project);
        }
        uasort($groups, static fn(array $a, array $b) => strnatcasecmp(
            mb_strtolower($a['project']['name']),
            mb_strtolower($b['project']['name']),
        ));

        return [
            'query'  => $query,
            'groups' => array_values($groups),
            'more'   => $more,
            'count'  => count($groups) + array_sum(array_map(static fn(array $group) => count($group['tickets']), $groups)),
        ];
    }

    private function group(array $project): array
    {
        return [
            'project' => [
                'id'       => (int) $project['id'],
                'name'     => $project['name'],
                'url'      => route('board', ['project' => $project['id']]),
                'archived' => $project['archived_at'] !== null,
            ],
            'tickets' => [],
        ];
    }
}
