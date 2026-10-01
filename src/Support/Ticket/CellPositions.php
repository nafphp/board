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
 * again: one statement per card, rare enough to be worth the simplicity.
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
            'SELECT id FROM tickets WHERE project_id=? AND column_id=? AND swimlane_id=? ORDER BY position,id',
        );
        $statement->execute([$project, $column, $lane]);
        $ids    = $statement->fetchAll(PDO::FETCH_COLUMN);
        $update = $this->pdo->prepare('UPDATE tickets SET position=? WHERE project_id=? AND id=?');

        // Through negative numbers first: no two cards of a cell may share a
        // position even for a moment, and the new positions may be old ones.
        foreach ($ids as $i => $id) {
            $update->execute([-self::GAP * ($i + 1), $project, $id]);
        }
        foreach ($ids as $i => $id) {
            $update->execute([self::GAP * ($i + 1), $project, $id]);
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
