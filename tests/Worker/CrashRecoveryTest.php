<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Worker;

use Naf\Board\Tests\Support\CrashProbeJob;
use Naf\Board\Tests\Support\DatabaseTestCase;
use RuntimeException;

use function Naf\Queue\queue;

/**
 * What happens to a job when the worker holding it dies.
 *
 * A queue that loses work when a machine is restarted is not a queue, so the
 * reservation lives in the database rather than in the process: the job stays
 * reserved with its attempt counted, and once the lease runs out another worker
 * may take it over. It must then finish it exactly once -- a job that runs twice
 * is worse than one that never ran, because nobody notices.
 *
 * Real processes, really killed. Nothing else would prove it.
 */
final class CrashRecoveryTest extends DatabaseTestCase
{
    protected bool $transactional = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearMarkers();
        $this->pdo->exec('DELETE FROM naf_queue_jobs');
    }

    protected function tearDown(): void
    {
        $this->clearMarkers();
        $this->pdo->exec('DELETE FROM naf_queue_jobs');
        parent::tearDown();
    }

    public function testAKilledWorkerLeavesItsReservationInTheDatabase(): void
    {
        $this->enqueueProbe();

        $this->killWorkerInsideItsJob();

        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM naf_queue_jobs WHERE attempts=1'),
            'the killed worker took its reservation with it',
        );
    }

    public function testAReplacementTakesOverTheExpiredLeaseAndFinishesTheJobOnce(): void
    {
        $this->enqueueProbe();
        $this->killWorkerInsideItsJob();

        // The deadline instead of five real minutes of waiting for it.
        $this->pdo->exec('UPDATE naf_queue_jobs SET lease_until=0');
        $this->assertSame(0, $this->runWorker(), 'the replacement worker failed');

        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM naf_queue_jobs'),
            'the job was not acknowledged',
        );
        $this->assertSame(
            "completed\n",
            file_get_contents(BASE_PATH . CrashProbeJob::FINISHED),
            'the job ran a number of times other than once',
        );
    }

    /**
     * A job whose class was removed by a deployment cannot run and will never be
     * able to. Failing loudly and setting it aside is the only useful answer:
     * retrying forever would keep the queue busy with something impossible.
     */
    public function testAJobWhoseClassIsGoneFailsAndIsSetAside(): void
    {
        queue()->driver()->enqueue('MissingNafinityProbeClass', []);

        $this->assertNotSame(0, $this->runWorker(), 'a job that cannot run reported success');
        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM naf_queue_jobs WHERE state='failed'"),
            'the impossible job was not set aside',
        );
    }

    private function enqueueProbe(): void
    {
        queue()->driver()->enqueue(CrashProbeJob::class, ['_job_id' => 'process-crash-probe']);
    }

    /** Start a worker, wait until it is really inside the job, then kill it. */
    private function killWorkerInsideItsJob(): void
    {
        $process = $this->startWorker();

        try {
            $started  = BASE_PATH . CrashProbeJob::STARTED;
            $deadline = microtime(true) + 8;
            while (!is_file($started) && microtime(true) < $deadline) {
                usleep(50000);
            }
            $this->assertFileExists($started, 'the worker never entered its job');
        } finally {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }

    /** @return resource */
    private function startWorker()
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Support/worker.php'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start a test worker.');
        }

        return $process;
    }

    private function runWorker(): int
    {
        return proc_close($this->startWorker());
    }

    private function clearMarkers(): void
    {
        foreach ([CrashProbeJob::STARTED, CrashProbeJob::FINISHED] as $marker) {
            if (is_file(BASE_PATH . $marker)) {
                unlink(BASE_PATH . $marker);
            }
        }
    }
}
