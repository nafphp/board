<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Let a contributed priority be stored.
 *
 * Priorities used to be four words the schema knew by heart. They are a registry
 * now, so a package can bring its own -- and the column disagreed twice: a CHECK
 * listing the original four, and a width that fit `urgent` but not the
 * namespaced `example.blocker` a package declares. Both turn a contributed
 * priority into a failed write rather than a stored value. The registry decides
 * what is accepted; the column only stores the decision.
 *
 * Status keeps its constraint. Open and closed are not a matter of taste: a
 * column marked as closing sets it and the board counts by it.
 *
 * @internal
 */
final class M202609210003OpenPriorities extends AbstractMigration
{
    /** Room for a namespaced id such as `example.blocker`, as the other key columns have. */
    private const int ROOM = 190;

    /** What the column was before: wide enough for `urgent` and nothing longer. */
    private const int NARROW = 12;

    /** The values the dropped constraint used to allow; the first is the default. */
    private const array ORIGINAL = ['normal', 'low', 'high', 'urgent'];

    public function up(PDO $connection): void
    {
        $name = $this->constraintName($connection);

        // An installation created after the constraint was dropped has nothing
        // to drop. That is a finished migration, not a failure.
        if ($name !== null) {
            $connection->exec('ALTER TABLE tickets DROP CONSTRAINT ' . $name);
        }

        $connection->exec($this->width($connection, self::ROOM));
    }

    public function down(PDO $connection): void
    {
        /*
         * A contributed priority cannot be represented once the constraint is
         * back, and would not fit the narrower column either. Putting those
         * tickets on the default says so; the alternative is an ALTER that fails
         * halfway and leaves the schema in neither state.
         */
        $placeholders = implode(',', array_fill(0, count(self::ORIGINAL), '?'));
        $connection
            ->prepare("UPDATE tickets SET priority=? WHERE priority NOT IN ($placeholders)")
            ->execute([self::ORIGINAL[0], ...self::ORIGINAL]);

        $allowed = "'" . implode("','", self::ORIGINAL) . "'";
        $connection->exec(
            "ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK(priority IN ($allowed))",
        );
        $connection->exec($this->width($connection, self::NARROW));
    }

    /** Resizing the column. */
    private function width(PDO $connection, int $characters): string
    {
        return "ALTER TABLE tickets MODIFY priority VARCHAR($characters) NOT NULL DEFAULT 'normal'";
    }

    /**
     * The generated name of the CHECK on `priority`.
     *
     * The constraint was declared inline and never named, so the server made
     * one up -- `CONSTRAINT_2` on MariaDB, `tickets_chk_1` on MySQL. Guessing
     * would drop the wrong constraint, so the name is read back instead.
     */
    private function constraintName(PDO $connection): ?string
    {
        $name = $connection->query(
            "SELECT c.CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS t
               JOIN information_schema.CHECK_CONSTRAINTS c
                 ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA
                AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME
              WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME='tickets'
                AND t.CONSTRAINT_TYPE='CHECK' AND c.CHECK_CLAUSE LIKE '%priority%'",
        )?->fetchColumn();

        if (!is_string($name)) {
            return null;
        }

        // Identifiers cannot be bound, so the name goes into the ALTER as text.
        // It comes from the catalogue rather than from input, and is quoted so a
        // generated name that needs it keeps working.
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
