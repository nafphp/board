<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

final class M202610100004InvoiceDrafts extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('CREATE TABLE invoice_connections (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_id BIGINT NOT NULL,
            provider VARCHAR(80) NOT NULL,
            company_name VARCHAR(240) NOT NULL,
            company_reference VARCHAR(240) NOT NULL,
            credentials TEXT NOT NULL,
            revoked_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX ix_invoice_owner(owner_id, revoked_at)
        )');
        $connection->exec('CREATE TABLE invoice_drafts (
            id CHAR(32) NOT NULL PRIMARY KEY,
            actor_id BIGINT NOT NULL,
            project_id BIGINT NOT NULL,
            connection_id BIGINT NOT NULL,
            owner_id BIGINT NOT NULL,
            state VARCHAR(20) NOT NULL,
            snapshot MEDIUMTEXT NOT NULL,
            remote_id VARCHAR(80) NULL,
            message VARCHAR(1000) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            attempted_at BIGINT NULL,
            INDEX ix_invoice_history(actor_id, created_at),
            FOREIGN KEY(connection_id) REFERENCES invoice_connections(id)
        )');
        // A personal submission and the company invoice are different billing purposes.
        // Within each purpose, a booking can be claimed only once, across all providers.
        $connection->exec('CREATE TABLE invoice_booking_claims (
            owner_id BIGINT NOT NULL,
            booking_id BIGINT NOT NULL,
            draft_id CHAR(32) NOT NULL,
            PRIMARY KEY(owner_id, booking_id),
            FOREIGN KEY(draft_id) REFERENCES invoice_drafts(id) ON DELETE CASCADE
        )');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE invoice_booking_claims');
        $connection->exec('DROP TABLE invoice_drafts');
        $connection->exec('DROP TABLE invoice_connections');
    }
}
