<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Support\ActivityDetail;
use Naf\Rbac\Scope;
use PDO;

use function Naf\Board\extensions;

/**
 * Reading the installation's history.
 *
 * Deliberately not filtered by membership, which is the whole point of it: an
 * installation-wide log that only showed the boards you belong to would answer
 * a different question than the one it is asked. The right to open it at all is
 * what limits it, and that right is its own -- see Installation::VIEW_AUDIT.
 *
 * Paged by id rather than by offset. Entries arrive while somebody reads, and an
 * offset would quietly show them a row twice and skip another.
 *
 * @internal
 */
final class AuditLog
{
    /**
     * The entries about a person rather than about their work.
     *
     * Named here because two places need the same list and must not disagree:
     * the query that leaves them out, and the filter that offers them. A type
     * added to the log without being added here is visible to everybody who may
     * read the log at all -- which is the safe direction for work and the wrong
     * one for this, so the list is short and deliberate.
     */
    public const array PERSONAL = [
        'account.password_changed',
        'account.email_requested',
        'account.email_changed',
        'account.signed_in',
        'account.sign_in_refused',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{scope?: string, actor?: int, type?: string, q?: string} $filter
     * @param bool $personal whether the reader may see what happened to accounts
     *
     * @return list<array<string,mixed>>
     */
    public function entries(
        array $filter = [],
        int $before = 0,
        int $limit = 100,
        bool $personal = false,
    ): array {
        $where  = [];
        $values = [];

        if (!$personal) {
            $where[] = 'a.event_type NOT IN ('
                . implode(',', array_fill(0, count(self::PERSONAL), '?')) . ')';
            $values = [...$values, ...self::PERSONAL];
        }

        // An empty scope is the installation itself, which is a filter and not
        // the absence of one -- so it is only applied when it was asked for.
        if (isset($filter['scope'])) {
            $where[]  = 'a.scope = ?';
            $values[] = $filter['scope'];
        }
        if (!empty($filter['actor'])) {
            $where[]  = 'a.actor_id = ?';
            $values[] = (int) $filter['actor'];
        }
        if (!empty($filter['type'])) {
            $where[]  = 'a.event_type = ?';
            $values[] = (string) $filter['type'];
        }
        if (($filter['q'] ?? '') !== '') {
            /*
             * Four places a person might remember something from: who did it,
             * which board, which ticket, and what the entry itself said.
             *
             * A scan, not an index. The table is meant to be kept in bounds by
             * the scheduled prune; until that exists this is the one query here
             * that will feel a very long history.
             */
            $like    = '%' . self::literal((string) $filter['q']) . '%';
            $columns = ['u.name', 'a.payload', 'p.name', "CONCAT(p.ticket_key, '-', t.number)"];
            $matches = array_map(
                static fn(string $column): string => $column . " LIKE ? ESCAPE '!'",
                $columns,
            );
            $terms = array_fill(0, count($columns), $like);

            /*
             * A payload holds `description`; the page shows "Beschreibung", and
             * that is the word somebody types. So the term is also asked of the
             * rendering: what would this have been stored as? The mapping is the
             * one the entries are drawn with, so the two cannot drift apart.
             */
            foreach (ActivityDetail::storedAs((string) $filter['q']) as $field) {
                $matches[] = "a.payload LIKE ? ESCAPE '!'";
                $terms[]   = '%"' . self::literal($field) . '"%';
            }

            $where[] = '(' . implode(' OR ', $matches) . ')';
            $values  = [...$values, ...$terms];
        }
        if ($before > 0) {
            $where[]  = 'a.id < ?';
            $values[] = $before;
        }

        $sql = 'SELECT a.*, u.name AS actor_name, t.number AS ticket_number,'
            . ' p.name AS project_name, p.ticket_key'
            . ' FROM activities a'
            . ' LEFT JOIN users u ON u.id = a.actor_id'
            . ' LEFT JOIN projects p ON p.id = a.project_id'
            . ' LEFT JOIN tickets t ON t.id = a.ticket_id AND t.project_id = a.project_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY a.id DESC'
            . ' LIMIT ' . max(1, min(500, $limit));

        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Where entries have actually been recorded, named.
     *
     * Read from the log rather than from the list of projects: a board that was
     * deleted still has a history, and offering a filter that matches nothing is
     * worse than not offering it.
     *
     * @return array<string,string> stored scope to what it is called
     */
    public function scopes(): array
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT a.scope, p.name FROM activities a'
            . ' LEFT JOIN projects p ON p.id = a.project_id ORDER BY a.scope',
        )->fetchAll(PDO::FETCH_ASSOC);

        $named = [];
        foreach ($rows as $row) {
            $scope         = (string) $row['scope'];
            $named[$scope] = $scope === ''
                ? 'Installation'
                : ((string) ($row['name'] ?? '') ?: self::unnamed($scope));
        }

        return $named;
    }

    /** @return array<int,string> */
    public function actors(): array
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT u.id, u.name FROM activities a'
            . ' JOIN users u ON u.id = a.actor_id ORDER BY u.name',
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, 'name', 'id');
    }

    /** @return array<string,string> */
    public function types(bool $personal = false): array
    {
        $recorded = $this->pdo
            ->query('SELECT DISTINCT event_type FROM activities ORDER BY event_type')
            ->fetchAll(PDO::FETCH_COLUMN);

        $named = [];
        foreach ($recorded as $type) {
            // Not offered as a filter to somebody who would be shown nothing.
            if (!$personal && in_array((string) $type, self::PERSONAL, true)) {
                continue;
            }
            $named[(string) $type] = extensions()->activityTypes()->get((string) $type)?->label
                ?? (string) $type;
        }

        return $named;
    }

    /**
     * A term as itself rather than as a pattern.
     *
     * Without this, searching for "100%" matches every entry there is, and an
     * underscore matches any character -- a search box that quietly means
     * something else than it says. `!` as the escape because the two engines
     * disagree about backslashes in string literals and a log should not be the
     * place that discovers it.
     */
    private static function literal(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term));
    }

    /** A board that is gone is still where something happened. */
    private static function unnamed(string $scope): string
    {
        $parsed = Scope::parse($scope);

        return $parsed->isEverywhere() ? 'Installation' : $parsed->type . ' ' . $parsed->id;
    }
}
