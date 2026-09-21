<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * An index on when a thing happened.
 *
 * The history has always been read newest first and paged by id, so the order
 * it is written in was the only order anybody needed and nothing indexed the
 * timestamp. Keeping a log for a stated number of days asks a different
 * question -- everything before this moment -- and without this that question
 * reads the whole table every time it is asked, which for a job that runs every
 * minute is the wrong shape of wrong.
 *
 * @internal
 */
final class M202609210009AuditRetention extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('CREATE INDEX activities_created_at ON activities(created_at)');
    }

    public function down(PDO $connection): void
    {
        $connection->exec(
            $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
                ? 'DROP INDEX activities_created_at'
                : 'DROP INDEX activities_created_at ON activities',
        );
    }
}
