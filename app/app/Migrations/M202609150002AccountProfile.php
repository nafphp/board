<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

final class M202609150002AccountProfile extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('ALTER TABLE users ADD COLUMN security_version BIGINT NOT NULL DEFAULT 0');
        $connection->exec('ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL');
        $connection->exec('CREATE TABLE account_email_changes (
            user_id BIGINT PRIMARY KEY REFERENCES users(id),
            request_id VARCHAR(32) NOT NULL UNIQUE,
            email VARCHAR(190) NOT NULL,
            code_hash VARCHAR(64) NOT NULL,
            security_version BIGINT NOT NULL,
            expires_at BIGINT NOT NULL,
            attempts SMALLINT NOT NULL DEFAULT 0
        )');
        $connection->exec('CREATE INDEX account_email_expiry ON account_email_changes(expires_at)');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE account_email_changes');
        $connection->exec('ALTER TABLE users DROP COLUMN email_verified_at');
        $connection->exec('ALTER TABLE users DROP COLUMN security_version');
    }
}
