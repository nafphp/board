<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Values contributed by extensions, one row per key.
 *
 * A new field must not need a new column, so the value is JSON in a row that
 * belongs to its owner. The existing personal, per-project and project columns
 * keep their meaning; nothing is copied into a second place.
 */
final class M202609180002PluginSettings extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('CREATE TABLE user_settings (
            user_id BIGINT NOT NULL,
            setting_key VARCHAR(190) NOT NULL,
            value_json TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL,
            PRIMARY KEY(user_id, setting_key),
            FOREIGN KEY(user_id) REFERENCES users(id)
        )');
        $connection->exec('CREATE TABLE project_settings (
            project_id BIGINT NOT NULL,
            setting_key VARCHAR(190) NOT NULL,
            value_json TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL,
            PRIMARY KEY(project_id, setting_key),
            FOREIGN KEY(project_id) REFERENCES projects(id)
        )');
        $connection->exec('CREATE TABLE project_user_settings (
            project_id BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            setting_key VARCHAR(190) NOT NULL,
            value_json TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL,
            PRIMARY KEY(project_id, user_id, setting_key),
            FOREIGN KEY(project_id, user_id) REFERENCES project_members(project_id, user_id)
        )');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE project_user_settings');
        $connection->exec('DROP TABLE project_settings');
        $connection->exec('DROP TABLE user_settings');
    }
}
