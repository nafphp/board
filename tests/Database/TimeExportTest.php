<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Domain\Change;
use Naf\Board\Domain\Failure;
use Naf\Board\Export\ExportFinished;
use Naf\Board\Export\ExportLine;
use Naf\Board\Export\TimeExportOptions;
use Naf\Board\Services\TimeExportService;
use Naf\Board\Services\TimerService;
use Naf\Board\Tests\Support\BoardTestCase;
use PDO;

use function Naf\app;
use function Naf\event;

final class TimeExportTest extends BoardTestCase
{
    private function rows(array $input = [], ?array $projects = null): array
    {
        $file = app()->container()->make(TimeExportService::class)->write(
            $projects ?? [$this->projectA],
            'json',
            TimeExportOptions::fromInput($input),
        );

        try {
            return json_decode(stream_get_contents($file['stream']), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            fclose($file['stream']);
        }
    }

    private function book(int $ticket, int $seconds): void
    {
        $timers = app()->container()->make(TimerService::class);
        $timers->act($this->projectA, $ticket, ['action' => 'start']);
        $this->pdo->prepare("UPDATE ticket_timers SET started_at=NULL,tracked_seconds=?,state='paused'
            WHERE project_id=? AND ticket_id=? AND user_id=?")
            ->execute([$seconds, $this->projectA, $ticket, $this->access->actor()]);
        $timers->act($this->projectA, $ticket, ['action' => 'stop']);
    }

    public function testStoppingBooksOnceForTheActualPersonAndKeepsExactMinutes(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Überprüfung & <Rechnung>']));
        $this->book($ticket, 61);
        app()->container()->make(TimerService::class)->act($this->projectA, $ticket, ['action' => 'stop']);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['minutes']);
        $this->assertSame(0.016667, $rows[0]['hours']);
        $this->assertSame('HUR', $rows[0]['unit']);
        $this->assertSame('Überprüfung & <Rechnung>', $rows[0]['title']);

        $this->actAs($this->member);
        $this->book($ticket, 120);
        $this->assertSame(2, $this->rows()[0]['minutes']);
        $this->actAs($this->alice);
        $this->assertSame(1, $this->rows()[0]['minutes']);
        $this->assertSame(3, (int) $this->scalar('SELECT spent_minutes FROM tickets WHERE id=?', [$ticket]));
    }

    public function testLiveAndPausedTimersAndManuallyChangedTotalsAreNotExported(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->pdo->prepare('UPDATE tickets SET spent_minutes=600 WHERE id=?')->execute([$ticket]);
        $timers = app()->container()->make(TimerService::class);
        $timers->act($this->projectA, $ticket, ['action' => 'start']);
        $this->assertSame([], $this->rows());
        $timers->act($this->projectA, $ticket, ['action' => 'pause']);
        $this->assertSame([], $this->rows());
        $this->book($ticket, 59);
        $this->assertSame([], $this->rows());
        $this->book($ticket, 1);
        $this->assertSame(1, $this->rows()[0]['minutes']);
    }

    public function testARefusedBookingRollsBackBothTheTotalAndItsExportEntry(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $timers = app()->container()->make(TimerService::class);
        $timers->act($this->projectA, $ticket, ['action' => 'start']);
        $this->pdo->prepare("UPDATE ticket_timers SET started_at=NULL,tracked_seconds=120,state='paused' WHERE ticket_id=?")
            ->execute([$ticket]);
        $reject = true;
        event()->listen(Change::class, static function (Change $change) use (&$reject): void {
            if ($reject && $change->type === 'timer.recorded') {
                throw new Failure('Booking refused');
            }
        });

        try {
            $this->assertDenied(422, fn() => $timers->act($this->projectA, $ticket, ['action' => 'stop']));
            $this->assertSame([], $this->rows());
            $this->assertSame(0, (int) $this->scalar('SELECT spent_minutes FROM tickets WHERE id=?', [$ticket]));
            $this->assertSame(120, $timers->state($this->projectA, $ticket)['seconds']);
        } finally {
            $reject = false;
        }
        $timers->act($this->projectA, $ticket, ['action' => 'stop']);
        $this->assertSame(2, $this->rows()[0]['minutes']);
    }

    public function testDatesAreInclusiveUtcBookingDaysAndAllowOpenEnds(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        foreach (['2026-09-20 23:59:59', '2026-09-21 00:00:00', '2026-09-22 23:59:59', '2026-09-23 00:00:00'] as $date) {
            $this->book($ticket, 60);
            $this->pdo->prepare('UPDATE ticket_time_entries SET recorded_at=? WHERE id=?')
                ->execute([$date, $this->scalar('SELECT MAX(id) FROM ticket_time_entries')]);
        }
        $this->assertCount(2, $this->rows(['from' => '2026-09-21', 'until' => '2026-09-22']));
        $this->assertCount(3, $this->rows(['from' => '2026-09-21']));
        $this->assertCount(3, $this->rows(['until' => '2026-09-22']));
        $this->assertSame([], $this->rows(['from' => '2026-09-24']));
    }

    public function testEveryProjectIsAuthorizedAndHistoricalViewerBookingsStayPersonal(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->book($ticket, 60);
        $this->assertDenied(404, fn() => $this->rows([], [$this->projectA, $this->projectB]));
        $this->actAs($this->viewer);
        $this->assertSame([], $this->rows());
        $this->actAs($this->alice);
        $this->pdo->prepare('UPDATE projects SET archived_at=? WHERE id=?')->execute(['2026-10-01 12:00:00', $this->projectA]);
        $this->assertSame(1, $this->rows()[0]['minutes']);
    }

    public function testTransferKeepsTheOriginalTicketAndProjectInTheBooking(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Original title']));
        $this->book($ticket, 120);
        $original = $this->rows()[0];
        $this->actAs($this->bob);
        $this->projects->member($this->projectB, ['email' => 'alice@example.test', 'role' => 'member']);
        $this->actAs($this->alice);
        $version = (int) $this->scalar('SELECT version FROM tickets WHERE id=?', [$ticket]);
        $this->tickets->transfer($this->projectA, $ticket, ['version' => $version, 'project_id' => $this->projectB]);
        $this->assertSame($original, $this->rows()[0]);
        $this->assertSame([], $this->rows([], [$this->projectB]));
    }

    public function testDeletingATicketRetainsItsBookingButDeletingAProjectRemovesIt(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->book($ticket, 60);
        $version = (int) $this->scalar('SELECT version FROM tickets WHERE id=?', [$ticket]);
        $this->tickets->delete($this->projectA, $ticket, ['version' => $version]);
        $this->assertSame(1, $this->rows()[0]['minutes']);
        $this->projects->delete($this->projectA, ['confirmation' => 'A']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ticket_time_entries WHERE project_id=?', [$this->projectA]));
    }

    public function testPagingAndEventsUseTheSharedPipelineAndIdentifyTheSource(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->book($ticket, 60);
        $row = $this->pdo->query('SELECT * FROM ticket_time_entries')->fetch(PDO::FETCH_ASSOC);
        unset($row['id']);
        $insert = $this->pdo->prepare('INSERT INTO ticket_time_entries (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
        for ($i = 0; $i < 501; $i++) {
            $insert->execute(array_values($row));
        }
        $finished = [];
        event()->listen(ExportLine::class, static function (ExportLine $line): void {
            if ($line->source === 'time' && $line->isFor('json')) {
                $line->data['title'] = 'Mapped time entry';
            }
        });
        event()->listen(ExportFinished::class, static function (ExportFinished $export) use (&$finished): void {
            if ($export->source === 'time') {
                $finished[] = $export->written;
            }
        });
        $rows = $this->rows();
        $this->assertCount(502, $rows);
        $this->assertSame([502], $finished);
        $this->assertSame('Mapped time entry', $rows[501]['title']);
    }
}
