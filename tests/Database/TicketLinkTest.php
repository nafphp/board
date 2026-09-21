<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

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

    private function link(int $number): void
    {
        $this->tickets->link($this->project, $this->ticket, ['number' => $number] + $this->current());
    }
}
