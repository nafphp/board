<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;
use RuntimeException;

/** Contributed values belong to the ticket and follow it between projects. */
final class M202610100002TicketMetadataTransfer extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $this->replace($connection, ' ON UPDATE CASCADE');
    }

    public function down(PDO $connection): void
    {
        $this->replace($connection, '');
    }

    private function replace(PDO $connection, string $updateRule): void
    {
        $query = $connection->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ticket_metadata'
                AND COLUMN_NAME='ticket_id' AND REFERENCED_TABLE_NAME='tickets'");
        $name = $query->fetchColumn();
        if (!is_string($name)) {
            throw new RuntimeException('The ticket metadata foreign key was not found.');
        }

        // MariaDB cannot drop and recreate the same constraint name in one ALTER.
        $replacement = $name === 'fk_ticket_metadata_ticket' ? 'fk_ticket_metadata_ticket_replaced' : 'fk_ticket_metadata_ticket';
        $connection->exec('ALTER TABLE ticket_metadata DROP FOREIGN KEY `' . str_replace('`', '``', $name) . '`, '
            . 'ADD CONSTRAINT `' . $replacement . '` FOREIGN KEY(project_id,ticket_id) '
            . 'REFERENCES tickets(project_id,id)' . $updateRule);
    }
}
