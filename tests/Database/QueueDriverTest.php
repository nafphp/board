<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\DatabaseTestCase;
use Naf\Queue\Drivers\PDODriver;
use RuntimeException;

/**
 * The queue in the database, which is what makes a job and the change that
 * caused it one atomic thing: a job enqueued by work that is rolled back must
 * never run.
 *
 * The rest is about two workers reaching for the same job. A lease says who
 * holds it and until when; a worker whose lease has expired must be told so
 * rather than allowed to acknowledge work someone else has taken over.
 *
 * Not transactional: the first test rolls one back on purpose.
 */
final class QueueDriverTest extends DatabaseTestCase
{
    protected bool $transactional = false;

    private PDODriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DELETE FROM naf_queue_jobs');
        $this->driver = new PDODriver($this->pdo, 30);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM naf_queue_jobs');
        parent::tearDown();
    }

    public function testAJobEnqueuedInsideARolledBackTransactionNeverExists(): void
    {
        $this->pdo->beginTransaction();
        $this->driver->enqueue('NeverRuns', []);
        $this->pdo->rollBack();

        $this->assertNull($this->driver->reserve(), 'the job escaped the rollback');
    }

    public function testOnlyOneWorkerHoldsAJobAtATime(): void
    {
        $this->driver->enqueue('TestJob', ['_job_id' => 'same']);
        $this->driver->enqueue('TestJob', ['_job_id' => 'same']);

        $this->assertNotNull($this->driver->reserve());
        $this->assertNull($this->driver->reserve(), 'a second worker claimed the same job');
    }

    public function testAWorkerWhoseLeaseExpiredCannotAcknowledgeTheJob(): void
    {
        $this->driver->enqueue('TestJob', ['_job_id' => 'same']);
        $first = $this->driver->reserve();

        // The lease runs out and another worker picks the job up.
        $this->pdo->exec('UPDATE naf_queue_jobs SET lease_until=0');
        $this->driver->reserve();

        try {
            $this->driver->acknowledge($first);
            $this->fail('a worker acknowledged a job its lease no longer covered');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('lease was lost', $exception->getMessage());
        }
    }

    public function testAJobThatRanOutOfAttemptsIsSetAsideAndCanBeRetried(): void
    {
        $this->driver->enqueue('TestJob', ['_job_id' => 'same']);
        $this->driver->reserve();
        // A second attempt, by taking the job over from a worker whose lease ran
        // out. Two is the limit below, so this is the attempt that uses it up.
        $this->pdo->exec('UPDATE naf_queue_jobs SET lease_until=0');
        $job = $this->driver->reserve();

        $this->driver->release($job, new RuntimeException('test failure'), 2, 0);

        $this->assertNull($this->driver->reserve(), 'a dead-lettered job was handed out again');
        $this->assertSame(1, $this->driver->retryFailed(), 'the failed job was not found to retry');

        $retry = $this->driver->reserve();
        $this->assertSame(1, $retry['attempts'], 'the retry forgot how often the job had run');

        $this->driver->acknowledge($retry);
    }
}
