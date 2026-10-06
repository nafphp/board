<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/** Keep the chosen color palette separate from the light/dark preference. @internal */
final class M202609240001AppearancePalette extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            "ALTER TABLE user_preferences ADD COLUMN palette VARCHAR(20) NOT NULL DEFAULT 'classic'",
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE user_preferences DROP COLUMN palette');
    }
}
