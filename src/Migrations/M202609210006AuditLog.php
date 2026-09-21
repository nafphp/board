<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Make the history a log of the whole installation, not only of its boards.
 *
 * Three changes, one purpose. Every entry carries the scope it happened in --
 * the same `type:id` naf/rbac uses, so "what happened on this board" and "who
 * may read it" are asked in the same language. A project is no longer required,
 * because settings, roles and accounts are changed outside every board and those
 * changes are exactly the ones worth keeping. And an actor is no longer
 * required, because an account can be deleted and its entries must not be: the
 * history says what happened, and "a deleted account" is a true answer to who.
 *
 * Rolling back restores the scope column's absence and the old foreign key, and
 * deliberately leaves both columns nullable. An entry whose actor is gone has no
 * other value to take, and deleting history in order to undo a migration is the
 * one thing this table must never do.
 *
 * @internal
 */
final class M202609210006AuditLog extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $mysql = $this->isMysql($connection);

        $connection->exec("ALTER TABLE activities ADD COLUMN scope VARCHAR(190) NOT NULL DEFAULT ''");
        // Everything recorded until now happened in a project, by construction.
        $connection->exec(
            "UPDATE activities SET scope = CONCAT('project:', project_id) WHERE project_id IS NOT NULL",
        );

        $connection->exec($mysql
            ? 'ALTER TABLE activities MODIFY project_id BIGINT NULL'
            : 'ALTER TABLE activities ALTER COLUMN project_id DROP NOT NULL');

        $name = $this->actorConstraint($connection);
        if ($name !== null) {
            $connection->exec(
                'ALTER TABLE activities DROP ' . ($mysql ? 'FOREIGN KEY ' : 'CONSTRAINT ') . $name,
            );
        }
        $connection->exec($mysql
            ? 'ALTER TABLE activities MODIFY actor_id BIGINT NULL'
            : 'ALTER TABLE activities ALTER COLUMN actor_id DROP NOT NULL');
        $connection->exec(
            'ALTER TABLE activities ADD CONSTRAINT activities_actor_fk'
            . ' FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL',
        );

        // Every read of this table now asks for one scope, newest first.
        $connection->exec('CREATE INDEX activities_scope ON activities(scope, id)');
    }

    public function down(PDO $connection): void
    {
        $mysql = $this->isMysql($connection);

        $connection->exec('DROP INDEX ' . ($mysql ? 'activities_scope ON activities' : 'activities_scope'));
        $connection->exec(
            'ALTER TABLE activities DROP '
            . ($mysql ? 'FOREIGN KEY activities_actor_fk' : 'CONSTRAINT activities_actor_fk'),
        );
        $connection->exec(
            'ALTER TABLE activities ADD CONSTRAINT activities_actor_fk'
            . ' FOREIGN KEY (actor_id) REFERENCES users(id)',
        );
        $connection->exec($mysql
            ? 'ALTER TABLE activities DROP COLUMN scope'
            : 'ALTER TABLE activities DROP COLUMN scope');
    }

    private function isMysql(PDO $connection): bool
    {
        return $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /**
     * The generated name of the foreign key on `actor_id`.
     *
     * Declared inline and never named, so each engine made one up. Guessing
     * would drop the wrong key on the other, so it is read back -- the same way
     * the priorities migration reads back the CHECK it drops.
     */
    private function actorConstraint(PDO $connection): ?string
    {
        $postgres = !$this->isMysql($connection);
        $sql      = $postgres
            ? "SELECT conname FROM pg_constraint
                 WHERE conrelid='activities'::regclass AND contype='f'
                   AND pg_get_constraintdef(oid) LIKE '%actor_id%'"
            : "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='activities'
                  AND COLUMN_NAME='actor_id' AND REFERENCED_TABLE_NAME IS NOT NULL";

        $name = $connection->query($sql)?->fetchColumn();
        if (!is_string($name)) {
            return null;
        }

        // Identifiers cannot be bound, and the two engines disagree about the
        // quote character; this one comes from the catalogue, not from input.
        $quote = $postgres ? '"' : '`';

        return $quote . str_replace($quote, $quote . $quote, $name) . $quote;
    }
}
