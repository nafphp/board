<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Export\TimeExportOptions;
use Naf\Board\Rbac\Installation;
use PDO;

use function Naf\I18n\t;

/** Authorized booked time, passed through the shared export pipeline. */
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
    public function availableProjects(bool $company = false): array
    {
        return array_values(array_filter($this->query->projects(), function (array $project) use ($company): bool {
            try {
                $this->access->project((int) $project['id'], $company ? 'export' : 'read');

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
        $groups = $this->bookings($projects, $options);

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

    /**
     * Authorized bookings for another export source to transform; personal by default.
     * All projects are checked eagerly; rows remain lazy and use the same date scope.
     * Company billing additionally requires installation settings and board export rights.
     *
     * @param list<int> $projects
     * @return array<int, iterable<array{record: array, data: array}>>
     */
    public function bookings(array $projects, TimeExportOptions $options, bool $company = false): array
    {
        $actor  = $this->access->actor();
        $groups = [];
        foreach (array_unique($projects) as $project) {
            if ($company && !\Naf\Rbac\rbac()->allows($actor, Installation::MANAGE_SETTINGS)) {
                throw new Failure(t('Diese Seite ist Administratoren vorbehalten.'), 403);
            }
            $scope            = $this->access->project($project, $company ? 'export' : 'read');
            $groups[$project] = $this->records($project, $company ? null : $actor, (string) $scope->project['name'], $options);
        }

        return $groups;
    }

    private function records(int $project, ?int $actor, string $name, TimeExportOptions $options): iterable
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
        $userFilter = $actor === null ? '' : ' AND e.user_id=?';
        $query      = $this->pdo->prepare('SELECT e.*, u.name AS user_name FROM ticket_time_entries e
            JOIN users u ON u.id=e.user_id
            WHERE e.project_id=?' . $userFilter . ' AND e.id>? ' . $where . ' ORDER BY e.id LIMIT 500');
        $after = 0;
        while (true) {
            $query->execute([$project, ...($actor === null ? [] : [$actor]), $after, ...$params]);
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
