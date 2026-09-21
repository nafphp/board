<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\ExporterInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Domain\ProjectScope;
use Naf\Board\Export\ExportLine;
use Naf\Board\Support\Format;
use Naf\Board\Support\Resolver;
use PDO;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\event;
use function Naf\I18n\t;

/**
 * Getting a board out of Nafinity, in whichever format was asked for.
 *
 * One service for every format, and that is the design rather than an accident
 * of there being two so far. The rows are built here and handed to a writer
 * that only turns them into text, so a third format cannot quietly grow its own
 * idea of what a ticket is -- and `export.line` fires here, once per record,
 * which is what makes it true that a listener sees every export rather than the
 * ones whose author remembered to ask.
 *
 * Written straight into a temporary stream as it goes. A board of fifty
 * thousand tickets is read in pages and never held whole, so an export costs a
 * file on disk rather than a copy of the board in memory.
 */
final class ExportService
{
    /**
     * The event a plugin listens to in order to change what an export says.
     *
     * Fired for every record of every format. Listeners receive an ExportLine
     * and may edit its `data`; the stored ticket is readonly and does not move.
     */
    public const string LINE = 'export.line';

    /** Tickets read per round trip, and metadata resolved per round trip with them. */
    private const int PAGE = 500;

    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private TicketMetadataWriter $metadata,
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
        $scope      = $this->access->project($project, 'export');
        $definition = extensions()->exporters()->get($format);

        if ($definition === null) {
            throw new Failure(t('Dieses Exportformat gibt es nicht: :format', ['format' => $format]), 404);
        }

        // build() rather than service(): a writer holds the state of one export
        // -- whether the JSON one has written a row yet decides whether the next
        // gets a comma -- and a bound instance shared between two exports would
        // start the second one mid-document. Constructor injection still works,
        // so a plugin's writer may have dependencies; it just never gets to be
        // a singleton.
        $writer  = Resolver::build(app()->container(), $definition->writer);
        $columns = $this->columns($scope);

        if (!$writer instanceof ExporterInterface) {
            throw new Failure(t('Das Exportformat :format kann nicht schreiben.', ['format' => $format]), 500);
        }

        $out = fopen('php://temp/maxmemory:' . (4 * 1024 * 1024), 'w+b');
        fwrite($out, $writer->open($columns));

        foreach ($this->pages($project) as $rows) {
            $ids   = array_map(intval(...), array_column($rows, 'id'));
            $meta  = $this->metadata->readable($scope, $project, $ids);
            $lists = $this->lists($project, $ids);

            foreach ($rows as $row) {
                $id   = (int) $row['id'];
                $line = new ExportLine(
                    $definition->id,
                    $project,
                    $row,
                    $this->data(
                        $row,
                        $meta[$id] ?? [],
                        $columns,
                        $lists['labels'][$id] ?? [],
                        $lists['assignees'][$id] ?? [],
                    ),
                );

                // The one place a plugin gets between a ticket and what is said
                // about it. After the row is built and before it is written, so
                // a listener sees the finished values and the writer sees the
                // listener's.
                event()->dispatch(self::LINE, $line);

                fwrite($out, $writer->line($line, $columns));
            }
        }

        fwrite($out, $writer->close());
        rewind($out);

        return [
            'stream'   => $out,
            'mime'     => $definition->mimeType,
            'filename' => $this->filename($scope, $definition->extension),
        ];
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
    private function pages(int $project): iterable
    {
        $statement = $this->pdo->prepare(<<<'SQL'
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
         WHERE t.project_id = ? AND t.number > ?
         ORDER BY t.number
        SQL . ' LIMIT ' . self::PAGE);

        $after = 0;
        while (true) {
            $statement->execute([$project, $after]);
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
     * Two queries per page rather than two correlated subqueries per row, and
     * the reason is portability before speed: the obvious spelling of this is
     * GROUP_CONCAT, which PostgreSQL does not have and calls string_agg. The
     * board's own query already reads them this way, so this is also the
     * spelling that stays true when those tables change.
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

    private function filename(ProjectScope $scope, string $extension): string
    {
        $slug = strtolower((string) ($scope->project['ticket_key'] ?? 'export'));

        return preg_replace('/[^a-z0-9]+/', '-', $slug) . '-' . date('Y-m-d') . '.' . $extension;
    }
}
