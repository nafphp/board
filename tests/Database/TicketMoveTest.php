<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;
use PDO;

/**
 * Moving a ticket: the optimistic lock, and the order tickets keep in a column.
 *
 * Positions are dense integers rather than a linked list, so inserting between
 * two neighbours has to rebalance without ever letting two tickets claim one
 * place. The neighbours a caller names are checked, because "between these two"
 * is only meaningful if those two really are adjacent and really are here.
 */
final class TicketMoveTest extends BoardTestCase
{
    public function testAMoveIsPersistedAndRepeatingItWithTheOldVersionConflicts(): void
    {
        $ticket    = $this->tickets->create($this->projectA, $this->ticketData());
        $before    = (int) $this->scalar('SELECT COUNT(*) FROM activities');
        $secondary = (int) $this->boardA['columns'][1]['id'];
        $move      = ['version' => 1, 'column_id' => $secondary, 'swimlane_id' => $this->laneA]
            + $this->revision();

        $this->tickets->move($this->projectA, $ticket, $move);

        $this->assertSame(
            $secondary,
            (int) $this->tickets->ticket($this->projectA, $ticket)['column_id'],
        );

        $this->assertDenied(409, fn() => $this->tickets->move($this->projectA, $ticket, $move));

        $this->assertSame(
            $before + 1,
            (int) $this->scalar('SELECT COUNT(*) FROM activities'),
            'the refused second move was recorded as if it had happened',
        );
    }

    public function testInsertingBetweenTwoNeighboursKeepsTheOrderUnique(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $first  = $this->tickets->create($this->projectA, $this->ticketData());
        $second = $this->tickets->create($this->projectA, $this->ticketData());

        // Dense neighbours: there is no gap left to place anything between them,
        // which is the case the rebalance exists for.
        $this->pdo->prepare('UPDATE tickets SET position=1 WHERE id=?')->execute([$first]);
        $this->pdo->prepare('UPDATE tickets SET position=2 WHERE id=?')->execute([$second]);

        $this->tickets->move($this->projectA, $ticket, [
            'version'     => 1,
            'column_id'   => $this->columnA,
            'swimlane_id' => $this->laneA,
            'placement'   => 'between',
            'left_id'     => $first,
            'right_id'    => $second,
        ] + $this->revision());

        $statement = $this->pdo->prepare('SELECT id FROM tickets WHERE project_id=? ORDER BY position');
        $statement->execute([$this->projectA]);

        $this->assertSame(
            [$first, $ticket, $second],
            array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)),
        );
    }

    public function testDenseNegativePositionsRebalanceWithoutCollidingWithTheMovingCard(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $first  = $this->tickets->create($this->projectA, $this->ticketData());
        $second = $this->tickets->create($this->projectA, $this->ticketData());
        $set    = $this->pdo->prepare('UPDATE tickets SET position=? WHERE id=?');
        $set->execute([-1024, $ticket]);
        $set->execute([-4096, $first]);
        $set->execute([-4095, $second]);

        $this->tickets->move($this->projectA, $ticket, [
            'version'     => 1,
            'column_id'   => $this->columnA,
            'swimlane_id' => $this->laneA,
            'placement'   => 'between',
            'left_id'     => $first,
            'right_id'    => $second,
        ] + $this->revision());

        $query = $this->pdo->prepare('SELECT id FROM tickets WHERE project_id=? ORDER BY position');
        $query->execute([$this->projectA]);
        $this->assertSame([$first, $ticket, $second], array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testDensePositionsAtTheSignedIntegerBoundaryRebalanceWithoutOverflow(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $first  = $this->tickets->create($this->projectA, $this->ticketData());
        $second = $this->tickets->create($this->projectA, $this->ticketData());
        $set    = $this->pdo->prepare('UPDATE tickets SET position=? WHERE id=?');
        $set->execute([PHP_INT_MIN, $first]);
        $set->execute([PHP_INT_MIN + 1, $second]);

        $this->tickets->move($this->projectA, $ticket, [
            'version'     => 1,
            'column_id'   => $this->columnA,
            'swimlane_id' => $this->laneA,
            'placement'   => 'between',
            'left_id'     => $first,
            'right_id'    => $second,
        ] + $this->revision());

        $query = $this->pdo->prepare('SELECT id,position FROM tickets WHERE project_id=? ORDER BY position');
        $query->execute([$this->projectA]);
        $rows = $query->fetchAll();
        $this->assertSame([$first, $ticket, $second], array_map(static fn($row) => (int) $row['id'], $rows));
        $this->assertGreaterThan(0, (int) $rows[0]['position']);
        $this->assertCount(3, array_unique(array_column($rows, 'position')));
    }

    public function testNeighboursGivenInTheWrongOrderAreRefused(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $first  = $this->tickets->create($this->projectA, $this->ticketData());
        $second = $this->tickets->create($this->projectA, $this->ticketData());
        $this->pdo->prepare('UPDATE tickets SET position=1 WHERE id=?')->execute([$first]);
        $this->pdo->prepare('UPDATE tickets SET position=2 WHERE id=?')->execute([$second]);

        $this->assertDenied(409, fn() => $this->tickets->move($this->projectA, $ticket, [
            'version'     => 1,
            'column_id'   => $this->columnA,
            'swimlane_id' => $this->laneA,
            'placement'   => 'between',
            'left_id'     => $second,
            'right_id'    => $first,
        ] + $this->revision()));
    }
}
