<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/** @internal */
final class M202610100001ProjectInvitations extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('CREATE TABLE project_invitations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT NOT NULL,
            invited_by BIGINT NOT NULL,
            email VARCHAR(190) NOT NULL,
            role VARCHAR(30) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at BIGINT NOT NULL,
            created_at TIMESTAMP NOT NULL,
            UNIQUE(project_id,email),
            FOREIGN KEY(project_id) REFERENCES projects(id),
            FOREIGN KEY(invited_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE project_invitations');
    }
}
