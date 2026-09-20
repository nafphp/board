<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\TicketDetailTestCase;

/**
 * Every field in the detail view saves on its own, which means every one of them
 * is a way in. They are all checked the same way: a viewer is refused, a stranger
 * is not told the ticket exists, and an archived ticket takes no writes at all.
 */
final class TicketAccessTest extends TicketDetailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->projects->member($this->project, ['email' => 'viewer@example.test', 'role' => 'viewer']);
    }

    public function testAViewerMayReadTheTicketAndChangeNothingOnIt(): void
    {
        $version = $this->current();

        $this->actAs($this->viewer);

        $this->assertNotEmpty($this->query->detail($this->project, $this->ticket), 'a viewer cannot read');
        $this->assertDenied(403, fn() => $this->tickets->update($this->project, $this->ticket, ['title' => 'Forbidden'] + $version));
        $this->assertDenied(403, fn() => $this->tickets->link($this->project, $this->ticket, ['number' => 2] + $version));
        $this->assertDenied(403, fn() => $this->comments->save($this->project, $this->ticket, ['body' => 'Forbidden']));
    }

    public function testAStrangerIsNotToldTheTicketExists(): void
    {
        $version = $this->current();

        $this->actAs($this->bob);

        $this->assertDenied(404, fn() => $this->query->detail($this->project, $this->ticket));
        $this->assertDenied(404, fn() => $this->tickets->update($this->project, $this->ticket, ['title' => 'Foreign'] + $version));
    }

    public function testAnArchivedTicketTakesNoWrites(): void
    {
        $this->tickets->state($this->project, $this->ticket, ['action' => 'archive'] + $this->current());

        $this->assertDenied(422, fn() => $this->edit(['title' => 'Archived']));
        $this->assertDenied(422, fn() => $this->tickets->link(
            $this->project,
            $this->ticket,
            ['number' => 2] + $this->current(),
        ));
    }
}
