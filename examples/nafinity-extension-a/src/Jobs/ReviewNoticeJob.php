<?php

declare(strict_types=1);

namespace Example\ExtensionA\Jobs;

use Naf\CLI\Core\Output;
use Naf\Queue\Core\QueueJobInterface;
use PDO;

/**
 * The work that follows a review, once the change is actually committed.
 *
 * A job has no session, so it does not pretend to be anyone: it works on the
 * ids it was given and writes only into this extension's own table. A right
 * that has been taken away in the meantime is checked here rather than assumed
 * from when the job was queued.
 */
final class ReviewNoticeJob implements QueueJobInterface
{
    public function __construct(
        private int $projectId,
        private ?int $ticketId,
        private PDO $pdo,
    ) {
    }

    public function execute(Output $output): void
    {
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE id=? AND archived_at IS NULL');
        $exists->execute([$this->projectId]);

        if ((int) $exists->fetchColumn() === 0) {
            $output->writeLine('Project ' . $this->projectId . ' is gone or archived; nothing to do.');

            return;
        }

        $mysql     = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $statement = $this->pdo->prepare(
            'INSERT INTO example_report_runs(project_id,ran_at,open_reviews) VALUES(?,?,0)'
            . ($mysql
                ? ' ON DUPLICATE KEY UPDATE ran_at=VALUES(ran_at)'
                : ' ON CONFLICT(project_id) DO UPDATE SET ran_at=excluded.ran_at'),
        );
        $statement->execute([$this->projectId, gmdate('Y-m-d H:i:s')]);

        $output->writeLine('Recorded a review on ticket ' . ($this->ticketId ?? 0));
    }
}
