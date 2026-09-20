<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;

/**
 * An archived project stays readable and stops accepting changes. Archiving is
 * not deleting, so it has to be reversible.
 */
final class ProjectArchiveTest extends BoardTestCase
{
    public function testArchivingClosesTheProjectForWritesAndCanBeUndone(): void
    {
        $this->projects->archive($this->projectA, true);

        $this->assertNotNull(
            $this->query->board($this->projectA)['project']['archived_at'],
            'the project does not report itself as archived',
        );
        $this->assertDenied(
            403,
            fn() => $this->tickets->create($this->projectA, $this->ticketData()),
        );

        $this->projects->archive($this->projectA, false);

        $this->assertNull(
            $this->query->board($this->projectA)['project']['archived_at'],
            'the project stayed archived after being restored',
        );
    }
}
