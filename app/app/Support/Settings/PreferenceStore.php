<?php

declare(strict_types=1);

namespace App\Support\Settings;

use PDO;

/**
 * The one place the existing preference rows are written.
 *
 * PreferenceService keeps its whole-form semantics and the generic settings
 * service writes single fields, but both end up here, so the two can never
 * disagree about what a stored preference means.
 */
final class PreferenceStore
{
    /** The personal columns, with the values a missing row stands for. */
    public const array USER_DEFAULTS = [
        'theme'         => 'system',
        'locale'        => 'de',
        'timezone'      => 'Europe/Berlin',
        'notify_in_app' => 1,
        'notify_mail'   => 0,
    ];

    /** The per-project columns of a membership. */
    public const array PROJECT_USER_DEFAULTS = ['muted' => 0];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param int $userId The person whose preferences are read
     *
     * @return array<string, mixed> Always complete, defaults included
     */
    public function user(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM user_preferences WHERE user_id=?');
        $statement->execute([$userId]);

        return $statement->fetch() ?: [...self::USER_DEFAULTS, 'user_id' => $userId];
    }

    /**
     * Write only the given personal columns, leaving the others as they are
     *
     * @param int                  $userId The person whose preferences change
     * @param array<string, mixed> $values Subset of the personal columns
     */
    public function writeUser(int $userId, array $values): void
    {
        $values = array_intersect_key($values, self::USER_DEFAULTS);

        if ($values === []) {
            return;
        }

        $row       = [...self::USER_DEFAULTS, ...$values];
        $columns   = array_keys(self::USER_DEFAULTS);
        $statement = 'INSERT INTO user_preferences(' . implode(',', $columns) . ',user_id) VALUES('
            . implode(',', array_fill(0, count($columns) + 1, '?')) . ')'
            . $this->onConflict('user_id', array_keys($values));

        $this->pdo->prepare($statement)->execute([
            ...array_map(static fn(string $column) => $row[$column], $columns),
            $userId,
        ]);
    }

    /**
     * @param int $projectId The project the membership belongs to
     * @param int $userId    The member
     *
     * @return array<string, mixed> Always complete, defaults included
     */
    public function projectUser(int $projectId, int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM project_preferences WHERE project_id=? AND user_id=?',
        );
        $statement->execute([$projectId, $userId]);

        return $statement->fetch() ?: [
            ...self::PROJECT_USER_DEFAULTS,
            'project_id' => $projectId,
            'user_id'    => $userId,
        ];
    }

    /**
     * @param int                  $projectId The project the membership belongs to
     * @param int                  $userId    The member
     * @param array<string, mixed> $values    Subset of the per-project columns
     */
    public function writeProjectUser(int $projectId, int $userId, array $values): void
    {
        $values = array_intersect_key($values, self::PROJECT_USER_DEFAULTS);

        if ($values === []) {
            return;
        }

        $row       = [...self::PROJECT_USER_DEFAULTS, ...$values];
        $columns   = array_keys(self::PROJECT_USER_DEFAULTS);
        $statement = 'INSERT INTO project_preferences(project_id,user_id,' . implode(',', $columns)
            . ') VALUES(' . implode(',', array_fill(0, count($columns) + 2, '?')) . ')'
            . $this->onConflict('project_id,user_id', array_keys($values));

        $this->pdo->prepare($statement)->execute([
            $projectId,
            $userId,
            ...array_map(static fn(string $column) => $row[$column], $columns),
        ]);
    }

    /**
     * The upsert tail both databases understand, updating only the named columns
     *
     * @param string       $key     Conflicting key columns
     * @param list<string> $columns Columns this write actually changes
     */
    private function onConflict(string $key, array $columns): string
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $assignments = array_map(
                static fn(string $column) => $column . '=VALUES(' . $column . ')',
                $columns,
            );

            return ' ON DUPLICATE KEY UPDATE ' . implode(',', $assignments);
        }

        $assignments = array_map(
            static fn(string $column) => $column . '=excluded.' . $column,
            $columns,
        );

        return ' ON CONFLICT(' . $key . ') DO UPDATE SET ' . implode(',', $assignments);
    }
}
