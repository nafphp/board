<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Domain\Failure;
use PDO;

use function Naf\I18n\t;

/** A bounded personal list across currently readable projects. @internal */
final class MyTickets
{
    public const int PAGE_SIZE = 50;

    public function __construct(private PDO $pdo, private AccessInterface $access, private BoardQueryInterface $boards)
    {
    }

    public function find(array $query): array
    {
        $actor  = $this->access->actor();
        $status = $query['status'] ?? 'open';
        $sort   = $query['sort'] ?? 'due';
        $page   = $query['page'] ?? '1';
        if (!in_array($status, ['open', 'closed', 'all'], true)
            || !in_array($sort, ['due', 'updated'], true)
            || !is_scalar($page) || !preg_match('/^[1-9][0-9]{0,5}$/D', (string) $page)) {
            throw new Failure(t('Ungültige Listenfilter.'));
        }
        $projects = [];
        foreach ($this->boards->projects() as $row) {
            try {
                $scope = $this->access->project((int) $row['id']);
                if ($scope->project['archived_at'] === null) {
                    $projects[] = (int) $row['id'];
                }
            } catch (Failure $failure) {
                if (!in_array($failure->status, [403, 404], true)) {
                    throw $failure;
                }
            }
        }
        $page   = (int) $page;
        $result = ['tickets' => [], 'total' => 0, 'page' => $page, 'pages' => 1, 'status' => $status, 'sort' => $sort];
        if ($projects === []) {
            $result['page'] = 1;

            return $result;
        }
        $marks = implode(',', array_fill(0, count($projects), '?'));
        $where = "t.project_id IN ($marks) AND t.archived_at IS NULL"
            . ' AND EXISTS(SELECT 1 FROM ticket_assignees a WHERE a.project_id=t.project_id AND a.ticket_id=t.id AND a.user_id=?)';
        $params = [...$projects, $actor];
        if ($status !== 'all') {
            $where .= ' AND t.status=?';
            $params[] = $status;
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM tickets t WHERE ' . $where);
        $count->execute($params);
        $result['total'] = (int) $count->fetchColumn();
        $result['pages'] = max(1, (int) ceil($result['total'] / self::PAGE_SIZE));
        $page            = min($page, $result['pages']);
        $result['page']  = $page;
        $order           = $sort === 'due' ? 't.due_date IS NULL,t.due_date,t.updated_at DESC,t.id DESC' : 't.updated_at DESC,t.id DESC';
        $rows            = $this->pdo->prepare(
            'SELECT t.id,t.project_id,t.number,t.title,t.status,t.priority,t.due_date,t.updated_at,p.name AS project_name,p.ticket_key'
            . ' FROM tickets t JOIN projects p ON p.id=t.project_id WHERE ' . $where
            . ' ORDER BY ' . $order . ' LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
        );
        $rows->execute($params);
        $result['tickets'] = $rows->fetchAll();

        return $result;
    }
}
