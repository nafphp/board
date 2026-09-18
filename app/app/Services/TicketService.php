<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Change;
use App\Domain\Estimation;
use App\Domain\Failure;
use App\Models\Ticket;
use App\Support\Duration;
use App\Support\Format;
use App\Support\Input;
use App\Support\RichText;
use Naf\ORM\Core\EntityManager;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\ProjectServiceInterface;
use Nafinity\Contracts\TicketServiceInterface;
use Nafinity\Contracts\TimerServiceInterface;
use PDO;
use Throwable;

use function Naf\event;

final class TicketService implements TicketServiceInterface
{
    public function __construct(
        private PDO $pdo,
        private EntityManager $entityManager,
        private AccessInterface $access,
        private ProjectServiceInterface $projects,
        private TimerServiceInterface $timers,
        private TicketMetadataWriter $metadata,
    ) {
    }

    public function create(int $project, array $data): int
    {
        $fields = $this->fields($data);

        return $this->access->write($project, 'write', function ($scope) use ($project, $data, $fields) {
            $metadata = $this->metadata->prepare($scope, $data, true);
            $board    = $this->board($project);
            $this->revision($board, $data);
            $column = $this->target(
                $project,
                (int) $board['id'],
                'board_columns',
                Input::id($data['column_id']),
            );
            $lane = $this->target(
                $project,
                (int) $board['id'],
                'swimlanes',
                Input::id($data['swimlane_id']),
            );
            $closed = (int) $column['closes_tickets'] === 1;
            $now    = gmdate('Y-m-d H:i:s');
            $ticket = new Ticket([
                ...$fields,
                'project_id'  => $project,
                'board_id'    => (int) $board['id'],
                'column_id'   => (int) $column['id'],
                'swimlane_id' => (int) $lane['id'],
                'number'      => (int) $board['next_number'],
                'status'      => $closed ? 'closed' : 'open',
                'created_by'  => $this->access->actor(),
                'created_at'  => $now,
                'updated_at'  => $now,
                'closed_at'   => $closed ? $now : null,
                'archived_at' => null,
                'position'    => $this->appendPosition(
                    $project,
                    (int) $column['id'],
                    (int) $lane['id'],
                ),
                'version' => 1,
            ]);
            $this->entityManager->save($ticket);
            $id = (int) $ticket->getId();
            $this->metadata->write($project, $id, $metadata);
            $this->pivots($project, $id, $data);
            $this->pdo
                ->prepare('UPDATE boards SET next_number=next_number+1 WHERE id=?')
                ->execute([$board['id']]);
            $this->changed($project, $id, 'ticket.created', [
                'title'    => $fields['title'],
                'number'   => (string) $board['next_number'],
                'metadata' => array_keys($metadata['values']),
            ]);

            return $id;
        });
    }

    public function update(int $project, int $id, array $data): void
    {
        $this->access->write($project, 'write', function ($scope) use ($project, $id, $data) {
            $row = $this->ticket($project, $id);
            // Partial updates retain all other attributes inside the existing project lock.
            $merged = array_replace($row, $data);
            if (array_key_exists('description', $data) && !array_key_exists('description_html', $data)) {
                $merged['description_html'] = null;
            }
            $fields = $this->fields($merged);
            $this->version($row, $data);
            $this->revision($this->board($project), $data);
            if ($row['archived_at'] !== null) {
                throw new Failure('Ein archiviertes Ticket kann nicht bearbeitet werden.');
            }
            // Metadata is validated before anything is written, so an invalid value
            // stops the whole change instead of leaving half a ticket behind.
            $metadata = $this->metadata->prepare($scope, $data, false);
            $changed  = [];
            foreach ($fields as $key => $value) {
                if ($row[$key] !== $value) {
                    $changed[] = $key;
                }
            }
            $ticket = new Ticket([
                ...$row,
                ...$fields,
                'version'    => (int) $row['version'] + 1,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->entityManager->save($ticket);
            $this->metadata->write($project, $id, $metadata);
            $this->pivots($project, $id, $data);
            $this->changed($project, $id, 'ticket.updated', [
                'fields'   => $changed,
                'metadata' => [...array_keys($metadata['values']), ...$metadata['resetKeys']],
            ]);
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
            $column = $this->target(
                $project,
                (int) $board['id'],
                'board_columns',
                Input::id($data['column_id']),
            );
            $lane = $this->target(
                $project,
                (int) $board['id'],
                'swimlanes',
                Input::id($data['swimlane_id']),
            );
            $statement = $this->pdo->prepare(
                <<<'SQL'
                SELECT id,
                       position
                FROM tickets
                WHERE project_id = ?
                    AND column_id = ?
                    AND swimlane_id = ?
                    AND id <> ?
                ORDER BY position,
                         id
                SQL,
            );
            $statement->execute([$project, $column['id'], $lane['id'], $id]);
            $rows  = $statement->fetchAll();
            $left  = empty($data['left_id']) ? null : Input::id($data['left_id']);
            $right = empty($data['right_id']) ? null : Input::id($data['right_id']);
            if (($data['placement'] ?? 'append') === 'append') {
                $left  = $rows ? (int) $rows[array_key_last($rows)]['id'] : null;
                $right = null;
            }
            $ids        = array_map(static fn($item) => (int) $item['id'], $rows);
            $leftIndex  = $left === null ? -1 : array_search($left, $ids, true);
            $rightIndex = $right === null ? count($rows) : array_search($right, $ids, true);
            if ($leftIndex === false || $rightIndex === false || $rightIndex !== $leftIndex + 1) {
                throw new Failure(
                    'Die Zielposition hat sich geändert. Bitte lade das Board neu.',
                    409,
                );
            }
            $position = $this->between($rows, $leftIndex, $rightIndex);
            if ($position === null) {
                // Include the moving card while rebalancing to avoid colliding with its old position.
                $this->rebalance($project, (int) $column['id'], (int) $lane['id']);
                $statement->execute([$project, $column['id'], $lane['id'], $id]);
                $rows     = $statement->fetchAll();
                $position = $this->between($rows, $leftIndex, $rightIndex);
            }
            if ($position === null) {
                throw new Failure('Keine freie Kartenposition verfügbar.', 409);
            }
            $now    = gmdate('Y-m-d H:i:s');
            $closed = (int) $column['closes_tickets'] === 1;
            $ticket = new Ticket([
                ...$row,
                'column_id'   => (int) $column['id'],
                'swimlane_id' => (int) $lane['id'],
                'position'    => $position,
                'status'      => $closed ? 'closed' : 'open',
                'closed_at'   => $closed ? $row['closed_at'] ?? $now : null,
                'version'     => (int) $row['version'] + 1,
                'updated_at'  => $now,
            ]);
            $this->entityManager->save($ticket);
            $this->changed($project, $id, 'ticket.moved', [
                'from'     => $row['column_id'],
                'to'       => (string) $column['id'],
                'column'   => $column['name'],
                'swimlane' => $lane['name'],
            ]);
        });
    }

    /**
     * Moves a ticket into another project, where it takes a new number and a new reference.
     *
     * What belongs to the ticket travels with it: its text, its comments, its attachments,
     * its history, and the people assigned to it who are members over there as well. What
     * belonged to the project it leaves stays behind, because it would mean nothing on the
     * other side — the labels, the links to its former neighbours, and the notifications
     * pointing at it. Clocks are stopped before the move, so the time already worked reaches
     * the ticket even though the runs themselves cannot come along.
     *
     * @return array{project:int, reference:string}
     */
    public function transfer(int $project, int $id, array $data): array
    {
        $target = Input::id($data['project_id'] ?? null, 'project_id');
        if ($target === $project) {
            throw new Failure('Das Ticket liegt bereits in diesem Projekt.');
        }
        $this->entityManager->begin();

        try {
            // Both projects are locked, lower id first, so two moves in opposite directions
            // wait for each other instead of deadlocking.
            $lock = $this->pdo->prepare('SELECT id FROM projects WHERE id=? FOR UPDATE');
            foreach ($project < $target ? [$project, $target] : [$target, $project] as $held) {
                $lock->execute([$held]);
            }
            $origin      = $this->access->project($project, 'write', true)->project;
            $destination = $this->access->project($target, 'write', true)->project;
            $row         = $this->ticket($project, $id);
            $this->version($row, $data);
            if ($row['archived_at'] !== null) {
                throw new Failure('Ein archiviertes Ticket kann nicht verschoben werden.');
            }
            $board  = $this->board($target);
            $column = $this->firstOf($target, (int) $board['id'], 'board_columns', 'position,id');
            $lane   = $this->firstOf($target, (int) $board['id'], 'swimlanes', 'is_default DESC,position,id');

            $this->timers->clear($project, $id);
            $this->detach($project, $id, $target);
            $threads = $this->unthread($project, $id);

            $now    = gmdate('Y-m-d H:i:s');
            $closes = (int) $column['closes_tickets'] === 1;
            $number = (int) $board['next_number'];
            // The version is raised in the statement rather than from the row read earlier,
            // because stopping the clocks may already have raised it once.
            $this->pdo->prepare(
                <<<'SQL'
                UPDATE tickets
                SET project_id = ?,
                    board_id = ?,
                    column_id = ?,
                    swimlane_id = ?,
                    number = ?,
                    position = ?,
                    status = ?,
                    closed_at = ?,
                    version = version + 1,
                    updated_at = ?
                WHERE project_id = ?
                    AND id = ?
                SQL,
            )->execute([
                $target,
                $board['id'],
                $column['id'],
                $lane['id'],
                $number,
                $this->appendPosition($target, (int) $column['id'], (int) $lane['id']),
                $closes ? 'closed' : 'open',
                $closes ? $row['closed_at'] ?? $now : null,
                $now,
                $project,
                $id,
            ]);
            $this->rethread($target, $threads);
            $this->pdo
                ->prepare('UPDATE boards SET next_number=next_number+1 WHERE project_id=?')
                ->execute([$target]);

            // The board it left is one card shorter; the arrival is recorded on the other.
            $this->pdo
                ->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')
                ->execute([$project]);
            $this->changed($target, $id, 'ticket.transferred', [
                'from'   => $origin['name'],
                'to'     => $destination['name'],
                'number' => (string) $number,
            ]);
            $this->entityManager->commit();

            return [
                'project'   => $target,
                'reference' => Format::ticket($destination['ticket_key'], $number),
            ];
        } catch (Throwable $exception) {
            $this->entityManager->rollback();
            throw $exception;
        }
    }

    public function state(int $project, int $id, array $data): void
    {
        $action = $data['action'] ?? '';
        if (!in_array($action, ['close', 'reopen', 'archive', 'restore'], true)) {
            throw new Failure('Ungültige Ticketaktion.');
        }
        $this->access->write($project, 'write', function () use ($project, $id, $data, $action) {
            $row = $this->ticket($project, $id);
            $this->version($row, $data);
            $now               = gmdate('Y-m-d H:i:s');
            $row['version']    = (int) $row['version'] + 1;
            $row['updated_at'] = $now;
            if ($action === 'archive' || $action === 'restore') {
                $row['archived_at'] = $action === 'archive' ? $now : null;
            } else {
                $row['status']    = $action === 'close' ? 'closed' : 'open';
                $row['closed_at'] = $action === 'close' ? $now : null;
            }
            $this->entityManager->save(new Ticket($row));
            $this->changed($project, $id, 'ticket.' . $action);
        });
    }

    public function link(int $project, int $id, array $data): void
    {
        $this->access->write($project, 'write', function () use ($project, $id, $data) {
            $row = $this->ticket($project, $id);
            $this->version($row, $data);
            if ($row['archived_at'] !== null) {
                throw new Failure('Ein archiviertes Ticket kann nicht bearbeitet werden.');
            }
            $number    = Input::id($data['number'] ?? null, 'number');
            $statement = $this->pdo->prepare('SELECT id FROM tickets WHERE project_id=? AND number=?');
            $statement->execute([$project, $number]);
            $related = (int) $statement->fetchColumn();
            if (!$related || $related === $id) {
                throw new Failure('Wähle ein anderes Ticket aus diesem Projekt.');
            }
            $pair   = [$project, min($id, $related), max($id, $related)];
            $remove = ($data['action'] ?? '') === 'delete';
            if ($remove) {
                $this->pdo->prepare('DELETE FROM ticket_links WHERE project_id=? AND ticket_id=? AND related_id=?')->execute($pair);
            } else {
                $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ticket_links WHERE project_id=? AND ticket_id=? AND related_id=?');
                $statement->execute($pair);
                if (!(int) $statement->fetchColumn()) {
                    $this->pdo->prepare('INSERT INTO ticket_links(project_id,ticket_id,related_id) VALUES(?,?,?)')->execute($pair);
                }
            }
            $this->pdo->prepare('UPDATE tickets SET version=version+1,updated_at=? WHERE project_id=? AND id IN (?,?)')
                ->execute([gmdate('Y-m-d H:i:s'), $project, $id, $related]);
            $this->changed($project, $id, $remove ? 'ticket.unlinked' : 'ticket.linked', ['number' => $number]);
        });
    }

    /**
     * Turns a reference like NAF-3 into the row id. A bare number is refused on purpose:
     * addresses used to carry the row id, and accepting those again would quietly open a
     * different ticket instead of failing.
     */
    public function resolve(int $project, string $reference): int
    {
        $statement = $this->pdo->prepare('SELECT ticket_key FROM projects WHERE id=?');
        $statement->execute([$project]);
        $key = $statement->fetchColumn();
        if ($key === false) {
            throw new Failure('Projekt nicht gefunden.', 404);
        }
        $expected = '/^' . preg_quote((string) $key, '/') . '-([1-9][0-9]{0,17})$/i';
        if (!preg_match($expected, trim($reference), $found)) {
            throw new Failure('Unbekannte Ticketnummer: ' . $reference, 404);
        }
        $statement = $this->pdo->prepare('SELECT id FROM tickets WHERE project_id=? AND number=?');
        $statement->execute([$project, (int) $found[1]]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new Failure('Ticket nicht gefunden.', 404);
        }

        return (int) $id;
    }

    /**
     * The visible reference of a stored ticket, for redirects after a change.
     */
    public function reference(int $project, int $id): string
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT p.ticket_key,
                   t.number
            FROM tickets t
            JOIN projects p ON p.id = t.project_id
            WHERE t.project_id = ?
                AND t.id = ?
            SQL,
        );
        $statement->execute([$project, $id]);
        $row = $statement->fetch();
        if (!$row) {
            throw new Failure('Ticket nicht gefunden.', 404);
        }

        return Format::ticket($row['ticket_key'], $row['number']);
    }

    public function ticket(int $project, int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tickets WHERE project_id=? AND id=?');
        $statement->execute([$project, $id]);
        $row = $statement->fetch();
        if (!$row) {
            throw new Failure('Ticket nicht gefunden.', 404);
        }

        return $row;
    }

    public function board(int $project): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM boards WHERE project_id=?');
        $statement->execute([$project]);

        return $statement->fetch() ?: throw new Failure('Board nicht gefunden.', 404);
    }

    private function fields(array $data): array
    {
        $validated = Input::validate($data, [
            'title'       => 'required|string|max:200',
            'description' => 'string|max:50000',
            'priority'    => 'required|string',
        ]);
        $validated['title'] = trim($validated['title']);
        if ($validated['title'] === '') {
            throw new Failure('Ein Titel wird benötigt.');
        }
        if (!in_array($validated['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
            throw new Failure('Ungültige Priorität.');
        }
        $validated['color'] = $this->projects->color($data['color'] ?? '#6366f1');
        $due                = $data['due_date'] ?? null;
        if ($due === '') {
            $due = null;
        }
        if ($due !== null) {
            Input::validate(['due_date' => $due], ['due_date' => 'date']);
        }
        $validated['due_date'] = $due;
        $start                 = $data['start_date'] ?? null;
        $start                 = $start === '' ? null : $start;
        foreach (['start_date' => $start, 'due_date' => $due] as $field => $date) {
            if ($date !== null && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                throw new Failure('Bitte wähle ein gültiges Datum.', 422, [$field => ['Ungültiges Datum.']]);
            }
        }
        if ($start !== null && $due !== null && $start > $due) {
            throw new Failure('Das Startdatum darf nicht nach dem Fälligkeitsdatum liegen.');
        }
        $validated['start_date'] = $start;
        foreach (['estimate_minutes' => null, 'spent_minutes' => 0] as $field => $default) {
            $value             = $data[$field] ?? $default;
            $value             = $value === '' ? $default : $value;
            $validated[$field] = Duration::parse($value, $field) ?? $default;
        }
        // Stored without regard for the project's scale: a value kept from an earlier scale
        // has to survive, and the interface is what offers the values a scale allows.
        $points = $data['estimate_points'] ?? null;
        $points = $points === '' ? null : $points;
        if ($points !== null && ((!is_string($points) && !is_int($points))
            || filter_var($points, FILTER_VALIDATE_INT) === false
            || (int) $points < 0 || (int) $points > Estimation::MAX)) {
            throw new Failure('Bitte gib eine Schätzung zwischen 0 und ' . Estimation::MAX . ' ein.', 422, [
                'estimate_points' => ['Ungültige Schätzung.'],
            ]);
        }
        $validated['estimate_points'] = $points === null ? null : (int) $points;

        $html = $data['description_html'] ?? null;
        if ($html !== null) {
            Input::validate(['description_html' => $html], ['description_html' => 'string|max:100000']);
            $html                     = RichText::clean($html);
            $validated['description'] = RichText::plain($html);
            Input::validate($validated, ['description' => 'string|max:50000']);
        }
        $validated['description_html'] = $html;
        $validated['description'] ??= '';

        return $validated;
    }

    private function pivots(int $project, int $ticket, array $data): void
    {
        foreach (
            [
                'label_ids'    => ['labels', 'ticket_labels', 'label_id'],
                'assignee_ids' => ['project_members', 'ticket_assignees', 'user_id'],
            ] as $field => [$source, $pivot, $key]
        ) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $ids = Input::ids($data[$field], $field);
            foreach ($ids as $id) {
                $column    = $source === 'labels' ? 'id' : 'user_id';
                $statement = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM $source WHERE project_id=? AND $column=?"
                        . ($source === 'project_members' ? ' AND active=1' : ''),
                );
                $statement->execute([$project, $id]);
                if ((int) $statement->fetchColumn() !== 1) {
                    throw new Failure('Die Auswahl gehört nicht zu diesem Projekt.', 422, [
                        $field => ['Ungültige Zuordnung.'],
                    ]);
                }
            }
            $this->pdo
                ->prepare("DELETE FROM $pivot WHERE project_id=? AND ticket_id=?")
                ->execute([$project, $ticket]);
            $statement = $this->pdo->prepare("INSERT INTO $pivot(project_id,ticket_id,$key) VALUES(?,?,?)");
            foreach ($ids as $id) {
                $statement->execute([$project, $ticket, $id]);
            }
        }
    }

    /** Where a ticket lands on a board it has never been on: the first column and lane. */
    private function firstOf(int $project, int $board, string $table, string $order): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM $table WHERE project_id=? AND board_id=? ORDER BY $order",
        );
        $statement->execute([$project, $board]);

        return $statement->fetch() ?: throw new Failure('Dem Zielprojekt fehlt ein Board.', 422);
    }

    /**
     * Everything that only meant something in the project the ticket is leaving. These rows
     * keep a plain foreign key on purpose, so that forgetting one of them stops the move
     * rather than dragging it somewhere it does not belong.
     */
    private function detach(int $project, int $id, int $target): void
    {
        $this->pdo
            ->prepare('DELETE FROM ticket_labels WHERE project_id=? AND ticket_id=?')
            ->execute([$project, $id]);

        // The neighbours stay behind, and their half of the link goes with the ticket, so
        // they are touched too and anyone looking at them is told to reload.
        $statement = $this->pdo->prepare(
            'SELECT CASE WHEN ticket_id=? THEN related_id ELSE ticket_id END'
            . ' FROM ticket_links WHERE project_id=? AND (ticket_id=? OR related_id=?)',
        );
        $statement->execute([$id, $project, $id, $id]);
        $neighbours = $statement->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo
            ->prepare('DELETE FROM ticket_links WHERE project_id=? AND (ticket_id=? OR related_id=?)')
            ->execute([$project, $id, $id]);
        $touch = $this->pdo->prepare(
            'UPDATE tickets SET version=version+1,updated_at=? WHERE project_id=? AND id=?',
        );
        foreach ($neighbours as $neighbour) {
            $touch->execute([gmdate('Y-m-d H:i:s'), $project, $neighbour]);
        }

        // A notification is a pointer held by someone in this project, and it cannot follow
        // the ticket out of it.
        $this->pdo->prepare(
            'DELETE FROM notification_deliveries WHERE notification_id IN'
            . ' (SELECT id FROM notifications WHERE project_id=? AND ticket_id=?)',
        )->execute([$project, $id]);
        $this->pdo
            ->prepare('DELETE FROM notifications WHERE project_id=? AND ticket_id=?')
            ->execute([$project, $id]);

        // Being assigned is work still to do, not a record of work done: it needs a
        // membership on the other side, and without one the assignment ends here.
        $this->pdo->prepare(
            'DELETE FROM ticket_assignees WHERE project_id=? AND ticket_id=? AND user_id NOT IN'
            . ' (SELECT user_id FROM project_members WHERE project_id=? AND active=1)',
        )->execute([$project, $id, $target]);
    }

    /**
     * A reply points at the comment above it through the ticket's project, and the database
     * checks that for each row as it changes rather than once the statement is done. So the
     * thread is taken apart before the move and put back together after it; the gap exists
     * only inside the transaction and nobody ever reads it.
     *
     * @return list<array{0:int,1:int}>
     */
    private function unthread(int $project, int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,parent_id FROM comments WHERE project_id=? AND ticket_id=? AND parent_id IS NOT NULL',
        );
        $statement->execute([$project, $id]);
        $threads = array_map(
            static fn(array $row) => [(int) $row['id'], (int) $row['parent_id']],
            $statement->fetchAll(),
        );
        $this->pdo->prepare(
            'UPDATE comments SET parent_id=NULL WHERE project_id=? AND ticket_id=? AND parent_id IS NOT NULL',
        )->execute([$project, $id]);

        return $threads;
    }

    /** @param list<array{0:int,1:int}> $threads */
    private function rethread(int $project, array $threads): void
    {
        $statement = $this->pdo->prepare('UPDATE comments SET parent_id=? WHERE project_id=? AND id=?');
        foreach ($threads as [$comment, $parent]) {
            $statement->execute([$parent, $project, $comment]);
        }
    }

    private function target(int $project, int $board, string $table, int $id): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM $table WHERE project_id=? AND board_id=? AND id=?");
        $statement->execute([$project, $board, $id]);

        return $statement->fetch() ?: throw new Failure('Das Ziel gehört nicht zu diesem Board.', 422);
    }

    private function version(array $row, array $data): void
    {
        if (Input::id($data['version'] ?? null, 'version') !== (int) $row['version']) {
            throw new Failure('Dieses Ticket wurde inzwischen geändert. Bitte lade es neu.', 409);
        }
    }

    private function revision(array $board, array $data): void
    {
        if (
            Input::id($data['board_revision'] ?? null, 'board_revision')
            !== (int) $board['revision']
        ) {
            throw new Failure('Das Board wurde inzwischen geändert. Bitte lade es neu.', 409);
        }
    }

    private function appendPosition(int $project, int $column, int $lane): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(position),0) FROM tickets WHERE project_id=? AND column_id=? AND swimlane_id=?',
        );
        $statement->execute([$project, $column, $lane]);
        $position = (int) $statement->fetchColumn();
        if ($position > PHP_INT_MAX - 1024) {
            $this->rebalance($project, $column, $lane);
            $statement->execute([$project, $column, $lane]);
            $position = (int) $statement->fetchColumn();
        }

        return $position + 1024;
    }

    private function between(array $rows, int $left, int $right): ?int
    {
        $lo = $left < 0 ? 0 : (int) $rows[$left]['position'];
        if ($right === count($rows)) {
            return $lo > PHP_INT_MAX - 1024 ? null : $lo + 1024;
        }
        $hi = (int) $rows[$right]['position'];

        return $hi - $lo > 1 ? $lo + intdiv($hi - $lo, 2) : null;
    }

    private function rebalance(int $project, int $column, int $lane): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM tickets WHERE project_id=? AND column_id=? AND swimlane_id=? ORDER BY position,id',
        );
        $statement->execute([$project, $column, $lane]);
        $ids    = $statement->fetchAll(PDO::FETCH_COLUMN);
        $update = $this->pdo->prepare('UPDATE tickets SET position=? WHERE project_id=? AND id=?');
        foreach ($ids as $i => $id) {
            $update->execute([-1024 * ($i + 1), $project, $id]);
        }
        foreach ($ids as $i => $id) {
            $update->execute([1024 * ($i + 1), $project, $id]);
        }
    }

    private function changed(int $project, int $ticket, string $type, array $data = []): void
    {
        $this->pdo
            ->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')
            ->execute([$project]);
        event()->dispatch(
            'nafinity.changed',
            new Change($project, $ticket, $this->access->actor(), $type, $data),
        );
    }
}
