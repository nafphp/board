<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;

/**
 * A service writes inside whatever unit of work its caller has opened, and never
 * ends it. If it committed, a caller that wraps several calls in one transaction
 * would find the first of them already durable when the third one failed.
 *
 * The caller here is the test itself: every test in this suite already runs in a
 * transaction, so the second half uses a savepoint to have something it can undo
 * without ending the transaction it is demonstrating.
 */
final class TransactionOwnershipTest extends BoardTestCase
{
    public function testCreatingATicketLeavesTheCallersTransactionOpen(): void
    {
        $this->assertTrue($this->pdo->inTransaction(), 'this test is meant to own a transaction');
        $before = (int) $this->scalar('SELECT COUNT(*) FROM tickets');

        $this->tickets->create($this->projectA, $this->ticketData());

        $this->assertTrue(
            $this->pdo->inTransaction(),
            'the service committed a transaction it did not open',
        );
        $this->assertSame($before + 1, (int) $this->scalar('SELECT COUNT(*) FROM tickets'));
    }

    public function testWhatTheCallerUndoesTakesTheServicesWorkWithIt(): void
    {
        $before = (int) $this->scalar('SELECT COUNT(*) FROM tickets');

        $this->pdo->exec('SAVEPOINT caller_work');
        $this->tickets->create($this->projectA, $this->ticketData());
        $this->pdo->exec('ROLLBACK TO SAVEPOINT caller_work');

        $this->assertSame(
            $before,
            (int) $this->scalar('SELECT COUNT(*) FROM tickets'),
            'the ticket survived the rollback, so the service had committed it',
        );
    }
}
