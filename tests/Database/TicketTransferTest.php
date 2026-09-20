<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\TimerService;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\app;

/**
 * Moving a ticket to another project.
 *
 * The rule underneath all of it: what the ticket itself is goes with it, what
 * only meant something in the project it left stays behind. A comment is the
 * ticket's; a label is the project's. Authorship travels because it is a fact
 * about the past -- Bob wrote that, and the destination having never heard of
 * Bob does not make it untrue. Assignment does not travel, because it is a claim
 * about the future and needs a membership on the other side.
 *
 * "Away" is deliberately missing Bob, so every one of those cases is live.
 */
final class TicketTransferTest extends BoardTestCase
{
    private int $home;
    private int $away;
    private int $homeLabel;
    private TimerService $timers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timers = app()->container()->make(TimerService::class);

        $this->home = $this->projects->create([
            'name'        => 'Transfer home',
            'description' => 'Where the ticket starts',
        ]);
        $this->away = $this->projects->create([
            'name'        => 'Transfer away',
            'description' => 'Where the ticket lands',
        ]);
        foreach (['bob', 'member'] as $name) {
            $this->projects->member($this->home, ['email' => $name . '@example.test', 'role' => 'member']);
        }
        $this->projects->member($this->away, ['email' => 'member@example.test', 'role' => 'member']);
        $this->projects->structure($this->away, ['kind' => 'column', 'name' => 'Eingang', 'color' => '#6366f1']);
        $this->projects->structure($this->home, ['kind' => 'label', 'name' => 'Nur hier']);
        $this->homeLabel = (int) $this->scalar('SELECT id FROM labels WHERE project_id=?', [$this->home]);
    }

    public function testTheTicketArrivesWithTheDestinationsOwnNumberAndReference(): void
    {
        ['ticket' => $ticket] = $this->ticketWithHistory();
        $before               = $this->tickets->ticket($this->home, $ticket);

        $moved = $this->transfer($ticket);
        $after = $this->tickets->ticket($this->away, $ticket);

        $this->assertSame($this->away, $moved['project'], 'the ticket did not arrive');
        $key = (string) $this->scalar('SELECT ticket_key FROM projects WHERE id=?', [$this->away]);
        $this->assertSame($key . '-1', $moved['reference']);
        $this->assertSame(
            $ticket,
            $this->tickets->resolve($this->away, $moved['reference']),
            'the new reference does not open the ticket',
        );
        $this->assertSame(1, (int) $after['number'], 'the ticket kept a number from the project it left');
        $this->assertSame(
            2,
            (int) $this->tickets->board($this->away)['next_number'],
            'the next ticket over there would reuse the number',
        );
        $this->assertSame($before['title'], $after['title']);
        $this->assertSame('high', $after['priority']);
        $this->assertGreaterThan(
            (int) $before['version'],
            (int) $after['version'],
            'the move left the version untouched',
        );
    }

    public function testItLandsAtTheFrontOfTheBoardItJoins(): void
    {
        ['ticket' => $ticket] = $this->ticketWithHistory();

        $this->transfer($ticket);

        $after = $this->tickets->ticket($this->away, $ticket);
        $board = $this->query->board($this->away);
        $this->assertSame((int) $board['columns'][0]['id'], (int) $after['column_id']);
        $this->assertSame((int) $board['board']['id'], (int) $after['board_id']);
    }

    public function testTheConversationAndTheFilesFollowWhoeverWroteThem(): void
    {
        ['ticket' => $ticket, 'root' => $root] = $this->ticketWithHistory();

        $this->transfer($ticket);

        $detail = $this->query->detail($this->away, $ticket);
        $this->assertCount(2, $detail['comments'], 'the conversation did not follow');
        $this->assertSame('Bob', $detail['comments'][0]['author_name'], 'the comment lost its author');
        $this->assertSame(
            $root,
            (int) $detail['comments'][1]['parent_id'],
            'the reply lost the comment it answered',
        );
        $this->assertCount(1, $detail['attachments'], 'the attachment did not follow');
        $this->assertSame(
            $this->bob->getId(),
            (int) $this->scalar(
                'SELECT uploaded_by FROM attachments WHERE project_id=? AND ticket_id=?',
                [$this->away, $ticket],
            ),
            'the attachment lost the person who uploaded it',
        );
        $this->assertSame(
            $this->alice->getId(),
            (int) $detail['ticket']['created_by'],
            'the ticket lost its creator',
        );
    }

    public function testTheHistoryArrivesAndIsNotLeftInBothPlaces(): void
    {
        ['ticket' => $ticket] = $this->ticketWithHistory();
        $was                  = $this->countFor('activities', $this->home, $ticket);
        $this->assertGreaterThan(0, $was, 'the ticket has no history to carry');

        $this->transfer($ticket);

        $this->assertGreaterThanOrEqual(
            $was + 1,
            $this->countFor('activities', $this->away, $ticket),
            'the history did not follow',
        );
        $this->assertSame(
            0,
            $this->countFor('activities', $this->home, $ticket),
            'the history is now in two places at once',
        );
    }

    public function testWhatOnlyMeantSomethingInTheOldProjectStaysBehind(): void
    {
        ['ticket' => $ticket, 'neighbour' => $neighbour] = $this->ticketWithHistory();
        $neighbourWas                                    = (int) $this->tickets->ticket($this->home, $neighbour)['version'];

        $this->transfer($ticket);

        $this->assertSame(
            0,
            $this->countFor('ticket_labels', $this->away, $ticket),
            'a label from the old project came along',
        );
        $this->assertSame(
            0,
            $this->countFor('ticket_links', $this->away, $ticket),
            'a link to a ticket left behind came along',
        );
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM ticket_links WHERE project_id=?', [$this->home]),
            'half a link stayed behind',
        );
        $this->assertGreaterThan(
            $neighbourWas,
            (int) $this->tickets->ticket($this->home, $neighbour)['version'],
            'the ticket it was linked to was never told',
        );
    }

    public function testTheArrivalIsAnnouncedAndNoNotificationPointsAtTheOldProject(): void
    {
        ['ticket' => $ticket] = $this->ticketWithHistory();
        $this->assertGreaterThan(
            0,
            $this->countFor('notifications', $this->home, $ticket),
            'nobody was told about the ticket in the first place',
        );

        $this->transfer($ticket);

        $this->assertGreaterThan(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE ticket_id=?', [$ticket]),
            'the arrival went unannounced',
        );
        $this->assertSame(
            0,
            $this->countFor('notifications', $this->home, $ticket),
            'a notification still points at a ticket that has left',
        );
    }

    public function testOnlyAssigneesWhoBelongToTheDestinationStayAssigned(): void
    {
        ['ticket' => $ticket] = $this->ticketWithHistory();

        $this->transfer($ticket);

        $this->assertSame(
            [$this->member->getId()],
            array_map('intval', $this->query->detail($this->away, $ticket)['selected_assignees']),
            'someone stayed assigned in a project they are not in',
        );
    }

    public function testTheClocksAreStoppedOnTheWayOutAndTheirTimeReachesTheTicket(): void
    {
        ['ticket' => $ticket] = $this->ticketWithHistory();

        $this->transfer($ticket);

        $after = $this->tickets->ticket($this->away, $ticket);
        $this->assertSame(3, (int) $after['spent_minutes'], 'counted time was lost on the way');
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM ticket_timers WHERE ticket_id=?', [$ticket]),
            'a running clock survived the move',
        );
        $this->assertNull($this->timers->running(), 'the bar still marks a run on a ticket that moved');
    }

    public function testOnlyProjectsThePersonMayWriteInAreOffered(): void
    {
        $this->actAs($this->bob);
        $readOnly = $this->projects->create([
            'name'        => 'Transfer nowhere',
            'description' => 'Alice only watches',
        ]);
        $this->projects->member($readOnly, ['email' => 'alice@example.test', 'role' => 'viewer']);

        $this->actAs($this->alice);
        $shelved = $this->projects->create([
            'name'        => 'Transfer shelved',
            'description' => 'Archived before the move',
        ]);
        $this->projects->archive($shelved, true);

        $offered = array_column($this->query->transferTargets($this->home), 'id');

        $this->assertContains($this->away, $offered, 'a project the person owns was not offered');
        $this->assertNotContains($this->home, $offered, 'the project the ticket is already in was offered');
        $this->assertNotContains($readOnly, $offered, 'a project the person may only read was offered');
        $this->assertNotContains($shelved, $offered, 'an archived project was offered');
    }

    public function testAMoveThatCannotBeMadeChangesNothing(): void
    {
        $ticket = $this->addTicket($this->home, 'Bleibt wo es ist');

        $this->assertDenied(422, fn() => $this->moveTo($ticket, $this->home), 'into the project it is in');
        $this->assertDenied(422, fn() => $this->moveTo($ticket, 'nowhere'), 'into something that is not an id');
        $this->assertDenied(404, fn() => $this->moveTo($ticket, 999999), 'into a project that does not exist');
        $this->assertDenied(
            409,
            fn() => $this->tickets->transfer($this->home, $ticket, [
                'project_id' => $this->away,
                'version'    => 99,
            ]),
            'with a version nobody has',
        );

        // Reading a project is not permission to put work into it.
        $this->actAs($this->bob);
        $readOnly = $this->projects->create([
            'name'        => 'Transfer readable',
            'description' => 'Alice watches here too',
        ]);
        $this->projects->member($readOnly, ['email' => 'alice@example.test', 'role' => 'viewer']);
        $this->actAs($this->alice);
        $this->assertDenied(403, fn() => $this->moveTo($ticket, $readOnly), 'into a project she may only read');

        $this->tickets->state($this->home, $ticket, ['action' => 'archive'] + $this->versionOf($ticket));
        $this->assertDenied(422, fn() => $this->moveTo($ticket, $this->away), 'while archived');
        $this->tickets->state($this->home, $ticket, ['action' => 'restore'] + $this->versionOf($ticket));

        $this->assertSame(
            $this->home,
            (int) $this->tickets->ticket($this->home, $ticket)['project_id'],
            'a refused move went through anyway',
        );
        $this->assertNotEmpty(
            $this->query->detail($this->home, $ticket),
            'the ticket is no longer readable where it was',
        );
    }

    /**
     * A ticket carrying everything a move has to decide about: a label and a link
     * that belong to this project, a comment thread and an attachment from
     * someone the destination does not know, two assignees of whom only one does
     * belong there, and time on two clocks.
     *
     * @return array{ticket:int,neighbour:int,root:int}
     */
    private function ticketWithHistory(): array
    {
        $ticket    = $this->addTicket($this->home, 'Wandert');
        $neighbour = $this->addTicket($this->home, 'Bleibt hier');

        $this->tickets->update($this->home, $ticket, [
            'label_ids'      => [$this->homeLabel],
            'assignee_ids'   => [$this->bob->getId(), $this->member->getId()],
            'version'        => $this->tickets->ticket($this->home, $ticket)['version'],
            'board_revision' => $this->tickets->board($this->home)['revision'],
        ]);
        $this->tickets->link($this->home, $ticket, [
            'number' => $this->tickets->ticket($this->home, $neighbour)['number'],
        ] + $this->versionOf($ticket));
        $this->attach($ticket, $this->bob->getId());

        // Bob writes, and is then the one person the destination has never heard of.
        $this->actAs($this->bob);
        $this->comments->save($this->home, $ticket, ['body' => 'Bob asks a question']);
        $root = (int) $this->scalar(
            'SELECT id FROM comments WHERE project_id=? AND ticket_id=? ORDER BY id',
            [$this->home, $ticket],
        );
        $this->timers->act($this->home, $ticket, ['action' => 'start']);
        $this->rewindClock($ticket, $this->bob->getId(), 150);
        $this->timers->act($this->home, $ticket, ['action' => 'pause']);

        $this->actAs($this->alice);
        $this->comments->save($this->home, $ticket, ['body' => 'Alice replies', 'parent_id' => $root]);
        $this->timers->act($this->home, $ticket, ['action' => 'start']);
        $this->rewindClock($ticket, $this->alice->getId(), 80);

        return ['ticket' => $ticket, 'neighbour' => $neighbour, 'root' => $root];
    }

    /** @return array<string,mixed> */
    private function transfer(int $ticket): array
    {
        return $this->tickets->transfer(
            $this->home,
            $ticket,
            ['project_id' => $this->away] + $this->versionOf($ticket),
        );
    }

    private function moveTo(int $ticket, mixed $project): mixed
    {
        return $this->tickets->transfer(
            $this->home,
            $ticket,
            ['project_id' => $project] + $this->versionOf($ticket),
        );
    }

    private function addTicket(int $project, string $title): int
    {
        $board = $this->query->board($project);

        return $this->tickets->create($project, [
            'title'          => $title,
            'description'    => 'Travels with the ticket',
            'priority'       => 'high',
            'column_id'      => $board['columns'][0]['id'],
            'swimlane_id'    => $board['swimlanes'][0]['id'],
            'board_revision' => $this->tickets->board($project)['revision'],
        ]);
    }

    /** @return array{version:mixed} */
    private function versionOf(int $ticket): array
    {
        return ['version' => $this->tickets->ticket($this->home, $ticket)['version']];
    }

    private function countFor(string $table, int $project, int $ticket): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE project_id=? AND ticket_id=?",
            [$project, $ticket],
        );
    }

    /**
     * A file written straight into the table: what is under test is the key it
     * hangs on, not the upload that would otherwise have to be staged first.
     */
    private function attach(int $ticket, int $uploader): void
    {
        $this->pdo->prepare(
            'INSERT INTO attachments(project_id,ticket_id,uploaded_by,storage_key,original_name,'
            . "mime_type,byte_size,sha256,state,created_at) "
            . "VALUES(?,?,?,?,'travels.txt','text/plain',9,?,'ready',?)",
        )->execute([
            $this->home,
            $ticket,
            $uploader,
            'transfer-' . $ticket . '-' . $uploader,
            str_repeat('a', 64),
            gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Move a running clock back in time, so it has counted something. */
    private function rewindClock(int $ticket, int $user, int $seconds): void
    {
        $this->pdo
            ->prepare('UPDATE ticket_timers SET started_at=? WHERE project_id=? AND ticket_id=? AND user_id=?')
            ->execute([gmdate('Y-m-d H:i:s', time() - $seconds), $this->home, $ticket, $user]);
    }
}
