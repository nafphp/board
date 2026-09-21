<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Domain\Change;
use Naf\Board\Services\AuditLog;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\Rbac\Events\GrantsChanged;

use function Naf\event;

/**
 * The history as a log of the whole installation.
 *
 * Three promises, and each one is a schema promise rather than a matter of
 * careful calling: every entry names the scope it happened in, an entry can
 * belong to no project at all, and an account can be deleted without taking what
 * it did with it.
 */
final class AuditLogTest extends BoardTestCase
{
    public function testAChangeInAProjectIsScopedToThatBoard(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());

        $this->assertSame(
            'project:' . $this->projectA,
            $this->scalar('SELECT scope FROM activities WHERE ticket_id=? ORDER BY id DESC', [$ticket]),
        );
    }

    /** Settings, roles and accounts happen outside every board. */
    public function testAChangeToTheInstallationBelongsToNoProject(): void
    {
        $change = Change::inInstallation((int) $this->alice->getId(), 'settings.changed', ['key' => 'mail']);

        $this->assertSame('', $change->scope);
        $this->assertNull($change->projectId);
        $this->assertNull($change->ticketId);
    }

    /**
     * What an account did outlives the account. The entry stays and says so; the
     * database sees to it rather than every delete path remembering to.
     */
    public function testDeletingAnAccountLeavesItsEntriesWithNobodyNamed(): void
    {
        $stranger = $this->insertUser('geht-wieder@example.test');
        $entry    = $this->insertEntry($stranger);

        $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$stranger]);

        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM activities WHERE id=?', [$entry]),
            'the history was deleted along with the account',
        );
        $this->assertNull($this->scalar('SELECT actor_id FROM activities WHERE id=?', [$entry]));
    }

    /**
     * The editor saves by itself while somebody types, so one edit arrives here
     * several times. Keeping each is not a fuller record, it is a record nobody
     * reads.
     */
    public function testAnEditThatContinuesTheLastOneIsFoldedIntoIt(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $before = $this->entriesFor($ticket, 'ticket.updated');

        foreach (['Erst so', 'Dann so', 'Und so'] as $title) {
            $this->tickets->update($this->projectA, $ticket, [
                'title' => $title,
                ...$this->revision($this->projectA),
                'version' => $this->scalar('SELECT version FROM tickets WHERE id=?', [$ticket]),
            ]);
        }

        $this->assertSame($before + 1, $this->entriesFor($ticket, 'ticket.updated'));
    }

    /** What was folded still names everything the burst touched. */
    public function testTheFoldedEntryNamesEveryFieldOfTheBurst(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        foreach ([['title' => 'Anders'], ['priority' => 'high']] as $change) {
            $this->tickets->update($this->projectA, $ticket, [
                ...$change,
                ...$this->revision($this->projectA),
                'version' => $this->scalar('SELECT version FROM tickets WHERE id=?', [$ticket]),
            ]);
        }

        $payload = json_decode((string) $this->scalar(
            "SELECT payload FROM activities WHERE ticket_id=? AND event_type='ticket.updated' ORDER BY id DESC",
            [$ticket],
        ), true);

        $this->assertContains('title', $payload['fields']);
        $this->assertContains('priority', $payload['fields']);
    }

    /**
     * naf/rbac reports what moved; this is the half that decides it is worth
     * keeping. In a ticket system, who was given which rights where is the entry
     * people come looking for long after everything else is forgotten.
     */
    public function testAChangeOfSomebodysRolesIsRecordedWhereItHappened(): void
    {
        event()->dispatch(new GrantsChanged(
            (int) $this->alice->getId(),
            (int) $this->bob->getId(),
            'project:' . $this->projectA,
            ['Viewer'],
            ['Manager'],
        ));

        $entry = $this->fetchOne(
            "SELECT scope, payload FROM activities WHERE event_type='rbac.granted' ORDER BY id DESC",
        );

        $this->assertNotNull($entry, 'a change of roles left no trace');
        $this->assertSame('project:' . $this->projectA, $entry['scope']);

        $payload = json_decode((string) $entry['payload'], true);
        $this->assertSame(['Viewer'], $payload['before']);
        $this->assertSame(['Manager'], $payload['after']);
        // Resolved when it happened: an id names nothing once the row is gone.
        $this->assertNotSame('', $payload['person']);
    }

    /**
     * What happened to somebody's account is not what happened to the boards.
     * Reading the log and reading those are two rights, so they are two answers.
     */
    public function testPersonalEntriesAreLeftOutWithoutTheRight(): void
    {
        $this->pdo->prepare(
            'INSERT INTO activities(scope,project_id,ticket_id,actor_id,event_type,payload,created_at)'
            . " VALUES('',NULL,NULL,?,'account.password_changed',?,?)",
        )->execute([
            $this->alice->getId(),
            json_encode(['person' => 'Alice Winter'], JSON_THROW_ON_ERROR),
            gmdate('Y-m-d H:i:s'),
        ]);

        $log      = new AuditLog($this->pdo);
        $without  = array_column($log->entries(), 'event_type');
        $withThem = array_column($log->entries([], 0, 100, true), 'event_type');

        $this->assertNotContains('account.password_changed', $without);
        $this->assertContains('account.password_changed', $withThem);
        $this->assertArrayNotHasKey('account.password_changed', $log->types());
        $this->assertArrayHasKey('account.password_changed', $log->types(true));
    }

    private function entriesFor(int $ticket, string $type): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM activities WHERE ticket_id=? AND event_type=?',
            [$ticket, $type],
        );
    }

    private function insertUser(string $email): int
    {
        $this->pdo
            ->prepare('INSERT INTO users(name,email,password_hash,active) VALUES(?,?,?,1)')
            ->execute(['Kurz da', $email, null]);

        return (int) $this->scalar('SELECT id FROM users WHERE email=?', [$email]);
    }

    private function insertEntry(int $actor): int
    {
        $this->pdo
            ->prepare(
                'INSERT INTO activities(scope,project_id,ticket_id,actor_id,event_type,payload,created_at)'
                . " VALUES('',NULL,NULL,?,'settings.changed','{}',?)",
            )
            ->execute([$actor, gmdate('Y-m-d H:i:s')]);

        return (int) $this->scalar(
            'SELECT id FROM activities WHERE actor_id=? ORDER BY id DESC',
            [$actor],
        );
    }
}
