<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Change;
use App\Domain\Failure;
use PDO;

use function Naf\event;

/**
 * A person works on one thing at a time, so starting a timer settles whatever was running.
 * Elapsed time is derived from a stored start instant rather than counted in the browser:
 * a closed tab, a reload or a sleeping laptop can then neither lose nor invent minutes.
 *
 * Timer writes deliberately carry no ticket version. Every other write uses optimistic
 * locking, but someone who tracked an hour would hold a stale version by the time they
 * pause, and a conflict there would throw away real work. Accumulating minutes is an
 * addition, not a replacement, so it cannot conflict with anyone else's edit.
 */
final class TimerService
{
    private const ACTIONS = ['start', 'pause', 'stop'];

    public function __construct(
        private PDO $pdo,
        private Access $access,
    ) {
    }

    public function act(int $project, int $ticket, array $data): array
    {
        $action = $data['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            throw new Failure('Unbekannte Aktion für die Zeiterfassung.');
        }

        return $this->access->write($project, 'write', function () use ($project, $ticket, $action) {
            $statement = $this->pdo->prepare('SELECT archived_at FROM tickets WHERE project_id=? AND id=?');
            $statement->execute([$project, $ticket]);
            $row = $statement->fetch();
            if (!$row) {
                throw new Failure('Ticket nicht gefunden.', 404);
            }
            if ($row['archived_at'] !== null) {
                throw new Failure('Für ein archiviertes Ticket wird keine Zeit erfasst.');
            }
            $actor = (int) $this->access->actor();
            if ($action === 'start') {
                $this->settle($actor, 'paused');
                $this->begin($project, $ticket, $actor);
            } else {
                $minutes = $this->settle($actor, $action === 'stop' ? 'stopped' : 'paused', $project, $ticket);
                if ($minutes > 0) {
                    $this->changed($project, $ticket, 'timer.recorded', ['minutes' => (string) $minutes]);
                }
            }

            return $this->state($project, $ticket, $actor);
        });
    }

    /** What the ticket view and its buttons render for the person looking at it. */
    public function state(int $project, int $ticket, ?int $actor = null): array
    {
        $actor ??= (int) $this->access->actor();
        $statement = $this->pdo->prepare(
            'SELECT * FROM ticket_timers WHERE project_id=? AND ticket_id=? AND user_id=?',
        );
        $statement->execute([$project, $ticket, $actor]);
        $run       = $statement->fetch();
        $statement = $this->pdo->prepare('SELECT spent_minutes FROM tickets WHERE project_id=? AND id=?');
        $statement->execute([$project, $ticket]);

        return [
            'state'         => $run ? $run['state'] : 'stopped',
            'seconds'       => $run ? $this->elapsed($run) : 0,
            'spent_minutes' => (int) $statement->fetchColumn(),
        ];
    }

    /** The one run that is counting right now, for the marker that follows the person. */
    public function running(?int $actor = null): ?array
    {
        $actor ??= (int) $this->access->actor();
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT r.project_id,
                   r.ticket_id,
                   r.started_at,
                   r.carry_seconds,
                   r.tracked_seconds,
                   t.number,
                   t.title,
                   p.ticket_key
            FROM ticket_timers r
            JOIN tickets t ON t.project_id = r.project_id
                AND t.id = r.ticket_id
            JOIN projects p ON p.id = r.project_id
            WHERE r.user_id = ?
                AND r.state = 'running'
            ORDER BY r.started_at DESC
            SQL,
        );
        $statement->execute([$actor]);
        $run = $statement->fetch();
        if (!$run) {
            return null;
        }

        return [...$run, 'seconds' => $this->elapsed($run)];
    }

    /**
     * Ticket ids of this project the person is counting on, so the board can mark them.
     *
     * @return list<int>
     */
    public function runningIn(int $project, ?int $actor = null): array
    {
        $actor ??= (int) $this->access->actor();
        $statement = $this->pdo->prepare(
            "SELECT ticket_id FROM ticket_timers WHERE project_id=? AND user_id=? AND state='running'",
        );
        $statement->execute([$project, $actor]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function begin(int $project, int $ticket, int $actor): void
    {
        $now       = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'SELECT state FROM ticket_timers WHERE project_id=? AND ticket_id=? AND user_id=?',
        );
        $statement->execute([$project, $ticket, $actor]);
        if ($statement->fetch() === false) {
            $this->pdo
                ->prepare(
                    'INSERT INTO ticket_timers(project_id,ticket_id,user_id,started_at,carry_seconds,state,updated_at)'
                    . " VALUES(?,?,?,?,0,'running',?)",
                )
                ->execute([$project, $ticket, $actor, $now, $now]);

            return;
        }
        $this->pdo
            ->prepare(
                "UPDATE ticket_timers SET started_at=?,state='running',updated_at=?"
                . ' WHERE project_id=? AND ticket_id=? AND user_id=?',
            )
            ->execute([$now, $now, $project, $ticket, $actor]);
    }

    /**
     * Folds counted time into the ticket and leaves the run in the given state. Without a
     * ticket it settles whatever else the person had running, which is how one timer per
     * person stays true across projects.
     */
    private function settle(int $actor, string $state, ?int $project = null, ?int $ticket = null): int
    {
        $where  = 'user_id=?';
        $params = [$actor];
        if ($project !== null) {
            $where .= ' AND project_id=? AND ticket_id=?';
            $params = [$actor, $project, $ticket];
        } else {
            $where .= " AND state='running'";
        }
        $statement = $this->pdo->prepare("SELECT * FROM ticket_timers WHERE $where");
        $statement->execute($params);
        $runs = $statement->fetchAll();
        if (!$runs) {
            return 0;
        }
        $now    = gmdate('Y-m-d H:i:s');
        $update = $this->pdo->prepare(
            'UPDATE ticket_timers SET started_at=NULL,carry_seconds=?,tracked_seconds=?,state=?,updated_at=?'
            . ' WHERE project_id=? AND ticket_id=? AND user_id=?',
        );
        $spend = $this->pdo->prepare(
            'UPDATE tickets SET spent_minutes=spent_minutes+?,version=version+1,updated_at=?'
            . ' WHERE project_id=? AND id=?',
        );
        $recorded = 0;

        foreach ($runs as $run) {
            $live    = $this->live($run);
            $pending = (int) $run['carry_seconds'] + $live;
            $minutes = intdiv($pending, 60);
            // Whole minutes go to the ticket; the seconds below one stay here and count
            // towards the next minute, so repeated short bursts are not rounded away. What
            // the clock shows is a separate, only ever growing number — an elapsed time that
            // drops when you pause reads as a broken timer, however correct the bookkeeping.
            $tracked = $state === 'stopped' ? 0 : (int) $run['tracked_seconds'] + $live;
            $update->execute([
                $pending % 60, $tracked, $state, $now,
                $run['project_id'], $run['ticket_id'], $run['user_id'],
            ]);
            if ($minutes > 0) {
                $spend->execute([$minutes, $now, $run['project_id'], $run['ticket_id']]);
                $recorded += $minutes;
            }
        }

        return $recorded;
    }

    /** What the clock shows: everything tracked here so far, plus the run in progress. */
    private function elapsed(array $run): int
    {
        return (int) $run['tracked_seconds'] + $this->live($run);
    }

    private function live(array $run): int
    {
        if ($run['started_at'] === null) {
            return 0;
        }

        return max(0, time() - (int) strtotime($run['started_at'] . ' UTC'));
    }

    private function changed(int $project, int $ticket, string $type, array $data): void
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
