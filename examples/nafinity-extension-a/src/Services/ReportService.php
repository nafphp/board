<?php

declare(strict_types=1);

namespace Example\ExtensionA\Services;

use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\BoardQueryInterface;
use Nafinity\Contracts\TicketMetadataReaderInterface;
use PDO;

/**
 * What the reports page counts.
 *
 * It asks Nafinity through its contracts rather than reaching into its tables,
 * so the numbers here are the ones the application itself would give — and an
 * application that replaces one of those services is followed automatically.
 */
final class ReportService
{
    public function __construct(
        private AccessInterface $access,
        private BoardQueryInterface $query,
        private TicketMetadataReaderInterface $metadata,
        private PDO $pdo,
    ) {
    }

    /**
     * @param int $projectId The project to report on
     * @param int $limit     How many tickets to list
     */
    public function summary(int $projectId, int $limit): array
    {
        // Reading the board authorizes the project; nothing below widens that.
        $board    = $this->query->board($projectId);
        $reviewed = 0;
        $rows     = [];

        foreach (array_slice($board['cards'], 0, $limit) as $card) {
            $values = $board['card_metadata'][(int) $card['id']] ?? [];
            $isDone = ($values['example.reviewed'] ?? false) === true;
            $reviewed += $isDone ? 1 : 0;

            $rows[] = [
                'id'          => (int) $card['id'],
                'number'      => (int) $card['number'],
                'title'       => $card['title'],
                'status'      => $card['status'],
                'reviewed'    => $isDone,
                'external_id' => $values['example.external_id'] ?? null,
            ];
        }

        return [
            'project'  => $board['project'],
            'scope'    => $board['scope'],
            'total'    => (int) $board['total'],
            'reviewed' => $reviewed,
            'rows'     => $rows,
        ];
    }

    /**
     * The tickets an extension considers unreviewed, for its reminder job
     *
     * The job has no session, so it reads the store directly after the caller
     * has decided which project it may work on.
     *
     * @param int $projectId The project the job was queued for
     *
     * @return list<int>
     */
    public function unreviewed(int $projectId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT t.id
            FROM tickets t
            LEFT JOIN ticket_metadata m
                ON m.project_id = t.project_id
                AND m.ticket_id = t.id
                AND m.meta_key = 'example.reviewed'
            WHERE t.project_id = ?
                AND t.archived_at IS NULL
                AND t.status = 'open'
                AND (m.value_json IS NULL OR m.value_json = 'false')
            ORDER BY t.id
            SQL,
        );
        $statement->execute([$projectId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function actor(): int
    {
        return $this->access->actor();
    }

    /**
     * One ticket's own review state, through the authorized reader
     *
     * @param int $projectId The project the ticket belongs to
     * @param int $ticketId  The ticket
     */
    public function reviewState(int $projectId, int $ticketId): array
    {
        return [
            'reviewed'    => (bool) $this->metadata->get($projectId, $ticketId, 'example.reviewed', false),
            'external_id' => $this->metadata->get($projectId, $ticketId, 'example.external_id'),
        ];
    }
}
