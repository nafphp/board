<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

final class M202609150001ProjectRoles extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $mysql = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id    = $mysql ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'BIGSERIAL PRIMARY KEY';
        $connection->exec("CREATE TABLE project_roles (
            id $id,
            project_id BIGINT NOT NULL REFERENCES projects(id),
            name VARCHAR(60) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            version BIGINT NOT NULL DEFAULT 1,
            UNIQUE(project_id, id),
            UNIQUE(project_id, name)
        )");
        $connection->exec('CREATE TABLE project_role_permissions (
            project_id BIGINT NOT NULL,
            role_id BIGINT NOT NULL,
            permission VARCHAR(30) NOT NULL,
            PRIMARY KEY(project_id, role_id, permission),
            FOREIGN KEY(project_id, role_id) REFERENCES project_roles(project_id, id)
        )');
        $connection->exec('ALTER TABLE project_members ADD COLUMN custom_role_id BIGINT NULL');
        $connection->exec('ALTER TABLE project_members ADD CONSTRAINT fk_member_custom_role
            FOREIGN KEY(project_id, custom_role_id) REFERENCES project_roles(project_id, id)');
        $connection->exec("ALTER TABLE project_members ADD CONSTRAINT ck_member_custom_role
            CHECK(custom_role_id IS NULL OR role = 'viewer')");
    }

    public function down(PDO $connection): void
    {
        $mysql = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $connection->exec('ALTER TABLE project_members DROP ' . ($mysql ? 'FOREIGN KEY' : 'CONSTRAINT') . ' fk_member_custom_role');
        $connection->exec('ALTER TABLE project_members DROP CONSTRAINT ck_member_custom_role');
        $connection->exec('ALTER TABLE project_members DROP COLUMN custom_role_id');
        $connection->exec('DROP TABLE project_role_permissions');
        $connection->exec('DROP TABLE project_roles');
    }
}
