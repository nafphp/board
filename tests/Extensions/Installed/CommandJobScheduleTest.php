<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Example\ExtensionA\Jobs\ReviewReminderJob;
use Example\ExtensionA\Migrations\M202609180101ExampleReports;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;
use Naf\CLI\Core\Output;
use Naf\CLI\Support\CommandRegistry;
use Naf\Queue\Core\Queue;
use Naf\Schedule\Core\JobRepository;
use PDO;

use function Naf\app;

/**
 * The parts of an extension that are not pages: a console command, a migration
 * with a table behind it, a queued job and a scheduled one. They arrive by being
 * installed, the same way everything else does.
 *
 * And the job stays inside its own table. A package that runs in the background
 * has the whole database in reach, so it is worth showing that this one does not
 * reach past what it brought.
 */
final class CommandJobScheduleTest extends ExtensionInstalledTestCase
{
    public function testTheConsoleCommandIsRegistered(): void
    {
        $commands = array_keys(app()->container()->get(CommandRegistry::class)->all());

        $this->assertContains('example:reports', $commands);
    }

    public function testTheMigrationRanAndItsTableIsThere(): void
    {
        $applied = $this->pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains(M202609180101ExampleReports::class, $applied);
        $this->assertNotFalse(
            $this->pdo->query('SELECT COUNT(*) FROM example_report_runs')->fetchColumn(),
            'the extension table is missing',
        );
    }

    public function testTheScheduledJobIsRegistered(): void
    {
        $scheduled = app()->container()->get(JobRepository::class)->all();

        $this->assertArrayHasKey(ReviewReminderJob::class, $scheduled);
    }

    public function testTheQueuedJobRunsAndWritesOnlyItsOwnTable(): void
    {
        $this->pdo->exec('DELETE FROM naf_queue_jobs');
        $this->pdo->exec('DELETE FROM example_report_runs');
        $this->enqueueByReviewing();

        $job = app()->container()->get(Queue::class)->pop();
        $this->assertNotNull($job, 'the listener queued nothing to run');

        $class = $job['class'] ?? $job['job_class'] ?? null;
        $this->assertIsString($class);
        $this->assertStringContainsString('ReviewNoticeJob', $class, 'a different job was queued');

        $before  = (int) $this->scalar('SELECT COUNT(*) FROM tickets');
        $payload = is_string($job['payload'] ?? null)
            ? json_decode($job['payload'], true)
            : ($job['payload'] ?? []);

        ob_start();
        app()->container()->make($class, is_array($payload) ? $payload : [])->execute(new Output());
        ob_end_clean();

        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM example_report_runs'),
            'the job wrote nothing',
        );
        $this->assertSame(
            $before,
            (int) $this->scalar('SELECT COUNT(*) FROM tickets'),
            'the job reached past its own table',
        );
    }

    /** The listener queues the job; asking it to is how one gets there. */
    private function enqueueByReviewing(): void
    {
        $ticket = $this->createTicket();
        $this->tickets->update($this->project, $ticket, [
            'metadata' => ['example.reviewed' => true],
            'version'  => $this->ticketVersion($ticket),
            ...$this->revision(),
        ]);
    }
}
