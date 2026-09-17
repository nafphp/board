<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * One estimate per ticket, read through the scale its project has chosen. The column has
 * no scale of its own so that switching between complexity and story points keeps the
 * numbers instead of stranding them in a second, then unused, column.
 */
final class M202609170001EstimationScale extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec("ALTER TABLE projects ADD COLUMN estimation_scale VARCHAR(12) NOT NULL DEFAULT 'none'");
        $connection->exec('ALTER TABLE tickets ADD COLUMN estimate_points INTEGER NULL CHECK (estimate_points >= 0)');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE tickets DROP COLUMN estimate_points');
        $connection->exec('ALTER TABLE projects DROP COLUMN estimation_scale');
    }
}
