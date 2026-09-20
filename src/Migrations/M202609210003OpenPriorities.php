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

    /** Resizing the column, which the two engines spell differently. */
    private function width(PDO $connection, int $characters): string
    {
        return $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? "ALTER TABLE tickets MODIFY priority VARCHAR($characters) NOT NULL DEFAULT 'normal'"
            : "ALTER TABLE tickets ALTER COLUMN priority TYPE VARCHAR($characters)";
    }

    /**
     * The generated name of the CHECK on `priority`.
     *
     * The constraint was declared inline and never named, so every engine made
     * up its own -- `CONSTRAINT_2` on MariaDB, `tickets_chk_1` on MySQL,
     * `tickets_priority_check` on Postgres. Guessing one of those would drop the
     * wrong constraint on the other two, so the name is read back instead.
     */
    private function constraintName(PDO $connection): ?string
    {
        $postgres = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $sql      = $postgres
            ? "SELECT conname FROM pg_constraint
                 WHERE conrelid='tickets'::regclass AND contype='c'
                   AND pg_get_constraintdef(oid) LIKE '%priority%'"
            : "SELECT c.CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS t
                 JOIN information_schema.CHECK_CONSTRAINTS c
                   ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA
                  AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME
                WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME='tickets'
                  AND t.CONSTRAINT_TYPE='CHECK' AND c.CHECK_CLAUSE LIKE '%priority%'";

        $name = $connection->query($sql)?->fetchColumn();

        if (!is_string($name)) {
            return null;
        }

        /*
         * Identifiers cannot be bound, so the name goes into the ALTER as text.
         * It comes from the catalogue rather than from input. Quoting keeps a
         * generated name that needs it working -- and the two engines disagree
         * about the quote: MariaDB reads "x" as a string unless ANSI_QUOTES is
         * on, which is not something a migration should assume about a host.
         */
        $quote = $postgres ? '"' : '`';

        return $quote . str_replace($quote, $quote . $quote, $name) . $quote;
    }
}
