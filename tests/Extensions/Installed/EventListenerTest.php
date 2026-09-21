<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

/**
 * A listener an extension registers runs inside the transaction that caused it.
 *
 * That is what makes the job and the change one thing: a change that is refused
 * must leave no job behind, because a job enqueued for something that never
 * happened would run against a state that does not exist.
 */
final class EventListenerTest extends ExtensionInstalledTestCase
{
    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DELETE FROM naf_queue_jobs');
        $this->ticket = $this->createTicket();
    }

    public function testTheListenerEnqueuesOnceForTheChangeItWatches(): void
    {
        $this->markReviewed(false);

        $this->assertSame(1, $this->queued(), 'the listener did not enqueue exactly once');
    }

    public function testARefusedChangeLeavesNoJobBehind(): void
    {
        $this->markReviewed(false);
        $before = $this->queued();

        $this->assertDenied(422, fn() => $this->tickets->update($this->project, $this->ticket, [
            'metadata' => ['example.reviewed' => 'perhaps'],
            'version'  => $this->ticketVersion($this->ticket),
            ...$this->revision(),
        ]));

        $this->assertSame($before, $this->queued(), 'a rejected change left a job behind');
    }

    private function markReviewed(bool $reviewed): void
    {
        $this->tickets->update($this->project, $this->ticket, [
            'metadata' => ['example.reviewed' => $reviewed],
            'version'  => $this->ticketVersion($this->ticket),
            ...$this->revision(),
        ]);
    }

    private function queued(): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM naf_queue_jobs WHERE job_class LIKE '%ReviewNoticeJob%'",
        );
    }
}
