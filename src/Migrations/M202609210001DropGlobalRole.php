<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Take away the column that used to say what somebody was.
 *
 * It held one word per account and nothing read it: since naf/rbac arrived,
 * what a person may do comes from the roles they hold. A column that sometimes
 * confers power is worse than one that always does -- it is the one nobody
 * checks in review, because the answer is understood to come from somewhere
 * else. There is no second source of authority now, which is the point.
 *
 * The way back writes 'user' for everybody, because that is what the column
 * held for everybody: the one installation that had another value in it had it
 * because of this migration's absence, not because anything acted on it.
 *
 * @internal
 */
final class M202609210001DropGlobalRole extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        if ($this->hasColumn($connection, 'global_role')) {
            $connection->exec('ALTER TABLE users DROP COLUMN global_role');
        }
    }

    public function down(PDO $connection): void
    {
        if ($this->hasColumn($connection, 'global_role')) {
            return;
        }

        $connection->exec(
            "ALTER TABLE users ADD COLUMN global_role VARCHAR(20) NOT NULL DEFAULT 'user'",
        );
    }

    /** Asked rather than assumed: a fresh install never had the column to begin with. */
    private function hasColumn(PDO $connection, string $column): bool
    {
        $sql = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? 'SELECT COUNT(*) FROM information_schema.columns'
                . ' WHERE table_name = ? AND column_name = ?'
            : 'SELECT COUNT(*) FROM information_schema.columns'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?';

        $statement = $connection->prepare($sql);
        $statement->execute(['users', $column]);

        return (int) $statement->fetchColumn() > 0;
    }
}
