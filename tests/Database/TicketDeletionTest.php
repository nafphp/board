<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;
use PDO;

/**
 * Removing a ticket for good.
 *
 * Two promises pull against each other here. Everything that only existed
 * because of the ticket has to go, or the next migration trips over a row
 * nobody can reach. And the history has to stay, because an entry saying what
 * happened is not part of the thing it happened to.
 *
 * The first is checked by asking the database which tables point at tickets
 * rather than by listing them here. A list in a test is a list somebody forgets
 * to extend, and then a new table quietly survives every deletion.
 */
final class TicketDeletionTest extends BoardTestCase
{
    public function testNothingThatPointedAtItIsLeftBehind(): void
    {
        $ticket = $this->furnishedTicket();
        $this->tickets->delete($this->projectA, $ticket, $this->stateOf($ticket));

        $tables = $this->tablesPointingAtTickets();
        $this->assertNotEmpty($tables, 'the schema was asked and answered nothing');

        foreach ($tables as $table) {
            // The history is the deliberate exception and is checked below.
            if ($table === 'activities') {
                continue;
            }

            $this->assertSame(
                0,
                (int) $this->scalar("SELECT COUNT(*) FROM $table WHERE ticket_id=?", [$ticket]),
                "$table still holds something of a deleted ticket",
            );
        }

        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tickets WHERE id=?', [$ticket]));
    }

    public function testTheHistoryStaysAndSaysWhatWasDeleted(): void
    {
        $ticket = $this->furnishedTicket();
        $before = (int) $this->scalar('SELECT COUNT(*) FROM activities WHERE ticket_id=?', [$ticket]);
        $this->assertGreaterThan(0, $before, 'the ticket had no history to keep');

        $this->tickets->delete($this->projectA, $ticket, $this->stateOf($ticket));

        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM activities WHERE ticket_id=?', [$ticket]),
            'an entry still points at a ticket that is gone',
        );

        $entry = $this->fetchOne(
            "SELECT payload FROM activities WHERE event_type='ticket.deleted' ORDER BY id DESC",
        );
        $this->assertNotNull($entry, 'nothing recorded that it was deleted');

        // Named, because the link beside it is gone with the row it pointed at.
        $payload = json_decode((string) $entry['payload'], true);
        $this->assertNotSame('', (string) $payload['title']);
        $this->assertNotSame('', (string) $payload['key']);
    }

    /** Somebody who may write tickets may not therefore destroy them. */
    public function testItNeedsTheRightOfItsOwn(): void
    {
        $ticket = $this->furnishedTicket();
        // A member may write tickets and holds nothing else by default.
        $this->projects->member($this->projectA, ['email' => 'member@example.test', 'role' => 'member']);
        $this->actAs($this->member);

        $this->assertDenied(
            403,
            fn() => $this->tickets->delete($this->projectA, $ticket, $this->stateOf($ticket)),
        );
    }

    /**
     * What a delete has to carry: the version it was composed against, so a page
     * that has not seen the last change cannot destroy what it is looking at.
     *
     * @return array<string,mixed>
     */
    private function stateOf(int $ticket): array
    {
        return [
            'version' => $this->scalar('SELECT version FROM tickets WHERE id=?', [$ticket]),
            ...$this->revision($this->projectA),
        ];
    }

    /** A ticket with something hanging off every hook there is. */
    private function furnishedTicket(): int
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->comments->save($this->projectA, $ticket, ['body' => 'Ein Wort dazu']);
        $this->tickets->update($this->projectA, $ticket, [
            'title'        => 'Mit allem dran',
            'assignee_ids' => [$this->alice->getId()],
            ...$this->revision($this->projectA),
            'version' => $this->scalar('SELECT version FROM tickets WHERE id=?', [$ticket]),
        ]);

        return $ticket;
    }

    /**
     * @return list<string> every table with a foreign key into tickets
     */
    private function tablesPointingAtTickets(): array
    {
        $postgres = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $sql      = $postgres
            ? "SELECT DISTINCT c.relname FROM pg_constraint k
                 JOIN pg_class c ON c.oid = k.conrelid
                WHERE k.contype='f' AND k.confrelid='tickets'::regclass"
            : "SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='tickets'";

        return array_map(strval(...), $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }
}
