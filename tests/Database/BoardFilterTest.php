<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;

/**
 * Searching and filtering a board.
 *
 * Search is the database's own fulltext search rather than a LIKE, and filters
 * combine by narrowing. Both stay inside the project: a label belonging to
 * someone else's board matches nothing here instead of reaching across.
 *
 * Not transactional, and it has to be one test rather than three. InnoDB updates
 * its fulltext index when a transaction commits, so a search inside one finds
 * nothing at all -- and a test that cannot tell "the filter excluded it" from
 * "the search found nothing either way" is not testing the filter. So the rows
 * are committed, and the count that must be found comes first as the control for
 * the two that must not.
 */
final class BoardFilterTest extends BoardTestCase
{
    protected bool $transactional = false;

    public function testSearchAndFiltersNarrowAndStayInsideTheProject(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->tickets->create($this->projectA, $this->ticketData());
        }

        $this->assertSame(
            3,
            $this->query->board($this->projectA, ['q' => 'sunflower'])['total'],
            'the fulltext search did not find the tickets it was given',
        );
        $this->assertSame(
            0,
            $this->query->board($this->projectA, ['label' => $this->labelB])['total'],
            'a label belonging to another project matched here',
        );
        $this->assertSame(
            0,
            $this->query->board($this->projectA, ['q' => 'sunflower', 'priority' => 'urgent'])['total'],
            'the priority filter was ignored once a search term was given',
        );
    }
}
