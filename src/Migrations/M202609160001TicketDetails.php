<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/** @internal */
final class M202609160001TicketDetails extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('ALTER TABLE tickets ADD COLUMN description_html TEXT NULL');
        $connection->exec('ALTER TABLE tickets ADD COLUMN start_date DATE NULL');
        $connection->exec('ALTER TABLE tickets ADD COLUMN estimate_minutes INTEGER NULL CHECK (estimate_minutes >= 0)');
        $connection->exec('ALTER TABLE tickets ADD COLUMN spent_minutes INTEGER NOT NULL DEFAULT 0 CHECK (spent_minutes >= 0)');
        $connection->exec('ALTER TABLE comments ADD COLUMN parent_id BIGINT NULL');
        $connection->exec('ALTER TABLE comments ADD CONSTRAINT uq_comment_ticket UNIQUE(project_id, ticket_id, id)');
        $connection->exec('ALTER TABLE comments ADD CONSTRAINT fk_comment_parent FOREIGN KEY(project_id, ticket_id, parent_id) REFERENCES comments(project_id, ticket_id, id)');
        $connection->exec('CREATE TABLE ticket_links (
            project_id BIGINT NOT NULL,
            ticket_id BIGINT NOT NULL,
            related_id BIGINT NOT NULL,
            PRIMARY KEY(project_id, ticket_id, related_id),
            FOREIGN KEY(project_id, ticket_id) REFERENCES tickets(project_id, id),
            FOREIGN KEY(project_id, related_id) REFERENCES tickets(project_id, id),
            CHECK(ticket_id < related_id)
        )');
    }

    public function down(PDO $connection): void
    {
        $mysql = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $connection->exec('DROP TABLE ticket_links');
        $connection->exec('ALTER TABLE comments DROP ' . ($mysql ? 'FOREIGN KEY' : 'CONSTRAINT') . ' fk_comment_parent');
        $connection->exec('ALTER TABLE comments DROP COLUMN parent_id');
        $connection->exec('ALTER TABLE comments DROP ' . ($mysql ? 'INDEX' : 'CONSTRAINT') . ' uq_comment_ticket');
        foreach (['description_html', 'start_date', 'estimate_minutes', 'spent_minutes'] as $field) {
            $connection->exec('ALTER TABLE tickets DROP COLUMN ' . $field);
        }
    }
}
