<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;

/**
 * Columns and swimlanes can be removed, but not when that would strand tickets
 * or leave a board with nowhere to put one.
 */
final class ProjectStructureTest extends BoardTestCase
{
    public function testAColumnThatStillHoldsTicketsCannotBeRemoved(): void
    {
        $this->tickets->create($this->projectA, $this->ticketData());

        $this->assertDenied(422, fn() => $this->projects->structure($this->projectA, [
            'kind'   => 'column',
            'id'     => $this->columnA,
            'action' => 'delete',
        ]));
    }

    public function testTheLastSwimlaneCannotBeRemoved(): void
    {
        $this->assertDenied(422, fn() => $this->projects->structure($this->projectA, [
            'kind'   => 'swimlane',
            'id'     => $this->laneA,
            'action' => 'delete',
        ]));
    }
}
