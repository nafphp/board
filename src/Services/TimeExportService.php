<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Export\TimeExportOptions;
use PDO;

use function Naf\I18n\t;

/** The current person's booked time, passed through the shared export pipeline. */
final class TimeExportService
{
    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private BoardQueryInterface $query,
        private ExportRenderer $renderer,
    ) {
    }

    /** Read access to the original project is required, also for historical bookings. */
    public function availableProjects(): array
    {
        return array_values(array_filter($this->query->projects(), function (array $project): bool {
            try {
                $this->access->project((int) $project['id']);

                return true;
            } catch (Failure $failure) {
                if (!in_array($failure->status, [403, 404], true)) {
                    throw $failure;
                }

                return false;
            }
        }));
    }

    /** @param list<int> $projects Explicit scope, authorized before the first row is read. */
    public function write(array $projects, string $format, TimeExportOptions $options): array
    {
        $actor  = $this->access->actor();
        $groups = [];
        foreach (array_unique($projects) as $project) {
            $scope            = $this->access->project($project);
            $groups[$project] = $this->records($project, $actor, (string) $scope->project['name'], $options);
        }

        return $this->renderer->write($format, 'time', [
            'recorded_at' => t('Gebucht am (UTC)'),
            'project'     => t('Board'),
            'key'         => t('Ticket'),
            'title'       => t('Titel'),
            'user'        => t('Person'),
            'minutes'     => t('Minuten'),
            'hours'       => t('Stunden'),
            'unit'        => t('Einheit'),
        ], $groups, 'hours-' . gmdate('Y-m-d'));
    }

    private function records(int $project, int $actor, string $name, TimeExportOptions $options): iterable
    {
        $where  = '';
        $params = [];
        if ($options->from !== null) {
            $where .= ' AND e.recorded_at >= ?';
            $params[] = $options->from;
        }
        if ($options->before !== null) {
            $where .= ' AND e.recorded_at < ?';
            $params[] = $options->before;
        }
        $query = $this->pdo->prepare('SELECT e.*, u.name AS user_name FROM ticket_time_entries e
            JOIN users u ON u.id=e.user_id
            WHERE e.project_id=? AND e.user_id=? AND e.id>? ' . $where . ' ORDER BY e.id LIMIT 500');
        $after = 0;
        while (true) {
            $query->execute([$project, $actor, $after, ...$params]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                $minutes = (int) $row['minutes'];
                yield ['record' => $row, 'data' => [
                    'recorded_at' => (string) $row['recorded_at'],
                    'project'     => $name,
                    'key'         => (string) $row['ticket_key'],
                    'title'       => (string) $row['ticket_title'],
                    'user'        => (string) $row['user_name'],
                    'minutes'     => $minutes,
                    'hours'       => round($minutes / 60, 6),
                    'unit'        => 'HUR',
                ]];
                $after = (int) $row['id'];
            }
        }
    }
}
