<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Domain\ProjectScope;
use Naf\Board\Export\ExportOptions;
use Naf\Board\Support\Format;
use PDO;

use function Naf\Board\extensions;
use function Naf\I18n\t;

/** Ticket selection and readable columns, handed to the shared export renderer in pages. */
final class ExportService
{
    /** Tickets read per round trip, and metadata resolved per round trip with them. */
    private const int PAGE = 500;

    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private TicketMetadataWriter $metadata,
        private ExportRenderer $renderer = new ExportRenderer(),
    ) {
    }

    /**
     * Write one project's tickets in one format
     *
     * @param int    $project The project to export
     * @param string $format  Registered exporter id
     *
     * @return array{stream: resource, filename: string, mime: string}
     */
    public function write(int $project, string $format): array
    {
        return $this->render([$this->access->project($project, 'export')], $format, null, false);
    }

    /**
     * @param list<int> $projects Explicit scope; all projects are authorized before writing
     * @return array{stream: resource, filename: string, mime: string}
     */
    public function writeSelected(array $projects, string $format, ExportOptions $options, bool $identifyBoards = false): array
    {
        if ($projects === []) {
            throw new Failure(t('Es gibt keine Boards, die du exportieren darfst.'));
        }
        $scopes = [];
        foreach (array_unique($projects) as $project) {
            $scopes[] = $this->access->project($project, 'export');
        }

        return $this->render($scopes, $format, $options, $identifyBoards || count($scopes) > 1);
    }

    /** Only active, exportable boards belong in the installation selector. */
    public function availableProjects(array $projects): array
    {
        return array_values(array_filter($projects, function (array $project): bool {
            try {
                $this->access->project((int) $project['id'], 'export');

                return true;
            } catch (Failure $failure) {
                if (!in_array($failure->status, [403, 404], true)) {
                    throw $failure;
                }

                return false;
            }
        }));
    }

    /** @param list<ProjectScope> $scopes */
    private function render(array $scopes, string $format, ?ExportOptions $options, bool $identifyBoards): array
    {
        $columns    = $identifyBoards ? ['project_id' => t('Board-ID'), 'project' => t('Board')] : [];
        $perProject = [];
        foreach ($scopes as $scope) {
            $project              = (int) $scope->project['id'];
            $perProject[$project] = $this->selectedColumns($scope, $options);
            $columns += $perProject[$project];
        }
        $groups = [];
        foreach ($scopes as $scope) {
            $project          = (int) $scope->project['id'];
            $groups[$project] = $this->records($scope, $options, $perProject[$project], $identifyBoards);
        }

        return $this->renderer->write(
            $format,
            'tickets',
            $columns,
            $groups,
            $identifyBoards ? 'boards-' . gmdate('Y-m-d') : $this->filename($scopes[0]),
        );
    }

    private function records(ProjectScope $scope, ?ExportOptions $options, array $columns, bool $identifyBoards): iterable
    {
        $project = (int) $scope->project['id'];
        foreach ($this->pages($project, $options ?? ExportOptions::fromInput([])) as $rows) {
            $ids   = array_map(intval(...), array_column($rows, 'id'));
            $meta  = ($options?->metadata ?? true) ? $this->metadata->readable($scope, $project, $ids) : [];
            $lists = $this->lists($project, $ids);
            foreach ($rows as $row) {
                $id   = (int) $row['id'];
                $data = $this->data($row, $meta[$id] ?? [], $columns, $lists['labels'][$id] ?? [], $lists['assignees'][$id] ?? []);
                if ($identifyBoards) {
                    $data = ['project_id' => $project, 'project' => (string) $scope->project['name']] + $data;
                }
                yield ['record' => $row, 'data' => $data];
            }
        }
    }

    private function selectedColumns(ProjectScope $scope, ?ExportOptions $options): array
    {
        $columns = $this->columns($scope);
        if ($options === null) {
            return $columns;
        }
        if (!$options->metadata) {
            $columns = array_diff_key($columns, extensions()->ticketFields()->metadata());
        }
        if ($options->description) {
            $columns['description'] = t('Beschreibung');
        }
        $columns['archived_at'] = t('Archiviert am');

        return $columns;
    }

    /**
     * Every column the export has, in order, as key to heading
     *
     * The ticket's own attributes, and then one column per registered metadata
     * field -- so a plugin that added a field to tickets has it in the export
     * without having heard of the export. Read permissions are the field's own;
     * a field this reader may not see is not a column for them.
     *
     * @return array<string, string>
     */
    public function columns(ProjectScope $scope): array
    {
        $columns = [
            'key'        => t('Ticket'),
            'title'      => t('Titel'),
            'status'     => t('Status'),
            'column'     => t('Spalte'),
            'swimlane'   => t('Swimlane'),
            'priority'   => t('Priorität'),
            'labels'     => t('Labels'),
            'assignees'  => t('Verantwortliche'),
            'estimate'   => t('Schätzung'),
            'due_date'   => t('Fällig'),
            'created_at' => t('Erstellt'),
            'updated_at' => t('Geändert'),
            'closed_at'  => t('Geschlossen'),
        ];

        foreach (extensions()->ticketFields()->metadata() as $key => $field) {
            if ($scope->allows($field->readPermission)) {
                $columns[$key] = t($field->label);
            }
        }

        return $columns;
    }

    /**
     * The values of one record, before any listener sees them
     *
     * @param array                 $row       Ticket joined with its column and swimlane
     * @param array                 $meta      Readable metadata of this ticket
     * @param array<string, string> $columns   The columns to fill
     * @param list<string>          $labels    Label names of this ticket
     * @param list<string>          $assignees Names of the people it is assigned to
     *
     * @return array<string, mixed>
     */
    private function data(
        array $row,
        array $meta,
        array $columns,
        array $labels,
        array $assignees,
    ): array {
        $data = [
            'key'        => Format::ticket((string) $row['ticket_key'], (int) $row['number']),
            'title'      => (string) $row['title'],
            'status'     => (string) $row['status'],
            'column'     => (string) $row['column_name'],
            'swimlane'   => (string) $row['swimlane_name'],
            'priority'   => (string) $row['priority'],
            'labels'     => $labels,
            'assignees'  => $assignees,
            'estimate'   => $row['estimate_points'] === null ? null : (int) $row['estimate_points'],
            'due_date'   => $row['due_date'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'closed_at'  => $row['closed_at'],
        ];

        foreach ($meta as $key => $value) {
            if (array_key_exists($key, $columns)) {
                $data[$key] = $value;
            }
        }

        if (isset($columns['description'])) {
            $data['description'] = (string) $row['description'];
        }
        if (isset($columns['archived_at'])) {
            $data['archived_at'] = $row['archived_at'];
        }

        return $data;
    }

    /**
     * The tickets of a project, in pages, oldest number first
     *
     * Keyset paging on the number rather than OFFSET: an export of a busy board
     * runs while people work on it, and an offset that shifts underneath skips a
     * ticket or hands one over twice.
     *
     * The page size is written into the statement rather than bound, because a
     * placeholder in LIMIT is a string to a prepared statement and neither
     * engine accepts one there. It is a constant of this class, not a request.
     *
     * @return iterable<list<array>>
     */
    private function pages(int $project, ExportOptions $options): iterable
    {
        $where      = [];
        $parameters = [];
        if ($options->status !== 'all') {
            $where[]      = 't.status = ?';
            $parameters[] = $options->status;
        }
        if ($options->archive !== 'all') {
            $where[] = $options->archive === 'only' ? 't.archived_at IS NOT NULL' : 't.archived_at IS NULL';
        }
        if ($options->updatedFrom !== null) {
            $where[]      = 't.updated_at >= ?';
            $parameters[] = $options->updatedFrom;
        }
        if ($options->updatedBefore !== null) {
            $where[]      = 't.updated_at < ?';
            $parameters[] = $options->updatedBefore;
        }
        $filter = $where === [] ? '' : ' AND ' . implode(' AND ', $where);

        $statement = $this->pdo->prepare(<<<SQL
        SELECT t.id, t.number, t.title, t.description, t.status, t.priority,
               t.due_date, t.created_at, t.updated_at, t.closed_at, t.archived_at,
               t.estimate_points,
               p.ticket_key,
               c.name AS column_name,
               s.name AS swimlane_name
          FROM tickets t
          JOIN projects p ON p.id = t.project_id
          JOIN board_columns c
            ON c.project_id=t.project_id AND c.board_id=t.board_id AND c.id=t.column_id
          JOIN swimlanes s
            ON s.project_id=t.project_id AND s.board_id=t.board_id AND s.id=t.swimlane_id
         WHERE t.project_id = ? AND t.number > ? $filter
         ORDER BY t.number
        SQL . ' LIMIT ' . self::PAGE);

        $after = 0;
        while (true) {
            $statement->execute([$project, $after, ...$parameters]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                return;
            }

            yield $rows;

            $after = (int) $rows[array_key_last($rows)]['number'];
        }
    }

    /**
     * Labels and assignees of one page, by ticket
     *
     * Two queries per page rather than two correlated subqueries per row: the
     * board's own query reads them this way, so this is also the spelling that
     * stays true when those tables change.
     *
     * @param list<int> $ids Ticket ids of the page
     *
     * @return array{labels: array<int, list<string>>, assignees: array<int, list<string>>}
     */
    private function lists(int $project, array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $named = ['labels' => [], 'assignees' => []];

        $queries = [
            'labels' => "SELECT tl.ticket_id, l.name FROM ticket_labels tl
                 JOIN labels l ON l.project_id=tl.project_id AND l.id=tl.label_id
                WHERE tl.project_id=? AND tl.ticket_id IN ($marks) ORDER BY l.name",
            'assignees' => "SELECT ta.ticket_id, u.name FROM ticket_assignees ta
                 JOIN users u ON u.id=ta.user_id
                WHERE ta.project_id=? AND ta.ticket_id IN ($marks) ORDER BY u.name",
        ];

        foreach ($queries as $what => $sql) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$project, ...$ids]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $named[$what][(int) $row['ticket_id']][] = (string) $row['name'];
            }
        }

        return $named;
    }

    private function filename(ProjectScope $scope): string
    {
        $slug = strtolower((string) ($scope->project['ticket_key'] ?? 'export'));

        return preg_replace('/[^a-z0-9]+/', '-', $slug) . '-' . gmdate('Y-m-d');
    }
}
