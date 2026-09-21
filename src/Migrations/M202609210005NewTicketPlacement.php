<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Where a new ticket lands in its column.
 *
 * At the bottom is where they have always gone, which is where a long column
 * hides them. Two settings rather than one: a board says what it does, and a
 * person says what they want for the tickets they create -- and says nothing by
 * default, which is what `inherit` is for.
 *
 * @internal
 */
final class M202609210005NewTicketPlacement extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            "ALTER TABLE projects ADD COLUMN new_tickets VARCHAR(10) NOT NULL DEFAULT 'bottom'",
        );
        $connection->exec(
            "ALTER TABLE user_preferences ADD COLUMN new_tickets VARCHAR(10) NOT NULL DEFAULT 'inherit'",
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE user_preferences DROP COLUMN new_tickets');
        $connection->exec('ALTER TABLE projects DROP COLUMN new_tickets');
    }
}
