<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\TimerService;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\app;

/**
 * Tracking time on a ticket.
 *
 * Pausing holds the clock and writes nothing, which is what gives stopping a
 * meaning: only stopping books whole minutes onto the ticket, and the seconds
 * left over stay on the clock and count towards the next one. A person counts
 * one thing at a time, so starting somewhere else settles what was running --
 * as a pause, because nobody asked for that time to be booked yet.
 *
 * On the exactness of the numbers here: a running clock is the wall clock minus
 * a stored start, so a second can pass between rewinding it and reading it. Live
 * readings are therefore compared with a second of tolerance, and only values
 * the service has stored -- a paused clock, booked minutes -- are compared
 * exactly. Whole minutes are unaffected either way; a second on 150 does not
 * change how many minutes that is.
 */
final class TimerTest extends BoardTestCase
{
    private const TICK = 1;

    private TimerService $timers;
    private int $project;
    /** @var array<string,mixed> */
    private array $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timers  = app()->container()->make(TimerService::class);
        $this->project = $this->projects->create([
            'name'        => 'Timer',
            'description' => 'Time tracking regression',
        ]);
        $this->projects->member($this->project, ['email' => 'bob@example.test', 'role' => 'member']);
        $this->projects->member($this->project, ['email' => 'viewer@example.test', 'role' => 'viewer']);
        $this->board = $this->query->board($this->project);
    }

    public function testPausingHoldsTheClockAndBooksNothing(): void
    {
        $ticket = $this->addTicket();
        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 150);

        $paused = $this->timers->act($this->project, $ticket, ['action' => 'pause']);

        $this->assertSame('paused', $paused['state']);
        $this->assertEqualsWithDelta(150, $paused['seconds'], self::TICK, 'the clock lost time on pause');
        $this->assertSame(0, $this->spent($ticket), 'pausing booked time onto the ticket');
    }

    public function testAResumedClockCarriesOnFromWhereItStood(): void
    {
        $ticket = $this->addTicket();
        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 150);
        $this->timers->act($this->project, $ticket, ['action' => 'pause']);

        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 40);
        $paused = $this->timers->act($this->project, $ticket, ['action' => 'pause']);

        $this->assertEqualsWithDelta(190, $paused['seconds'], self::TICK);
        $this->assertSame(0, $this->spent($ticket), 'a second pause booked time onto the ticket');
    }

    public function testStoppingBooksTheWholeMinutesAndStartsAFreshSession(): void
    {
        $ticket = $this->addTicket();
        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 190);

        $stopped = $this->timers->act($this->project, $ticket, ['action' => 'stop']);

        $this->assertSame('stopped', $stopped['state']);
        $this->assertSame(3, $stopped['spent_minutes'], 'stop reported the wrong total');
        $this->assertSame(3, $this->spent($ticket), 'stopping did not book what it showed');
        $this->assertSame(0, $stopped['seconds'], 'stopping did not start a fresh session');
    }

    /** The ten seconds left over stay with the run and count towards the next minute. */
    public function testTheSecondsLeftOverAddUpAcrossSessions(): void
    {
        $ticket = $this->addTicket();
        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 190);
        $this->timers->act($this->project, $ticket, ['action' => 'stop']);

        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 50);
        $this->timers->act($this->project, $ticket, ['action' => 'stop']);

        $this->assertSame(4, $this->spent($ticket), 'short sessions were rounded away instead of adding up');
    }

    /** Exactly what a person does: run a while, pause, look, start again, look. */
    public function testTheClockNeverRunsBackwardsAcrossAPause(): void
    {
        $ticket = $this->addTicket();
        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 90);

        $running = $this->timers->state($this->project, $ticket)['seconds'];
        $this->assertEqualsWithDelta(90, $running, self::TICK, 'a minute and a half was not counted');

        $paused = $this->timers->act($this->project, $ticket, ['action' => 'pause'])['seconds'];
        $this->assertGreaterThanOrEqual($running, $paused, 'the clock went backwards on pause');
        $this->assertLessThanOrEqual($running + self::TICK, $paused, 'the clock jumped on pause');

        // A paused clock is a stored number, so resuming must return it unchanged.
        $resumed = $this->timers->act($this->project, $ticket, ['action' => 'start'])['seconds'];
        $this->assertSame($paused, $resumed, 'resuming restarted the clock');

        $this->rewind($ticket, 30);
        $this->assertEqualsWithDelta(
            120,
            $this->timers->state($this->project, $ticket)['seconds'],
            self::TICK,
            'the clock did not carry on',
        );

        $this->timers->act($this->project, $ticket, ['action' => 'stop']);
        $this->assertSame(2, $this->spent($ticket), 'the booked minutes disagree with the clock');
    }

    public function testStartingSomethingElseSettlesTheRunningOneAsAPause(): void
    {
        $first  = $this->addTicket();
        $second = $this->addTicket();
        $this->timers->act($this->project, $first, ['action' => 'start']);
        $this->rewind($first, 120);

        $this->timers->act($this->project, $second, ['action' => 'start']);

        $this->assertSame('paused', $this->timers->state($this->project, $first)['state'], 'the previous run kept counting');
        $this->assertSame(0, $this->spent($first), 'the interrupted run was booked without being stopped');
        $this->assertEqualsWithDelta(
            120,
            $this->timers->state($this->project, $first)['seconds'],
            self::TICK,
            'the interrupted run lost its time',
        );
    }

    /** The marker in the bar reads the ticket's clock, not a shorter one of its own. */
    public function testTheMarkerAndTheTicketAgreeOnWhatIsRunning(): void
    {
        $ticket = $this->addTicket();
        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 120);

        $running = $this->timers->running();

        $this->assertNotNull($running);
        $this->assertSame($ticket, (int) $running['ticket_id'], 'the wrong run is reported as running');
        $this->assertEqualsWithDelta(
            $this->timers->state($this->project, $ticket)['seconds'],
            $running['seconds'],
            self::TICK,
            'the marker and the ticket disagree about the elapsed time',
        );
        $this->assertSame([$ticket], $this->timers->runningIn($this->project));
    }

    public function testARunInAnotherProjectSettlesThisOneToo(): void
    {
        $here = $this->addTicket();
        $this->timers->act($this->project, $here, ['action' => 'start']);

        $other = $this->projects->create(['name' => 'Timer elsewhere', 'description' => 'Second project']);
        $board = $this->query->board($other);
        $far   = $this->tickets->create($other, [
            'title'          => 'Elsewhere',
            'description'    => 'Another project',
            'priority'       => 'normal',
            'column_id'      => $board['columns'][0]['id'],
            'swimlane_id'    => $board['swimlanes'][0]['id'],
            'board_revision' => $this->tickets->board($other)['revision'],
        ]);

        $this->timers->act($other, $far, ['action' => 'start']);

        $this->assertSame('paused', $this->timers->state($this->project, $here)['state'], 'a run in another project kept counting');
        $this->assertSame([], $this->timers->runningIn($this->project), 'the board still marks a settled run');
    }

    /**
     * Someone who has tracked an hour holds a stale ticket version by now --
     * someone else may well have edited the ticket meanwhile. Stopping the clock
     * is not an edit of the ticket, so it must not need that version.
     */
    public function testStoppingTheClockDoesNotNeedTheTicketVersion(): void
    {
        $ticket = $this->addTicket();
        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 3600);

        $this->tickets->update($this->project, $ticket, [
            'title'          => 'Renamed while the clock ran',
            'version'        => $this->tickets->ticket($this->project, $ticket)['version'],
            'board_revision' => $this->tickets->board($this->project)['revision'],
        ]);
        $this->timers->act($this->project, $ticket, ['action' => 'stop']);

        $this->assertSame(60, $this->spent($ticket), 'the hour did not reach the ticket');
        $this->assertSame(
            'Renamed while the clock ran',
            $this->tickets->ticket($this->project, $ticket)['title'],
            'the edit made during the run was lost',
        );
    }

    public function testAnUnknownActionAndAnArchivedTicketAreRefused(): void
    {
        $ticket = $this->addTicket();

        $this->assertDenied(422, fn() => $this->timers->act($this->project, $ticket, ['action' => 'rewind']));

        $this->tickets->state($this->project, $ticket, [
            'action'  => 'archive',
            'version' => $this->tickets->ticket($this->project, $ticket)['version'],
        ]);
        $this->assertDenied(422, fn() => $this->timers->act($this->project, $ticket, ['action' => 'start']));
    }

    public function testTwoPeopleTrackTheSameTicketWithoutOverwritingEachOther(): void
    {
        $ticket = $this->addTicket();
        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 180);
        $this->timers->act($this->project, $ticket, ['action' => 'stop']);
        $this->assertSame(3, $this->spent($ticket), 'her own time is missing');

        $this->actAs($this->bob);
        $this->timers->act($this->project, $ticket, ['action' => 'start']);
        $this->rewind($ticket, 120, $this->bob->getId());
        $this->timers->act($this->project, $ticket, ['action' => 'stop']);

        $this->assertSame(5, $this->spent($ticket), 'the second person did not add to the same ticket');
        $this->assertSame(
            0,
            $this->timers->state($this->project, $ticket)['seconds'],
            'the two runs were mixed up',
        );
    }

    public function testReadingAProjectIsNotPermissionToBookTimeAgainstIt(): void
    {
        $ticket = $this->addTicket();

        $this->actAs($this->viewer);
        $this->assertDenied(403, fn() => $this->timers->act($this->project, $ticket, ['action' => 'start']));

        // A stranger is not even told the project exists.
        $this->actAs($this->member);
        $this->assertDenied(404, fn() => $this->timers->act($this->project, $ticket, ['action' => 'start']));
    }

    private function addTicket(): int
    {
        return $this->tickets->create($this->project, [
            'title'          => 'Tracked',
            'description'    => 'Carries time',
            'priority'       => 'normal',
            'column_id'      => $this->board['columns'][0]['id'],
            'swimlane_id'    => $this->board['swimlanes'][0]['id'],
            'board_revision' => $this->tickets->board($this->project)['revision'],
        ]);
    }

    private function spent(int $ticket): int
    {
        return (int) $this->tickets->ticket($this->project, $ticket)['spent_minutes'];
    }

    /** Move a stored start back in time: a long run without waiting for one. */
    private function rewind(int $ticket, int $seconds, ?int $user = null): void
    {
        $this->pdo
            ->prepare('UPDATE ticket_timers SET started_at=? WHERE project_id=? AND ticket_id=? AND user_id=?')
            ->execute([
                gmdate('Y-m-d H:i:s', time() - $seconds),
                $this->project,
                $ticket,
                $user ?? $this->alice->getId(),
            ]);
    }
}
