<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\{Change,Failure};
use App\Models\Ticket;
use App\Support\Input;
use Naf\ORM\Core\EntityManager;
use PDO;

use function Naf\event;

final class TicketService
{
    public function __construct(private PDO $pdo, private EntityManager $em, private Access $access, private ProjectService $projects)
    {
    }
    public function create(int $project, array $data): int
    {
        $fields = $this->fields($data);
        return $this->access->write($project, 'write', function () use ($project, $data, $fields) {
            $board = $this->board($project);
            $this->revision($board, $data);
            $column = $this->target($project, (int)$board['id'], 'board_columns', Input::id($data['column_id']));
            $lane = $this->target($project, (int)$board['id'], 'swimlanes', Input::id($data['swimlane_id']));
            $closed = (int)$column['closes_tickets'] === 1;
            $now = gmdate('Y-m-d H:i:s');
            $ticket = new Ticket([...$fields,'project_id' => $project,'board_id' => (int)$board['id'],'column_id' => (int)$column['id'],'swimlane_id' => (int)$lane['id'],'number' => (int)$board['next_number'],'status' => $closed ? 'closed' : 'open','created_by' => $this->access->actor(),'created_at' => $now,'updated_at' => $now,'closed_at' => $closed ? $now : null,'archived_at' => null,'position' => $this->appendPosition($project, (int)$column['id'], (int)$lane['id']),'version' => 1]);
            $this->em->save($ticket);
            $id = (int)$ticket->getId();
            $this->pivots($project, $id, $data);
            $this->pdo->prepare('UPDATE boards SET next_number=next_number+1 WHERE id=?')->execute([$board['id']]);
            $this->changed($project, $id, 'ticket.created', ['title' => $fields['title'],'number' => (string)$board['next_number']]);
            return $id;
        });
    }
    public function update(int $project, int $id, array $data): void
    {
        $fields = $this->fields($data);
        $this->access->write($project, 'write', function () use ($project, $id, $data, $fields) {
            $row = $this->ticket($project, $id);
            $this->version($row, $data);
            $this->revision($this->board($project), $data);
            if ($row['archived_at'] !== null) {
                throw new Failure('Ein archiviertes Ticket kann nicht bearbeitet werden.');
            }
            $changed = [];
            foreach ($fields as $key => $value) {
                if ($row[$key] !== $value) {
                    $changed[] = $key;
                }
            }
            $ticket = new Ticket([...$row,...$fields,'version' => (int)$row['version'] + 1,'updated_at' => gmdate('Y-m-d H:i:s')]);
            $this->em->save($ticket);
            $this->pivots($project, $id, $data);
            $this->changed($project, $id, 'ticket.updated', ['fields' => $changed]);
        });
    }
    public function move(int $project, int $id, array $data): void
    {
        $this->access->write($project, 'write', function () use ($project, $id, $data) {
            $row = $this->ticket($project, $id);
            $this->version($row, $data);
            $board = $this->board($project);
            $this->revision($board, $data);
            if ($row['archived_at'] !== null) {
                throw new Failure('Ein archiviertes Ticket kann nicht verschoben werden.');
            }
            $column = $this->target($project, (int)$board['id'], 'board_columns', Input::id($data['column_id']));
            $lane = $this->target($project, (int)$board['id'], 'swimlanes', Input::id($data['swimlane_id']));
            $q = $this->pdo->prepare('SELECT id,position FROM tickets WHERE project_id=? AND column_id=? AND swimlane_id=? AND id<>? ORDER BY position,id');
            $q->execute([$project,$column['id'],$lane['id'],$id]);
            $rows = $q->fetchAll();
            $left = empty($data['left_id']) ? null : Input::id($data['left_id']);
            $right = empty($data['right_id']) ? null : Input::id($data['right_id']);
            if (($data['placement'] ?? 'append') === 'append') {
                $left = $rows ? (int)$rows[array_key_last($rows)]['id'] : null;
                $right = null;
            }
            $ids = array_map(static fn ($item) => (int)$item['id'], $rows);
            $leftIndex = $left === null ? -1 : array_search($left, $ids, true);
            $rightIndex = $right === null ? count($rows) : array_search($right, $ids, true);
            if ($leftIndex === false || $rightIndex === false || $rightIndex !== $leftIndex + 1) {
                throw new Failure('Die Zielposition hat sich geändert. Bitte lade das Board neu.', 409);
            }
            $position = $this->between($rows, $leftIndex, $rightIndex);
            if ($position === null) {
                // Include the moving card while rebalancing to avoid colliding with its old position.
                $this->rebalance($project, (int)$column['id'], (int)$lane['id']);
                $q->execute([$project,$column['id'],$lane['id'],$id]);
                $rows = $q->fetchAll();
                $position = $this->between($rows, $leftIndex, $rightIndex);
            }
            if ($position === null) {
                throw new Failure('Keine freie Kartenposition verfügbar.', 409);
            }
            $now = gmdate('Y-m-d H:i:s');
            $closed = (int)$column['closes_tickets'] === 1;
            $ticket = new Ticket([...$row,'column_id' => (int)$column['id'],'swimlane_id' => (int)$lane['id'],'position' => $position,'status' => $closed ? 'closed' : 'open','closed_at' => $closed ? ($row['closed_at'] ?? $now) : null,'version' => (int)$row['version'] + 1,'updated_at' => $now]);
            $this->em->save($ticket);
            $this->changed($project, $id, 'ticket.moved', ['from' => $row['column_id'],'to' => (string)$column['id'],'column' => $column['name'],'swimlane' => $lane['name']]);
        });
    }
    public function state(int $project, int $id, array $data): void
    {
        $action = $data['action'] ?? '';
        if (!in_array($action, ['close','reopen','archive','restore'], true)) {
            throw new Failure('Ungültige Ticketaktion.');
        }
        $this->access->write($project, 'write', function () use ($project, $id, $data, $action) {
            $row = $this->ticket($project, $id);
            $this->version($row, $data);
            $now = gmdate('Y-m-d H:i:s');
            $row['version'] = (int)$row['version'] + 1;
            $row['updated_at'] = $now;
            if ($action === 'archive' || $action === 'restore') {
                $row['archived_at'] = $action === 'archive' ? $now : null;
            } else {
                $row['status'] = $action === 'close' ? 'closed' : 'open';
                $row['closed_at'] = $action === 'close' ? $now : null;
            }
            $this->em->save(new Ticket($row));
            $this->changed($project, $id, 'ticket.'.$action);
        });
    }
    public function ticket(int $project, int $id): array
    {
        $q = $this->pdo->prepare('SELECT * FROM tickets WHERE project_id=? AND id=?');
        $q->execute([$project,$id]);
        $row = $q->fetch();
        if (!$row) {
            throw new Failure('Ticket nicht gefunden.', 404);
        }return $row;
    }
    public function board(int $project): array
    {
        $q = $this->pdo->prepare('SELECT * FROM boards WHERE project_id=?');
        $q->execute([$project]);
        return $q->fetch() ?: throw new Failure('Board nicht gefunden.', 404);
    }
    private function fields(array $data): array
    {
        $v = Input::validate($data, ['title' => 'required|string|max:200','description' => 'string|max:50000','priority' => 'required|string']);
        $v['title'] = trim($v['title']);
        if ($v['title'] === '') {
            throw new Failure('Ein Titel wird benötigt.');
        }
        if (!in_array($v['priority'], ['low','normal','high','urgent'], true)) {
            throw new Failure('Ungültige Priorität.');
        }
        $v['color'] = $this->projects->color($data['color'] ?? '#6366f1');
        $due = $data['due_date'] ?? null;
        if ($due === '') {
            $due = null;
        }
        if ($due !== null) {
            Input::validate(['due_date' => $due], ['due_date' => 'date']);
        }$v['due_date'] = $due;
        return $v;
    }
    private function pivots(int $project, int $ticket, array $data): void
    {
        foreach (['label_ids' => ['labels','ticket_labels','label_id'],'assignee_ids' => ['project_members','ticket_assignees','user_id']] as $field => [$source,$pivot,$key]) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $ids = Input::ids($data[$field], $field);
            foreach ($ids as $id) {
                $column = $source === 'labels' ? 'id' : 'user_id';
                $q = $this->pdo->prepare("SELECT COUNT(*) FROM $source WHERE project_id=? AND $column=?".($source === 'project_members' ? ' AND active=1' : ''));
                $q->execute([$project,$id]);
                if ((int)$q->fetchColumn() !== 1) {
                    throw new Failure('Die Auswahl gehört nicht zu diesem Projekt.', 422, [$field => ['Ungültige Zuordnung.']]);
                }
            }
            $this->pdo->prepare("DELETE FROM $pivot WHERE project_id=? AND ticket_id=?")->execute([$project,$ticket]);
            $q = $this->pdo->prepare("INSERT INTO $pivot(project_id,ticket_id,$key) VALUES(?,?,?)");
            foreach ($ids as $id) {
                $q->execute([$project,$ticket,$id]);
            }
        }
    }
    private function target(int $project, int $board, string $table, int $id): array
    {
        $q = $this->pdo->prepare("SELECT * FROM $table WHERE project_id=? AND board_id=? AND id=?");
        $q->execute([$project,$board,$id]);
        return $q->fetch() ?: throw new Failure('Das Ziel gehört nicht zu diesem Board.', 422);
    }
    private function version(array $row, array $data): void
    {
        if (Input::id($data['version'] ?? null, 'version') !== (int)$row['version']) {
            throw new Failure('Dieses Ticket wurde inzwischen geändert. Bitte lade es neu.', 409);
        }
    }
    private function revision(array $board, array $data): void
    {
        if (Input::id($data['board_revision'] ?? null, 'board_revision') !== (int)$board['revision']) {
            throw new Failure('Das Board wurde inzwischen geändert. Bitte lade es neu.', 409);
        }
    }
    private function appendPosition(int $project, int $column, int $lane): int
    {
        $q = $this->pdo->prepare('SELECT COALESCE(MAX(position),0) FROM tickets WHERE project_id=? AND column_id=? AND swimlane_id=?');
        $q->execute([$project,$column,$lane]);
        $position = (int)$q->fetchColumn();
        if ($position > PHP_INT_MAX - 1024) {
            $this->rebalance($project, $column, $lane);
            $q->execute([$project,$column,$lane]);
            $position = (int)$q->fetchColumn();
        }
        return $position + 1024;
    }
    private function between(array $rows, int $left, int $right): ?int
    {
        $lo = $left < 0 ? 0 : (int)$rows[$left]['position'];
        if ($right === count($rows)) {
            return $lo > PHP_INT_MAX - 1024 ? null : $lo + 1024;
        }
        $hi = (int)$rows[$right]['position'];
        return $hi - $lo > 1 ? $lo + intdiv($hi - $lo, 2) : null;
    }
    private function rebalance(int $project, int $column, int $lane): void
    {
        $q = $this->pdo->prepare('SELECT id FROM tickets WHERE project_id=? AND column_id=? AND swimlane_id=? ORDER BY position,id');
        $q->execute([$project,$column,$lane]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        $update = $this->pdo->prepare('UPDATE tickets SET position=? WHERE project_id=? AND id=?');
        foreach ($ids as $i => $id) {
            $update->execute([-1024 * ($i + 1),$project,$id]);
        }
        foreach ($ids as $i => $id) {
            $update->execute([1024 * ($i + 1),$project,$id]);
        }
    }
    private function changed(int $project, int $ticket, string $type, array $data = []): void
    {
        $this->pdo->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')->execute([$project]);
        event()->dispatch('nafinity.changed', new Change($project, $ticket, $this->access->actor(), $type, $data));
    }
}
