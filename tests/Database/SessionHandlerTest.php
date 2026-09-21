<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\DatabaseTestCase;
use Naf\Session\Storage\DatabaseSessionHandler;

/**
 * Sessions live in the same database as everything else, so an installation has
 * one thing to back up and a second server needs no shared filesystem.
 */
final class SessionHandlerTest extends DatabaseTestCase
{
    public function testASessionIsWrittenOverwrittenReadAndDestroyed(): void
    {
        $session = new DatabaseSessionHandler($this->pdo, 'sessions');

        $this->assertTrue($session->write('regression-session', 'first'));
        $this->assertTrue($session->write('regression-session', 'second'), 'the second write did not upsert');
        $this->assertSame('second', $session->read('regression-session'));

        $this->assertTrue($session->destroy('regression-session'));
        $this->assertSame('', $session->read('regression-session'), 'the session outlived its destruction');
    }
}
