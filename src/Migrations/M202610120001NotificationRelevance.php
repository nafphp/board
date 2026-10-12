<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/** @internal */
final class M202610120001NotificationRelevance extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec("ALTER TABLE user_preferences ADD notify_scope VARCHAR(12) NOT NULL DEFAULT 'all'");
        $connection->exec('ALTER TABLE notifications ADD relevant SMALLINT NOT NULL DEFAULT 0');
        $connection->exec('CREATE TABLE ticket_subscriptions (
            project_id BIGINT NOT NULL,
            ticket_id BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            PRIMARY KEY(project_id,ticket_id,user_id),
            FOREIGN KEY(project_id,ticket_id) REFERENCES tickets(project_id,id),
            FOREIGN KEY(project_id,user_id) REFERENCES project_members(project_id,user_id)
        )');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE ticket_subscriptions');
        $connection->exec('ALTER TABLE notifications DROP COLUMN relevant');
        $connection->exec('ALTER TABLE user_preferences DROP COLUMN notify_scope');
    }
}
