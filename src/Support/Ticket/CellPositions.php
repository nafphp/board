<?php

declare(strict_types=1);

namespace Naf\Board\Support\Ticket;

use PDO;

/**
 * Where a card sits in its cell -- one column in one swimlane.
 *
 * Positions are integers with room between them, so a card dropped between two
 * others takes the midpoint and nothing else moves. Only when two neighbours
 * sit on adjacent numbers, or a cell runs out at either end, is it spread out
 * again in bounded batches, through free temporary positions first so the
 * unique cell-position constraint holds during both passes.
 *
 * @internal
 */
final class CellPositions
{
    /** The room left between two cards when a cell is laid out. */
    public const int GAP = 1024;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * A free position between two neighbours, or null when there is none.
     *
     * A missing neighbour is an open end: above the first card, below the last,
     * or anywhere in an empty cell.
     */
    public static function between(?int $above, ?int $below): ?int
    {
        return match (true) {
            $above === null && $below === null => self::GAP,
            $above === null                    => $below > PHP_INT_MIN + self::GAP ? $below - self::GAP : null,
            $below === null                    => $above < PHP_INT_MAX - self::GAP ? $above + self::GAP : null,
            $below - $above > 1                => $above + intdiv($below - $above, 2),
            default                            => null,
        };
    }

    /** Above everything in the cell. */
    public function top(int $project, int $column, int $lane): int
    {
        return $this->free($project, $column, $lane, 'MIN', static fn(int $first) => self::between(null, $first));
    }

    /** Below everything in the cell. */
    public function bottom(int $project, int $column, int $lane): int
    {
        return $this->free($project, $column, $lane, 'MAX', static fn(int $last) => self::between($last, null));
    }

    /** Lay the cell out again with a full gap between neighbours, keeping their order. */
    public function rebalance(int $project, int $column, int $lane): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id,position FROM tickets WHERE project_id=? AND column_id=? AND swimlane_id=? ORDER BY position,id',
        );
        $statement->execute([$project, $column, $lane]);
        $rows     = $statement->fetchAll(PDO::FETCH_ASSOC);
        $occupied = [];
        $final    = [];
        foreach ($rows as $index => $row) {
            $occupied[(int) $row['position']] = true;
            $position                         = self::GAP * ($index + 1);
            $final[(int) $row['id']]          = $position;
            $occupied[$position]              = true;
        }

        // A top insertion can already have made positions negative. Temporary
        // positions must be absent from both the old and final sets, including
        // when a card sits at the signed integer boundary.
        $temporary = [];
        $position  = PHP_INT_MIN;
        foreach ($rows as $row) {
            while (isset($occupied[$position])) {
                $position++;
            }
            $temporary[(int) $row['id']] = $position;
            $occupied[$position]         = true;
            $position++;
        }

        $this->writePositions($project, $temporary);
        $this->writePositions($project, $final);
    }

    /** @param array<int,int> $positions Ticket IDs and their distinct new positions. */
    private function writePositions(int $project, array $positions): void
    {
        // Bound statement/parameter size while avoiding two round trips per card.
        // Both passes keep the unique cell-position constraint valid throughout.
        foreach (array_chunk($positions, 250, true) as $batch) {
            $cases      = implode(' ', array_fill(0, count($batch), 'WHEN ? THEN ?'));
            $holders    = implode(',', array_fill(0, count($batch), '?'));
            $parameters = [];
            foreach ($batch as $id => $position) {
                $parameters[] = $id;
                $parameters[] = $position;
            }
            $parameters[] = $project;
            array_push($parameters, ...array_keys($batch));
            $this->pdo->prepare(
                'UPDATE tickets SET position=CASE id ' . $cases . ' END WHERE project_id=? AND id IN (' . $holders . ')',
            )->execute($parameters);
        }
    }

    /**
     * @param 'MIN'|'MAX'             $edge
     * @param callable(int): ?int     $beyond the free position past that edge
     */
    private function free(int $project, int $column, int $lane, string $edge, callable $beyond): int
    {
        $statement = $this->pdo->prepare(
            "SELECT $edge(position) FROM tickets WHERE project_id=? AND column_id=? AND swimlane_id=?",
        );
        $statement->execute([$project, $column, $lane]);
        $current = $statement->fetchColumn();

        if ($current === null) {
            return self::GAP;
        }

        $position = $beyond((int) $current);
        if ($position === null) {
            $this->rebalance($project, $column, $lane);
            $statement->execute([$project, $column, $lane]);
            $position = $beyond((int) $statement->fetchColumn());
        }

        return (int) $position;
    }
}
