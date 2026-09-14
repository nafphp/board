<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use Naf\RateLimit\PdoLimiter;
use PDO;

final class M202609140003RateLimits extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        (new PdoLimiter($connection))->install();
    }
    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE IF EXISTS naf_rate_limits');
    }
}
