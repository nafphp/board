<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Room for a contributed estimation scale's name.
 *
 * The column was wide enough for `none`, `complexity` and `points`. A scale that
 * comes from a package carries a namespaced id such as `example.tshirt`, which
 * did not fit — and a value that does not fit is not a smaller value, it is a
 * failed write. The width now matches the other key columns.
 */
final class M202609180004ScaleIdentifiers extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? "ALTER TABLE projects MODIFY estimation_scale VARCHAR(190) NOT NULL DEFAULT 'none'"
                : 'ALTER TABLE projects ALTER COLUMN estimation_scale TYPE VARCHAR(190)',
        );
    }

    public function down(PDO $connection): void
    {
        // Rolling the widening back means the wider ids cannot be represented at
        // all. Saying so by putting those projects back on `none` is the only
        // honest option: silently truncating would leave a scale id that names
        // nothing, and refusing would leave the schema half rolled back.
        $connection->exec(
            "UPDATE projects SET estimation_scale='none' WHERE LENGTH(estimation_scale) > 12",
        );
        $connection->exec(
            $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? "ALTER TABLE projects MODIFY estimation_scale VARCHAR(12) NOT NULL DEFAULT 'none'"
                : 'ALTER TABLE projects ALTER COLUMN estimation_scale TYPE VARCHAR(12)',
        );
    }
}
