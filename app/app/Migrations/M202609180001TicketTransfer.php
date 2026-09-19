<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;
use RuntimeException;

/**
 * Lets a ticket leave the project it was written in.
 *
 * Two changes, for two different reasons. Authorship stops pointing at a membership: who
 * created a ticket, wrote a comment or attached a file is a fact about the past, and it stays
 * true when that person leaves or was never a member of the project the ticket ends up in.
 * The key never granted anyone a reading right anyway — every request decides that against the
 * project the row is in now.
 *
 * And what belongs to a ticket now follows it. Comments, attachments, history and assignments
 * travel with the ticket's project in one update rather than being copied row by row. Rows
 * that must not simply follow — labels, links, notifications and running clocks, each of which
 * only means something in the project it was made in — deliberately keep a plain constraint,
 * so forgetting to deal with one of them fails loudly instead of arriving somewhere it does
 * not belong.
 *
 * @internal
 */
final class M202609180001TicketTransfer extends AbstractMigration
{
    /** Authorship, which outlives the membership it was recorded under. */
    private const AUTHORS = [
        ['tickets', 'created_by'],
        ['comments', 'author_id'],
        ['attachments', 'uploaded_by'],
    ];

    /** What a ticket takes with it when it moves. */
    private const FOLLOWERS = ['activities', 'attachments', 'comments', 'ticket_assignees'];

    public function up(PDO $connection): void
    {
        foreach (self::AUTHORS as [$table, $column]) {
            $this->drop($connection, $table, $this->constraint($connection, $table, $column, 'project_members'));
            $connection->exec(
                "ALTER TABLE $table ADD CONSTRAINT fk_{$table}_$column"
                . " FOREIGN KEY($column) REFERENCES users(id)",
            );
        }

        foreach (self::FOLLOWERS as $table) {
            $this->drop($connection, $table, $this->constraint($connection, $table, 'ticket_id', 'tickets'));
            $connection->exec(
                "ALTER TABLE $table ADD CONSTRAINT fk_{$table}_ticket"
                . ' FOREIGN KEY(project_id,ticket_id) REFERENCES tickets(project_id,id) ON UPDATE CASCADE',
            );
        }
    }

    public function down(PDO $connection): void
    {
        foreach (self::AUTHORS as [$table, $column]) {
            $this->remember($connection, $table, $column);
        }

        foreach (self::FOLLOWERS as $table) {
            $this->drop($connection, $table, "fk_{$table}_ticket");
            $connection->exec(
                "ALTER TABLE $table ADD CONSTRAINT fk_{$table}_ticket"
                . ' FOREIGN KEY(project_id,ticket_id) REFERENCES tickets(project_id,id)',
            );
        }

        foreach (self::AUTHORS as [$table, $column]) {
            $this->drop($connection, $table, "fk_{$table}_$column");
            $connection->exec(
                "ALTER TABLE $table ADD CONSTRAINT fk_{$table}_$column"
                . " FOREIGN KEY(project_id,$column) REFERENCES project_members(project_id,user_id)",
            );
        }
    }

    /**
     * Narrowing the keys again asserts something a moved ticket has since made untrue: that
     * whoever wrote a row is a member of the project it sits in. The one repair that invents
     * nothing is to record those people as inactive members, which is the very row the
     * application keeps when somebody is removed from a project. It grants no access — every
     * read and every write joins on an active membership.
     */
    private function remember(PDO $connection, string $table, string $column): void
    {
        $missing = $connection->query(
            <<<SQL
            SELECT DISTINCT t.project_id,
                            t.$column AS user_id
            FROM $table t
            WHERE NOT EXISTS (SELECT 1
                              FROM project_members m
                              WHERE m.project_id = t.project_id
                                  AND m.user_id = t.$column)
            SQL,
        )->fetchAll(PDO::FETCH_ASSOC);
        $insert = $connection->prepare(
            "INSERT INTO project_members(project_id,user_id,role,active) VALUES(?,?,'viewer',0)",
        );
        foreach ($missing as $row) {
            $insert->execute([$row['project_id'], $row['user_id']]);
        }
    }

    /**
     * The constraints being replaced were declared inline and so were named by the driver,
     * differently on each. Looking the name up is what lets one migration run on both.
     */
    private function constraint(PDO $connection, string $table, string $column, string $target): string
    {
        $statement = $connection->prepare(
            $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? <<<'SQL'
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?
                    AND REFERENCED_TABLE_NAME = ?
                LIMIT 1
                SQL
                : <<<'SQL'
                SELECT c.conname
                FROM pg_constraint c
                WHERE c.contype = 'f'
                    AND c.conrelid = to_regclass(?)
                    AND c.confrelid = to_regclass(?)
                    AND EXISTS (SELECT 1
                                FROM pg_attribute a
                                WHERE a.attrelid = c.conrelid
                                    AND a.attnum = ANY (c.conkey)
                                    AND a.attname = ?)
                LIMIT 1
                SQL,
        );
        // The drivers read the same three facts in a different order.
        $statement->execute(
            $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? [$table, $column, $target]
                : [$table, $target, $column],
        );
        $name = $statement->fetchColumn();
        if ($name === false) {
            throw new RuntimeException("No foreign key on $table.$column towards $target.");
        }

        return (string) $name;
    }

    /**
     * Dropping a foreign key in MySQL leaves behind the index it was given to work with, and
     * for the keys added here that index carries the key's own name. It has to go as well, or
     * adding the same key back runs into its own leftovers. Indexes that came with the
     * original schema are left alone, because nothing is ever added under those names.
     */
    private function drop(PDO $connection, string $table, string $constraint): void
    {
        $mysql = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $connection->exec(
            "ALTER TABLE $table DROP " . ($mysql ? 'FOREIGN KEY' : 'CONSTRAINT') . " $constraint",
        );
        if (!$mysql || !str_starts_with($constraint, 'fk_')) {
            return;
        }
        $statement = $connection->prepare(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND INDEX_NAME = ?
            SQL,
        );
        $statement->execute([$table, $constraint]);
        if ((int) $statement->fetchColumn() > 0) {
            $connection->exec("ALTER TABLE $table DROP INDEX $constraint");
        }
    }
}
