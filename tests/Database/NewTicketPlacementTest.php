<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Domain\Placement;
use Naf\Board\Tests\Support\BoardTestCase;

/**
 * Where a new ticket lands in its column.
 *
 * Two settings answer one question, so what these tests hold is which of them
 * wins: a person decides for the tickets they create, a board decides for
 * everybody who has not, and a person who has said nothing is not overruling
 * anything.
 *
 * A ticket has one position, decided once, when it is made -- so this is asked
 * of whoever is creating it and never of whoever is looking at it.
 */
final class NewTicketPlacementTest extends BoardTestCase
{
    public function testWithoutASettingItGoesToTheBottomAsItAlwaysHas(): void
    {
        $first  = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Erstes']));
        $second = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Zweites']));

        $this->assertGreaterThan($this->positionOf($first), $this->positionOf($second));
    }

    public function testABoardCanPutThemOnTopInstead(): void
    {
        $below = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Schon da']));
        $this->boardPuts(Placement::TOP);
        $above = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Neu']));

        $this->assertLessThan($this->positionOf($below), $this->positionOf($above));
    }

    /** Somebody who wants their own tickets on top gets them there on any board. */
    public function testAPersonOverridesTheBoard(): void
    {
        $below = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Schon da']));
        $this->personWants(Placement::TOP);
        $above = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Neu']));

        $this->assertLessThan($this->positionOf($below), $this->positionOf($above));
    }

    /** And the other way round: the board says top, this person would rather not. */
    public function testAPersonCanDeclineWhatTheBoardPrefers(): void
    {
        $this->boardPuts(Placement::TOP);
        $this->personWants(Placement::BOTTOM);
        $first  = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Erstes']));
        $second = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Zweites']));

        $this->assertGreaterThan($this->positionOf($first), $this->positionOf($second));
    }

    /** Saying nothing is not the same as saying "bottom". */
    public function testSayingNothingLeavesItToTheBoard(): void
    {
        $this->boardPuts(Placement::TOP);
        $this->personWants(Placement::INHERIT);
        $below = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Schon da']));
        $above = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Neu']));

        $this->assertLessThan($this->positionOf($below), $this->positionOf($above));
    }

    /** Two cards may never share a position; the unique index would refuse the row. */
    public function testEveryTicketOnTopKeepsAPlaceOfItsOwn(): void
    {
        $this->boardPuts(Placement::TOP);
        $positions = [];
        foreach (['Eins', 'Zwei', 'Drei'] as $title) {
            $positions[] = $this->positionOf(
                $this->tickets->create($this->projectA, $this->ticketData(['title' => $title])),
            );
        }

        $this->assertSame($positions, array_values(array_unique($positions)), 'two cards share a place');
        $this->assertGreaterThan($positions[1], $positions[0], 'the second did not go above the first');
        $this->assertGreaterThan($positions[2], $positions[1], 'the third did not go above the second');
    }

    private function positionOf(int $ticket): int
    {
        return (int) $this->scalar('SELECT position FROM tickets WHERE id=?', [$ticket]);
    }

    private function boardPuts(string $where): void
    {
        $this->pdo->prepare('UPDATE projects SET new_tickets=? WHERE id=?')
            ->execute([$where, $this->projectA]);
    }

    // Written straight rather than through the service, which would need a whole
    // valid form; and deleted first, because an upsert is spelled differently on
    // each engine this suite runs against.
    private function personWants(string $where): void
    {
        $this->pdo->prepare('DELETE FROM user_preferences WHERE user_id=?')
            ->execute([$this->alice->getId()]);
        $this->pdo->prepare('INSERT INTO user_preferences(user_id,new_tickets) VALUES(?,?)')
            ->execute([$this->alice->getId(), $where]);
    }
}
