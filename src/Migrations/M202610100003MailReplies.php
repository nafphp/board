<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

final class M202610100003MailReplies extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        // Resolve the current project through tickets: references survive transfers.
        $connection->exec('CREATE TABLE board_mail_cases (
            ticket_id BIGINT NOT NULL PRIMARY KEY,
            reference VARCHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            recipient VARCHAR(254) NULL,
            notified_at TIMESTAMP NULL,
            FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
        )');
        $connection->exec('CREATE TABLE board_mail_receipts (
            receipt_key CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
            fingerprint CHAR(64) CHARACTER SET ascii NULL UNIQUE,
            ticket_id BIGINT NOT NULL,
            imported_at TIMESTAMP NOT NULL,
            FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
        )');
        $connection->exec('CREATE TABLE board_mail_cursors (
            mailbox_key CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
            uid_validity BIGINT NOT NULL,
            last_uid BIGINT NOT NULL DEFAULT 0
        )');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE board_mail_cursors');
        $connection->exec('DROP TABLE board_mail_receipts');
        $connection->exec('DROP TABLE board_mail_cases');
    }
}
