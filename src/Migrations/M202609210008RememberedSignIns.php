<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Sign-ins that outlive their session.
 *
 * Two columns rather than one token, because they do different jobs. The
 * selector is looked up and is therefore indexed and public; the validator is
 * compared and is therefore never stored as itself. A leak of this table is
 * then a list of names and hashes, not a key ring.
 *
 * The validator is kept as a plain SHA-256 rather than a password hash, and
 * that is deliberate rather than a shortcut: it is 256 bits of randomness this
 * application generated, not a word somebody chose, so there is nothing to slow
 * an attacker down about -- and a slow hash on every request of every remembered
 * visitor would be paid by everybody to protect nobody.
 *
 * `security_version` is copied in at issue and compared at use. The account
 * raises it whenever a password or an address changes, which is how "changing
 * your password signs you out everywhere" stays true of a cookie that was
 * issued before it.
 *
 * @internal
 */
final class M202609210008RememberedSignIns extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $id = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? 'BIGSERIAL PRIMARY KEY'
            : 'BIGINT AUTO_INCREMENT PRIMARY KEY';

        $connection->exec("CREATE TABLE remembered_sign_ins (
            id $id,
            user_id BIGINT NOT NULL,
            selector VARCHAR(32) NOT NULL UNIQUE,
            validator_hash VARCHAR(64) NOT NULL,
            security_version BIGINT NOT NULL,
            issued_at TIMESTAMP NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            last_used_at TIMESTAMP NULL,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )");
        $connection->exec(
            'CREATE INDEX remembered_sign_ins_user ON remembered_sign_ins(user_id)',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE remembered_sign_ins');
    }
}
