<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/** @internal */
final class M202610110001PasswordResetLinks extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('CREATE TABLE account_password_resets (
            user_id BIGINT PRIMARY KEY,
            requested_by BIGINT NOT NULL,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            email VARCHAR(190) NOT NULL,
            security_version BIGINT NOT NULL,
            expires_at BIGINT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id),
            FOREIGN KEY(requested_by) REFERENCES users(id)
        )');
        $connection->exec('CREATE INDEX account_password_reset_expiry ON account_password_resets(expires_at)');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE account_password_resets');
    }
}
