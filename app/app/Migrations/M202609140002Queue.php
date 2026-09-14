<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use Naf\Queue\Drivers\PDODriver;
use PDO;

final class M202609140002Queue extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        (new PDODriver($connection))->install();
    }
    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE IF EXISTS naf_queue_jobs');
    }
}
