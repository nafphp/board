<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Metadata contributed to a ticket, one row per key.
 *
 * The composite foreign key keeps a value inside the project its ticket belongs
 * to, so a metadata read can never cross a project boundary by mistake. Values
 * of a plugin that is currently absent stay here untouched.
 */
final class M202609180003TicketMetadata extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('CREATE TABLE ticket_metadata (
            project_id BIGINT NOT NULL,
            ticket_id BIGINT NOT NULL,
            meta_key VARCHAR(190) NOT NULL,
            value_json TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL,
            PRIMARY KEY(project_id, ticket_id, meta_key),
            FOREIGN KEY(project_id, ticket_id) REFERENCES tickets(project_id, id)
        )');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE ticket_metadata');
    }
}
