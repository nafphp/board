<?php

declare(strict_types=1);

namespace Example\ExtensionA\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * This extension's own table.
 *
 * It keeps a note per project of when the reminder job last ran, which is the
 * kind of state a plugin owns outright. Nafinity's own tables are untouched.
 */
final class M202609180101ExampleReports extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('CREATE TABLE example_report_runs (
            project_id BIGINT NOT NULL,
            ran_at TIMESTAMP NOT NULL,
            open_reviews INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(project_id),
            FOREIGN KEY(project_id) REFERENCES projects(id)
        )');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE example_report_runs');
    }
}
