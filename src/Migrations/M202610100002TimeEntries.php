<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/** Booked minutes retain their author and original ticket even after a transfer or deletion. */
final class M202610100002TimeEntries extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('CREATE TABLE ticket_time_entries (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT NOT NULL,
            ticket_id BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            ticket_key VARCHAR(100) NOT NULL,
            ticket_title VARCHAR(240) NOT NULL,
            minutes INTEGER NOT NULL CHECK (minutes > 0),
            recorded_at TIMESTAMP NOT NULL,
            FOREIGN KEY(project_id) REFERENCES projects(id),
            FOREIGN KEY(user_id) REFERENCES users(id)
        )');
        $connection->exec('CREATE INDEX ix_time_export ON ticket_time_entries(user_id, project_id, id)');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE ticket_time_entries');
    }
}
