<?php

declare(strict_types=1);

namespace Example\ExtensionA\Jobs;

use Example\ExtensionA\Services\ReportService;
use Naf\CLI\Core\Output;
use Naf\Schedule\Core\ScheduledJobInterface;
use PDO;

/**
 * Counts unreviewed tickets per project, on a schedule.
 *
 * It runs without a session, so it does not pretend to be anyone: it works on
 * the projects it is given and writes only to this extension's own table.
 */
final class ReviewReminderJob implements ScheduledJobInterface
{
    public function __construct(private PDO $pdo, private ReportService $reports)
    {
    }

    public function getCronExpression(): string
    {
        return '15 6 * * *';
    }

    public function execute(Output $output): void
    {
        $projects = $this->pdo
            ->query('SELECT id FROM projects WHERE archived_at IS NULL ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);

        $mysql     = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $statement = $this->pdo->prepare(
            'INSERT INTO example_report_runs(project_id,ran_at,open_reviews) VALUES(?,?,?)'
            . ($mysql
                ? ' ON DUPLICATE KEY UPDATE ran_at=VALUES(ran_at),open_reviews=VALUES(open_reviews)'
                : ' ON CONFLICT(project_id) DO UPDATE SET ran_at=excluded.ran_at,'
                    . 'open_reviews=excluded.open_reviews'),
        );

        foreach ($projects as $project) {
            $open = count($this->reports->unreviewed((int) $project));
            $statement->execute([(int) $project, gmdate('Y-m-d H:i:s'), $open]);
            $output->writeLine('Project ' . $project . ': ' . $open . ' unreviewed');
        }
    }
}
