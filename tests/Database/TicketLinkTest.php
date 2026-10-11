<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Support\TicketRelations;
use Naf\Board\Tests\Support\TicketDetailTestCase;

/**
 * Linking two tickets.
 *
 * A link is one relationship, not two: it is visible from both ends, adding it
 * twice does not make two, and removing it removes it for both. Tickets are
 * named by their number within the project, so a link can only ever reach
 * something on the same board.
 */
final class TicketLinkTest extends TicketDetailTestCase
{
    public function testALinkIsVisibleFromBothEndsAndOnlyOnce(): void
    {
        $number = $this->tickets->ticket($this->project, $this->other)['number'];

        $this->link($number);
        $this->link($number);

        $this->assertCount(
            1,
            $this->query->detail($this->project, $this->ticket)['linked_tickets'],
            'linking twice made two links',
        );
        $this->assertSame(
            $this->ticket,
            (int) $this->query->detail($this->project, $this->other)['linked_tickets'][0]['id'],
            'the link is not visible from the other end',
        );
    }

    public function testRemovingALinkRemovesItForBoth(): void
    {
        $number = $this->tickets->ticket($this->project, $this->other)['number'];
        $this->link($number);

        $this->tickets->link($this->project, $this->ticket, [
            'number' => $number,
            'action' => 'delete',
        ] + $this->current());

        $this->assertSame(
            [],
            $this->query->detail($this->project, $this->other)['linked_tickets'],
            'the link survived at the other end',
        );
    }

    public function testATicketThatIsNotOnThisBoardCannotBeLinked(): void
    {
        $this->assertDenied(422, fn() => $this->link(999999));
    }

    public function testATicketCannotBeLinkedToItself(): void
    {
        $own = $this->tickets->ticket($this->project, $this->ticket)['number'];

        $this->assertDenied(422, fn() => $this->link($own));
    }

    public function testLinkingCarriesTheSameVersionAsAnyOtherWrite(): void
    {
        $number = $this->tickets->ticket($this->project, $this->other)['number'];
        // A write of its own, so version 1 is genuinely behind rather than current.
        $this->edit(['title' => 'Moved on']);

        $this->assertDenied(409, fn() => $this->tickets->link($this->project, $this->ticket, [
            'number'  => $number,
            'version' => 1,
        ]));
    }

    public function testEveryDirectedRelationshipReadsCorrectlyFromEitherEnd(): void
    {
        foreach (TicketRelations::options() as $value => $label) {
            foreach ([$this->ticket, $this->other] as $source) {
                $target = $source === $this->ticket ? $this->other : $this->ticket;
                $this->tickets->link($this->project, $source, [
                    'number'   => $this->tickets->ticket($this->project, $target)['number'],
                    'relation' => $value,
                ] + $this->current($source));
                [$type, $inverse] = TicketRelations::parse($value);
                $outgoing         = $this->query->detail($this->project, $source)['linked_tickets'];
                $incoming         = $this->query->detail($this->project, $target)['linked_tickets'];
                $this->assertCount(1, $outgoing);
                $this->assertSame($label, TicketRelations::label($outgoing[0]['relation'], (bool) $outgoing[0]['inverse']));
                $this->assertSame(TicketRelations::label($type, !$inverse), TicketRelations::label($incoming[0]['relation'], (bool) $incoming[0]['inverse']));
            }
        }
    }

    public function testInvalidRelationshipsLeaveBothTicketsAndTheLinkUntouched(): void
    {
        $number = $this->tickets->ticket($this->project, $this->other)['number'];
        $this->link($number);
        $version      = $this->current();
        $otherVersion = $this->current($this->other);
        foreach (['unknown', '', ['clones'], 'clones_by_by'] as $relation) {
            $this->assertDenied(422, fn() => $this->tickets->link($this->project, $this->ticket, [
                'number' => $number, 'relation' => $relation,
            ] + $version));
        }
        $this->assertSame($version, $this->current());
        $this->assertSame($otherVersion, $this->current($this->other));
        $this->assertSame('relates', $this->query->detail($this->project, $this->ticket)['linked_tickets'][0]['relation']);
    }

    public function testLegacyPairsDefaultToASymmetricRelationship(): void
    {
        $this->pdo->prepare('INSERT INTO ticket_links(project_id,ticket_id,related_id) VALUES(?,?,?)')
            ->execute([$this->project, min($this->ticket, $this->other), max($this->ticket, $this->other)]);
        foreach ([$this->ticket, $this->other] as $ticket) {
            $row = $this->query->detail($this->project, $ticket)['linked_tickets'][0];
            $this->assertSame(TicketRelations::label('relates', false), TicketRelations::label($row['relation'], (bool) $row['inverse']));
        }
    }

    private function link(int $number): void
    {
        $this->tickets->link($this->project, $this->ticket, ['number' => $number] + $this->current());
    }
}
